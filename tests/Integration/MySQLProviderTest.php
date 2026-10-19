<?php

declare(strict_types=1);

use App\Domain\Execution;
use App\Domain\ExecutionKind;
use App\Domain\WinState;
use App\Domain\WinStateScope;
use App\Providers\MySQLProvider;

const TEST_TABLE = 'executions_test';

function mysqlTestEnv(string $key, string $default = ''): string
{
    $value = $_ENV[$key] ?? getenv($key);

    return is_string($value) && $value !== '' ? $value : $default;
}

/**
 * @return array{host: string, port: string, database: string, user: string, password: string, table: string}
 */
function mysqlTestConfig(): array
{
    return [
        'host'     => mysqlTestEnv('MYSQL_TEST_HOST', '127.0.0.1'),
        'port'     => mysqlTestEnv('MYSQL_TEST_PORT', '3306'),
        'database' => mysqlTestEnv('MYSQL_TEST_DATABASE'),
        'user'     => mysqlTestEnv('MYSQL_TEST_USER'),
        'password' => mysqlTestEnv('MYSQL_TEST_PASSWORD'),
        'table'    => TEST_TABLE,
    ];
}

function rawPdo(): PDO
{
    $c = mysqlTestConfig();

    return new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s', $c['host'], $c['port'], $c['database']),
        $c['user'],
        $c['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 2]
    );
}

function novaExecucaoMysql(
    string $command = 'ls',
    string $output = '',
    ?int $exitCode = 0,
    int $durationMs = 1,
    ExecutionKind $kind = ExecutionKind::Comando,
    bool $timedOut = false,
): Execution {
    return new Execution($command, $output, $exitCode, $durationMs, $kind, $timedOut);
}

beforeEach(function () {
    $c = mysqlTestConfig();

    if ($c['database'] === '' || $c['user'] === '') {
        $this->markTestSkipped(
            'MySQL de teste não configurado — exporte MYSQL_TEST_DATABASE e '
            . 'MYSQL_TEST_USER (ver .env.example).'
        );
    }

    try {
        rawPdo();
    } catch (PDOException $e) {
        $this->markTestSkipped('MySQL de teste inacessível: ' . $e->getMessage());
    }
});

afterEach(function () {
    try {
        $pdo = rawPdo();
        $pdo->exec('DROP TABLE IF EXISTS `' . TEST_TABLE . '`');
        $pdo->exec('DROP TABLE IF EXISTS `' . TEST_TABLE . '_win_state`');
    } catch (PDOException) {
    }
});

it('cria a tabela e grava uma execução', function () {
    $p = new MySQLProvider(mysqlTestConfig());

    $p->insert(novaExecucaoMysql('git status', "ok\n"));

    expect($p->recent())->toHaveCount(1);
});

it('duas execuções idênticas geram dois registros', function () {
    $p = new MySQLProvider(mysqlTestConfig());

    $p->insert(novaExecucaoMysql('git status'));
    $p->insert(novaExecucaoMysql('git status'));

    expect($p->recent())->toHaveCount(2);
});

it('devolve de volta exatamente o que foi gravado', function () {
    $p     = new MySQLProvider(mysqlTestConfig());
    $saida = "linha 1\nlinha 2 com acento: ção\n";
    $p->insert(novaExecucaoMysql('echo "olá"', $saida, 3, 4567, ExecutionKind::Anexo, true));

    $lido = $p->recent()[0];

    expect($lido->command)->toBe('echo "olá"')
        ->and($lido->output)->toBe($saida)
        ->and($lido->exitCode)->toBe(3)
        ->and($lido->durationMs)->toBe(4567)
        ->and($lido->kind)->toBe(ExecutionKind::Anexo)
        ->and($lido->timedOut)->toBeTrue()
        ->and($lido->createdAt)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/');
});

it('preserva exit code nulo como nulo', function () {
    $p = new MySQLProvider(mysqlTestConfig());
    $p->insert(novaExecucaoMysql('travou', '', null, 1, ExecutionKind::Comando, true));

    expect($p->recent()[0]->exitCode)->toBeNull();
});

it('recent() devolve o mais novo primeiro e respeita o limite', function () {
    $p = new MySQLProvider(mysqlTestConfig());
    foreach (['a', 'b', 'c'] as $c) {
        $p->insert(novaExecucaoMysql($c));
    }

    expect(array_map(static fn (Execution $e): string => $e->command, $p->recent()))
        ->toBe(['c', 'b', 'a'])
        ->and($p->recent(2))->toHaveCount(2);
});

it('clear() esvazia e devolve quantos foram apagados', function () {
    $p = new MySQLProvider(mysqlTestConfig());
    $p->insert(novaExecucaoMysql('a'));
    $p->insert(novaExecucaoMysql('b'));

    expect($p->clear())->toBe(2)
        ->and($p->recent())->toBe([]);
});

