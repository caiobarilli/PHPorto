<?php

declare(strict_types=1);

use App\Domain\Execution;
use App\Domain\ExecutionKind;
use App\Domain\WinState;
use App\Domain\WinStateScope;
use App\Providers\SQLiteProvider;

beforeEach(function () {
    $this->dbPath = tempnam(sys_get_temp_dir(), 'phporto_state_') ?: '';
});

afterEach(function () {
    if (is_string($this->dbPath) && $this->dbPath !== '' && file_exists($this->dbPath)) {
        @unlink($this->dbPath);
    }
});

/**
 * @return array{path: string, table: string}
 */
function estadoTestConfig(string $path, string $table = 'executions'): array
{
    return ['path' => $path, 'table' => $table];
}

it('a tabela de estado nasce no acesso, e banco novo lê estado vazio', function () {
    $p = new SQLiteProvider(estadoTestConfig($this->dbPath));

    expect($p->winStates(WinStateScope::Applied))->toBe([]);
});

it('grava o estado aplicado e devolve com o updated_at do banco', function () {
    $p = new SQLiteProvider(estadoTestConfig($this->dbPath));

    $gravado = $p->putWinState(new WinState(WinStateScope::Applied, 'tweaks', ['Preset' => 'standard']));

    expect($gravado->updatedAt)->not->toBeNull()
        ->and($gravado->payload)->toBe(['Preset' => 'standard']);
});

it('É UPSERT: gravar duas vezes substitui em vez de duplicar', function () {
    $p = new SQLiteProvider(estadoTestConfig($this->dbPath));

    $p->putWinState(new WinState(WinStateScope::Applied, 'tweaks', ['Preset' => 'standard']));
    $p->putWinState(new WinState(WinStateScope::Applied, 'tweaks', ['Preset' => 'advanced']));

    $estados = $p->winStates(WinStateScope::Applied);

    expect($estados)->toHaveCount(1)
        ->and($estados['tweaks']->payload)->toBe(['Preset' => 'advanced']);
});

it('a MESMA AÇÃO em escopos diferentes são duas linhas independentes', function () {
    $p = new SQLiteProvider(estadoTestConfig($this->dbPath));

    $p->putWinState(new WinState(WinStateScope::Applied, 'tweaks', ['Preset' => 'standard']));
    $p->putWinState(new WinState(WinStateScope::Selection, 'tweaks', ['itens' => 'a,b']));

    expect($p->winStates(WinStateScope::Applied))->toHaveCount(1)
        ->and($p->winStates(WinStateScope::Selection))->toHaveCount(1)
        ->and($p->winStates(WinStateScope::Applied)['tweaks']->payload)->toBe(['Preset' => 'standard'])
        ->and($p->winStates(WinStateScope::Selection)['tweaks']->payload)->toBe(['itens' => 'a,b']);
});

it('o filtro por escopo está na CONSULTA: não sobra linha do outro escopo', function () {
    $p = new SQLiteProvider(estadoTestConfig($this->dbPath));

    $p->putWinState(new WinState(WinStateScope::Selection, 'debloat', ['itens' => 'x']));
    $p->putWinState(new WinState(WinStateScope::Selection, 'dns', ['itens' => 'y']));
    $p->putWinState(new WinState(WinStateScope::Applied, 'gdid'));

    $aplicado = $p->winStates(WinStateScope::Applied);

    expect(array_keys($aplicado))->toBe(['gdid'])
        ->and($aplicado['gdid']->payload)->toBe([]);
});

it('os três escopos da mesma ação são três linhas, e a leitura de cada um traz só o seu', function () {
    $p = new SQLiteProvider(estadoTestConfig($this->dbPath));

    $p->putWinState(new WinState(WinStateScope::Applied, 'rdp', ['On' => 'true']));
    $p->putWinState(new WinState(WinStateScope::Selection, 'rdp', ['Provider' => 'x']));
    $p->putWinState(new WinState(WinStateScope::PendingReboot, 'rdp', ['H264' => 'true']));

    expect(array_keys($p->winStates(WinStateScope::Applied)))->toBe(['rdp'])
        ->and(array_keys($p->winStates(WinStateScope::Selection)))->toBe(['rdp'])
        ->and(array_keys($p->winStates(WinStateScope::PendingReboot)))->toBe(['rdp'])
        ->and($p->winStates(WinStateScope::Applied)['rdp']->payload)->toBe(['On' => 'true'])
        ->and($p->winStates(WinStateScope::PendingReboot)['rdp']->payload)->toBe(['H264' => 'true']);
});

it('esquecer o pendente-de-reinicio não leva o aplicado da mesma ação, nem o contrário', function () {
    $p = new SQLiteProvider(estadoTestConfig($this->dbPath));

    $p->putWinState(new WinState(WinStateScope::Applied, 'rdp', ['On' => 'true']));
    $p->putWinState(new WinState(WinStateScope::PendingReboot, 'rdp', ['H264' => 'true']));

    $p->forgetWinState(WinStateScope::PendingReboot, 'rdp');

    expect($p->winStates(WinStateScope::PendingReboot))->toBe([])
        ->and($p->winStates(WinStateScope::Applied))->toHaveCount(1);
});

