<?php

declare(strict_types=1);

use App\Domain\Execution;
use App\Domain\ExecutionKind;
use App\Providers\MySQLProvider;

/**
 * Testes de integração do MySQLProvider contra um MySQL de verdade.
 *
 * Sem infraestrutura, sem credencial versionada: a conexão vem das variáveis
 * de ambiente MYSQL_TEST_*, documentadas no .env.example. Se elas não
 * estiverem definidas — ou se o servidor não responder — todos os testes
 * deste arquivo são PULADOS com mensagem clara, em vez de falhar.
 *
 * Ler getenv() aqui é exceção deliberada à regra "só o Config lê env": estas
 * são variáveis do arranjo de teste, não configuração da aplicação. O bootstrap
 * do PHPUnit é o autoload do Composer, não o bootstrap.php, então o phpdotenv
 * não roda nos testes e o .env NÃO é lido — exporte as variáveis no ambiente.
 *
 * Usa uma TABELA ISOLADA ('executions_test'), criada pelo próprio provider e
 * dropada após cada teste — nunca a tabela de MYSQL_TABLE.
 */

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

/** Conexão crua para checagem de disponibilidade e limpeza. */
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
        // Descarte DELIBERADO do PDO: só queremos saber se a conexão abre.
        // Se rawPdo() um dia ganhar #[\NoDiscard], esta linha precisa de (void).
        rawPdo();
    } catch (PDOException $e) {
        $this->markTestSkipped('MySQL de teste inacessível: ' . $e->getMessage());
    }
});

afterEach(function () {
    try {
        rawPdo()->exec('DROP TABLE IF EXISTS `' . TEST_TABLE . '`');
    } catch (PDOException) {
        // Sem conexão: nada a limpar.
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