it('recent() sem filtro devolve todos os tipos, e filtrado devolve só o pedido', function () {
    $p = new MySQLProvider(mysqlTestConfig());
    $p->insert(novaExecucaoMysql('um comando', '', 0, 1, ExecutionKind::Comando));
    $p->insert(novaExecucaoMysql('um anexo', '', 0, 1, ExecutionKind::Anexo));
    $p->insert(novaExecucaoMysql('uma acao do windows', '', 0, 1, ExecutionKind::Windows));

    expect($p->recent())->toHaveCount(3)
        ->and($p->recent(100, [ExecutionKind::Windows]))->toHaveCount(1)
        ->and($p->recent(100, [ExecutionKind::Windows])[0]->command)->toBe('uma acao do windows');
});

it('recent() filtra antes de aplicar o limite', function () {
    $p = new MySQLProvider(mysqlTestConfig());
    $p->insert(novaExecucaoMysql('uma acao do windows', '', 0, 1, ExecutionKind::Windows));
    foreach (range(1, 5) as $i) {
        $p->insert(novaExecucaoMysql('comando ' . $i));
    }

    expect($p->recent(1, [ExecutionKind::Windows]))->toHaveCount(1)
        ->and($p->recent(1, [ExecutionKind::Windows])[0]->command)->toBe('uma acao do windows');
});

it('clear() de um tipo não leva o outro junto', function () {
    $p = new MySQLProvider(mysqlTestConfig());
    $p->insert(novaExecucaoMysql('um comando', '', 0, 1, ExecutionKind::Comando));
    $p->insert(novaExecucaoMysql('uma acao do windows', '', 0, 1, ExecutionKind::Windows));

    expect($p->clear([ExecutionKind::Windows]))->toBe(1)
        ->and($p->recent())->toHaveCount(1)
        ->and($p->recent()[0]->kind)->toBe(ExecutionKind::Comando);
});

it('lista de tipos filtra e apaga só os da lista, e lista vazia é nenhum', function () {
    $p = new MySQLProvider(mysqlTestConfig());
    $p->insert(novaExecucaoMysql('um comando', '', 0, 1, ExecutionKind::Comando));
    $p->insert(novaExecucaoMysql('um anexo', '', 0, 1, ExecutionKind::Anexo));
    $p->insert(novaExecucaoMysql('uma acao do windows', '', 0, 1, ExecutionKind::Windows));

    expect($p->recent(100, ExecutionKind::WSL))->toHaveCount(2)
        ->and($p->recent(100, []))->toBe([])
        ->and($p->clear([]))->toBe(0)
        ->and($p->clear(ExecutionKind::WSL))->toBe(2)
        ->and($p->recent()[0]->kind)->toBe(ExecutionKind::Windows);
});

it('grava o estado aplicado e devolve com o updated_at do banco', function () {
    $p = new MySQLProvider(mysqlTestConfig());

    $gravado = $p->putWinState(new WinState(WinStateScope::Applied, 'tweaks', ['Preset' => 'standard']));

    expect($gravado->updatedAt)->not->toBeNull()
        ->and($gravado->payload)->toBe(['Preset' => 'standard']);
});

it('o ON DUPLICATE KEY substitui em vez de duplicar', function () {
    $p = new MySQLProvider(mysqlTestConfig());

    $p->putWinState(new WinState(WinStateScope::Applied, 'tweaks', ['Preset' => 'standard']));
    $p->putWinState(new WinState(WinStateScope::Applied, 'tweaks', ['Preset' => 'advanced']));

    $estados = $p->winStates(WinStateScope::Applied);

    expect($estados)->toHaveCount(1)
        ->and($estados['tweaks']->payload)->toBe(['Preset' => 'advanced']);
});

it('a mesma ação em escopos diferentes são duas linhas independentes', function () {
    $p = new MySQLProvider(mysqlTestConfig());

    $p->putWinState(new WinState(WinStateScope::Applied, 'tweaks', ['Preset' => 'standard']));
    $p->putWinState(new WinState(WinStateScope::Selection, 'tweaks', ['itens' => 'a,b']));

    expect($p->winStates(WinStateScope::Applied))->toHaveCount(1)
        ->and($p->winStates(WinStateScope::Selection))->toHaveCount(1);
});

it('esquecer devolve 1 e depois 0', function () {
    $p = new MySQLProvider(mysqlTestConfig());

    $p->putWinState(new WinState(WinStateScope::Applied, 'gdid'));

    expect($p->forgetWinState(WinStateScope::Applied, 'gdid'))->toBe(1)
        ->and($p->forgetWinState(WinStateScope::Applied, 'gdid'))->toBe(0);
});

it('limpar o histórico do Windows NÃO mexe no estado', function () {
    $p = new MySQLProvider(mysqlTestConfig());

    $p->insert(novaExecucaoMysql('tweaks -Preset standard', kind: ExecutionKind::Windows));
    $p->putWinState(new WinState(WinStateScope::Applied, 'tweaks', ['Preset' => 'standard']));

    expect($p->clear([ExecutionKind::Windows]))->toBe(1)
        ->and($p->winStates(WinStateScope::Applied))->toHaveCount(1);
});

it('payload vazio volta como mapa vazio', function () {
    $p = new MySQLProvider(mysqlTestConfig());

    $p->putWinState(new WinState(WinStateScope::Applied, 'gdid'));

    expect($p->winStates(WinStateScope::Applied)['gdid']->payload)->toBe([]);
});