it('o array volta indexado pela AÇÃO, para a tela perguntar por nome', function () {
    $p = new SQLiteProvider(estadoTestConfig($this->dbPath));

    $p->putWinState(new WinState(WinStateScope::Applied, 'optimize', ['Preset' => 'ssh']));

    expect($p->winStates(WinStateScope::Applied))->toHaveKey('optimize');
});

it('esquecer devolve 1 e depois 0: ausência é o mesmo que não aplicado', function () {
    $p = new SQLiteProvider(estadoTestConfig($this->dbPath));

    $p->putWinState(new WinState(WinStateScope::Applied, 'gdid'));

    expect($p->forgetWinState(WinStateScope::Applied, 'gdid'))->toBe(1)
        ->and($p->forgetWinState(WinStateScope::Applied, 'gdid'))->toBe(0)
        ->and($p->winStates(WinStateScope::Applied))->toBe([]);
});

it('esquecer um escopo NÃO leva o outro', function () {
    $p = new SQLiteProvider(estadoTestConfig($this->dbPath));

    $p->putWinState(new WinState(WinStateScope::Applied, 'tweaks', ['Preset' => 'standard']));
    $p->putWinState(new WinState(WinStateScope::Selection, 'tweaks', ['itens' => 'a']));

    $p->forgetWinState(WinStateScope::Applied, 'tweaks');

    expect($p->winStates(WinStateScope::Selection))->toHaveCount(1)
        ->and($p->winStates(WinStateScope::Applied))->toBe([]);
});

it('LIMPAR O HISTÓRICO DO WINDOWS NÃO MEXE NO ESTADO', function () {
    $p = new SQLiteProvider(estadoTestConfig($this->dbPath));

    $p->insert(new Execution(
        'tweaks -Preset standard',
        'aplicado',
        0,
        8000,
        ExecutionKind::Windows,
        false
    ));
    $p->putWinState(new WinState(WinStateScope::Applied, 'tweaks', ['Preset' => 'standard']));

    expect($p->clear([ExecutionKind::Windows]))->toBe(1)
        ->and($p->recent(100, [ExecutionKind::Windows]))->toBe([])
        ->and($p->winStates(WinStateScope::Applied))->toHaveCount(1);
});

it('limpar TUDO também não mexe no estado', function () {
    $p = new SQLiteProvider(estadoTestConfig($this->dbPath));

    $p->insert(new Execution('git status', '', 0, 10, ExecutionKind::Comando, false));
    $p->insert(new Execution('processes', '', 0, 10, ExecutionKind::Windows, false));
    $p->putWinState(new WinState(WinStateScope::Applied, 'performance'));

    expect($p->clear())->toBe(2)
        ->and($p->winStates(WinStateScope::Applied))->toHaveCount(1);
});

it('payload vazio volta como mapa vazio, não como lista', function () {
    $p = new SQLiteProvider(estadoTestConfig($this->dbPath));

    $p->putWinState(new WinState(WinStateScope::Applied, 'gdid'));

    expect($p->winStates(WinStateScope::Applied)['gdid']->payload)->toBe([]);
});

it('LINHA ILEGÍVEL É DESCARTADA, e não derruba a leitura', function (string $scope, string $payload) {
    $p = new SQLiteProvider(estadoTestConfig($this->dbPath));
    $p->putWinState(new WinState(WinStateScope::Applied, 'tweaks', ['Preset' => 'standard']));

    $pdo = new PDO('sqlite:' . $this->dbPath);
    $pdo->exec(sprintf(
        'UPDATE executions_win_state SET scope = %s, payload = %s',
        $pdo->quote($scope),
        $pdo->quote($payload)
    ));

    expect($p->winStates(WinStateScope::Applied))->toBe([]);
})->with([
    'escopo desconhecido'     => ['inventado', '{"Preset":"standard"}'],
    'payload que é lista'     => ['aplicado', '["standard"]'],
    'payload que não é JSON'  => ['aplicado', 'standard'],
    'payload vazio de string' => ['aplicado', ''],
]);

it('payload com tipo estranho perde só a chave estranha', function () {
    $p = new SQLiteProvider(estadoTestConfig($this->dbPath));
    $p->putWinState(new WinState(WinStateScope::Applied, 'tweaks', ['Preset' => 'standard']));

    $pdo = new PDO('sqlite:' . $this->dbPath);
    $pdo->exec(sprintf(
        'UPDATE executions_win_state SET payload = %s',
        $pdo->quote('{"Preset":"standard","Lixo":null,"Fundo":{"a":1}}')
    ));

    expect($p->winStates(WinStateScope::Applied)['tweaks']->payload)->toBe(['Preset' => 'standard']);
});

it('a tabela de estado segue o nome da tabela de execuções', function () {
    $p = new SQLiteProvider(estadoTestConfig($this->dbPath, 'outro_nome'));
    $p->putWinState(new WinState(WinStateScope::Applied, 'gdid'));

    $pdo   = new PDO('sqlite:' . $this->dbPath);
    $query = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name");

    $tabelas = $query === false ? [] : $query->fetchAll(PDO::FETCH_COLUMN);

    expect($tabelas)->toContain('outro_nome')
        ->and($tabelas)->toContain('outro_nome_win_state');
});
