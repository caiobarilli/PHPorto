<?php

declare(strict_types=1);

use App\Domain\Execution;
use App\Domain\ExecutionKind;
use App\Domain\WinState;
use App\Domain\WinStateScope;
use App\Providers\SQLiteProvider;

/**
 * Testes de integração do estado da /win no SQLiteProvider.
 *
 * ARQUIVO PRÓPRIO, e não anexado ao SQLiteProviderTest: o que se testa aqui é
 * a outra tabela, com outra regra de unicidade — lá duas execuções iguais são
 * dois fatos, aqui é uma linha por (escopo, ação) que se substitui. Juntar os
 * dois num arquivo só faria o leitor procurar qual `it` fala de qual tabela.
 *
 * É A ÚNICA COBERTURA REAL dos três drivers: o SQLite é o padrão e não depende
 * de serviço externo. Os mesmos casos existem para o MySQL e são pulados sem
 * MYSQL_TEST_*; o Mongo não tem teste nenhum no projeto.
 */
beforeEach(function () {
    $this->dbPath = tempnam(sys_get_temp_dir(), 'phporto_state_') ?: '';
});

afterEach(function () {
    if (is_string($this->dbPath) && $this->dbPath !== '' && file_exists($this->dbPath)) {
        @unlink($this->dbPath);
    }
});

/**
 * Nome próprio para não colidir com o sqliteTestConfig() do outro arquivo: o
 * Pest carrega tudo no mesmo processo, e função global repetida é erro fatal.
 *
 * @return array{path: string, table: string}
 */
function estadoTestConfig(string $path, string $table = 'executions'): array
{
    return ['path' => $path, 'table' => $table];
}

it('a tabela de estado nasce no acesso, e banco novo lê estado vazio', function () {
    // Sem migration de propósito: é o mesmo CREATE TABLE IF NOT EXISTS da
    // tabela de execuções, e o projeto não tem versionador de schema. Quem já
    // tem banco ganha a tabela na próxima página que abrir.
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
    // Inverso exato do teste das duas execuções idênticas no outro arquivo.
    // Lá repetir são dois fatos; aqui é uma linha por par, e gravar é
    // substituir.
    $p = new SQLiteProvider(estadoTestConfig($this->dbPath));

    $p->putWinState(new WinState(WinStateScope::Applied, 'tweaks', ['Preset' => 'standard']));
    $p->putWinState(new WinState(WinStateScope::Applied, 'tweaks', ['Preset' => 'advanced']));

    $estados = $p->winStates(WinStateScope::Applied);

    expect($estados)->toHaveCount(1)
        ->and($estados['tweaks']->payload)->toBe(['Preset' => 'advanced']);
});

it('a MESMA AÇÃO em escopos diferentes são duas linhas independentes', function () {
    // A chave é composta, e é ESTE caso que obrigou o escopo a ser
    // obrigatório no winStates(): as duas linhas têm action 'tweaks', então
    // uma leitura sem filtro indexada por ação fazia uma sobrescrever a outra
    // e devolvia uma onde havia duas. O teste falhou de verdade antes de a
    // assinatura mudar.
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
    // O teste que mais importa nesta fatia. Um botão rotulado "limpar
    // histórico" que mudasse o que a tela afirma sobre a máquina passaria a
    // oferecer "Aplicar" no que continua aplicado — e é justamente por isso
    // que o estado não se deriva do histórico.
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
    // '[]' e '{}' voltam diferentes do json_decode, e a ausência de parâmetros
    // tem de voltar como mapa. Mesmo cuidado que o JobChannel toma com
    // "params":{} do outro lado.
    $p = new SQLiteProvider(estadoTestConfig($this->dbPath));

    $p->putWinState(new WinState(WinStateScope::Applied, 'gdid'));

    expect($p->winStates(WinStateScope::Applied)['gdid']->payload)->toBe([]);
});

it('LINHA ILEGÍVEL É DESCARTADA, e não derruba a leitura', function (string $scope, string $payload) {
    // O lado seguro de não saber o que a linha afirma é não afirmar nada — a
    // mesma regra do flags.json, onde JSON quebrado vira "pergunte ao .env".
    $p = new SQLiteProvider(estadoTestConfig($this->dbPath));
    $p->putWinState(new WinState(WinStateScope::Applied, 'tweaks', ['Preset' => 'standard']));

    // Estraga a linha por fora, como faria um banco editado à mão ou gravado
    // por uma versão que guardasse outra coisa.
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
    // Nulo e aninhado não vêm desta ferramenta — a allowlist só produz string,
    // inteiro e booleano. Repassar o que não se espera faria o chamador tratar
    // um tipo que ele não declara.
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
    // Derivada com sufixo fixo, e não uma chave nova no .env: quem renomeou a
    // tabela de execuções ganha esta renomeada junto, sem uma segunda variável
    // para manter em dia.
    $p = new SQLiteProvider(estadoTestConfig($this->dbPath, 'outro_nome'));
    $p->putWinState(new WinState(WinStateScope::Applied, 'gdid'));

    $pdo   = new PDO('sqlite:' . $this->dbPath);
    $query = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name");

    $tabelas = $query === false ? [] : $query->fetchAll(PDO::FETCH_COLUMN);

    expect($tabelas)->toContain('outro_nome')
        ->and($tabelas)->toContain('outro_nome_win_state');
});
