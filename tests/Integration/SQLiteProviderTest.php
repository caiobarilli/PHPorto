<?php

declare(strict_types=1);

use App\Domain\Execution;
use App\Domain\ExecutionKind;
use App\Exceptions\StorageException;
use App\Providers\SQLiteProvider;

/**
 * Testes de integração do SQLiteProvider.
 *
 * Sem skip condicional: SQLite não depende de serviço externo. Cada teste usa
 * um arquivo .sqlite temporário isolado, apagado no afterEach.
 */

beforeEach(function () {
    $this->dbPath = tempnam(sys_get_temp_dir(), 'phporto_test_') ?: '';
});

afterEach(function () {
    if (is_string($this->dbPath) && $this->dbPath !== '' && file_exists($this->dbPath)) {
        @unlink($this->dbPath);
    }
});

/** @return array{path: string, table: string} */
function sqliteTestConfig(string $path): array
{
    return ['path' => $path, 'table' => 'executions'];
}

function novaExecucao(
    string $command = 'ls -la',
    string $output = "total 0\n",
    ?int $exitCode = 0,
    int $durationMs = 12,
    ExecutionKind $kind = ExecutionKind::Comando,
    bool $timedOut = false,
): Execution {
    return new Execution($command, $output, $exitCode, $durationMs, $kind, $timedOut);
}

it('cria a tabela e grava uma execução', function () {
    $p = new SQLiteProvider(sqliteTestConfig($this->dbPath));

    $p->insert(novaExecucao('git status'));

    expect($p->recent())->toHaveCount(1);
});

it('DUAS EXECUÇÕES IDÊNTICAS GERAM DOIS REGISTROS', function () {
    // Este teste é o inverso exato do teste de duplicidade que existia antes.
    // Ele está aqui para derrubar quem reintroduzir um índice UNIQUE.
    $p = new SQLiteProvider(sqliteTestConfig($this->dbPath));

    $p->insert(novaExecucao('git status'));
    $p->insert(novaExecucao('git status'));

    expect($p->recent())->toHaveCount(2);
});

it('devolve de volta exatamente o que foi gravado', function () {
    $p      = new SQLiteProvider(sqliteTestConfig($this->dbPath));
    $saida  = "linha 1\nlinha 2 com acento: ção\nlinha 3 com aspas: \"x\" e \$cifrao\n";
    $p->insert(novaExecucao('echo "olá"', $saida, 3, 4567, ExecutionKind::Anexo, true));

    $lido = $p->recent()[0];

    expect($lido->command)->toBe('echo "olá"')
        ->and($lido->output)->toBe($saida)
        ->and($lido->exitCode)->toBe(3)
        ->and($lido->durationMs)->toBe(4567)
        ->and($lido->kind)->toBe(ExecutionKind::Anexo)
        ->and($lido->timedOut)->toBeTrue()
        ->and($lido->createdAt)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/');
});

it('preserva exit code nulo como nulo, e não como zero', function () {
    $p = new SQLiteProvider(sqliteTestConfig($this->dbPath));
    $p->insert(novaExecucao('travou', '', null));

    expect($p->recent()[0]->exitCode)->toBeNull();
});

it('preserva exit code zero como zero, e não como nulo', function () {
    $p = new SQLiteProvider(sqliteTestConfig($this->dbPath));
    $p->insert(novaExecucao('ok', '', 0));

    expect($p->recent()[0]->exitCode)->toBe(0);
});

it('preserva timedOut falso como falso', function () {
    $p = new SQLiteProvider(sqliteTestConfig($this->dbPath));
    $p->insert(novaExecucao(timedOut: false));

    expect($p->recent()[0]->timedOut)->toBeFalse();
});

it('preserva saída vazia como string vazia', function () {
    $p = new SQLiteProvider(sqliteTestConfig($this->dbPath));
    $p->insert(novaExecucao('true', ''));

    expect($p->recent()[0]->output)->toBe('');
});

it('recent() devolve o mais novo primeiro, mesmo dentro do mesmo segundo', function () {
    $p = new SQLiteProvider(sqliteTestConfig($this->dbPath));
    $p->insert(novaExecucao('primeiro'));
    $p->insert(novaExecucao('segundo'));
    $p->insert(novaExecucao('terceiro'));

    expect(array_map(static fn ($e) => $e->command, $p->recent()))
        ->toBe(['terceiro', 'segundo', 'primeiro']);
});

it('recent() respeita o limite', function () {
    $p = new SQLiteProvider(sqliteTestConfig($this->dbPath));
    foreach (['a', 'b', 'c'] as $c) {
        $p->insert(novaExecucao($c));
    }

    expect($p->recent(2))->toHaveCount(2)
        ->and($p->recent(2)[0]->command)->toBe('c');
});

it('clear() esvazia e devolve quantos foram apagados', function () {
    $p = new SQLiteProvider(sqliteTestConfig($this->dbPath));
    $p->insert(novaExecucao('a'));
    $p->insert(novaExecucao('b'));

    expect($p->clear())->toBe(2)
        ->and($p->recent())->toBe([]);
});

it('clear() em tabela vazia devolve zero', function () {
    $p = new SQLiteProvider(sqliteTestConfig($this->dbPath));

    expect($p->clear())->toBe(0);
});

it('guarda saída grande sem truncar', function () {
    $p     = new SQLiteProvider(sqliteTestConfig($this->dbPath));
    $saida = str_repeat("linha de saída bem comprida para encher o campo\n", 5000);
    $p->insert(novaExecucao('yes', $saida));

    expect($p->recent()[0]->output)->toBe($saida);
});

it('rejeita nome de tabela inválido (sanitizeIdentifier)', function () {
    new SQLiteProvider(['path' => $this->dbPath, 'table' => 'invalid; DROP TABLE x']);
})->throws(StorageException::class);

it('insert() devolve o registro com o created_at que o banco atribuiu', function () {
    $p = new SQLiteProvider(sqliteTestConfig($this->dbPath));

    $gravado = $p->insert(novaExecucao('date +%s'));

    expect($gravado->createdAt)->not->toBeNull()
        ->and($gravado->createdAt)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/')
        ->and($gravado->command)->toBe('date +%s')
        ->and($gravado->exitCode)->toBe(0);
});

it('o created_at devolvido pelo insert é o mesmo que o recent() lê', function () {
    $p = new SQLiteProvider(sqliteTestConfig($this->dbPath));

    $gravado = $p->insert(novaExecucao('mesmo-carimbo'));

    expect($p->recent()[0]->createdAt)->toBe($gravado->createdAt);
});

it('insert() devolve o registro certo mesmo com dois idênticos no mesmo segundo', function () {
    // A leitura de volta é pelo id, não pelo conteúdo: com dois registros
    // iguais, buscar por comando pegaria o do vizinho.
    $p = new SQLiteProvider(sqliteTestConfig($this->dbPath));

    $primeiro = $p->insert(novaExecucao('duplicado', 'saida A'));
    $segundo  = $p->insert(novaExecucao('duplicado', 'saida B'));

    expect($primeiro->output)->toBe('saida A')
        ->and($segundo->output)->toBe('saida B')
        ->and($p->recent())->toHaveCount(2);
});

/*
|--------------------------------------------------------------------------
| Filtro por tipo
|--------------------------------------------------------------------------
|
| Os três tipos moram na mesma tabela, e cada tela mostra a última execução
| DELA. Estes testes exigem a separação nos dois sentidos: ler só o tipo
| pedido, e apagar só o tipo pedido.
|
| O SQLite é o único dos três drivers com cobertura real nesta máquina — os
| de MySQL e Mongo dependem de servidor e são pulados sem ele.
|
*/

/** Grava um registro de cada tipo, do mais antigo para o mais novo. */
function tresTipos(SQLiteProvider $p): void
{
    $p->insert(novaExecucao('um comando', kind: ExecutionKind::Comando));
    $p->insert(novaExecucao('um anexo', kind: ExecutionKind::Anexo));
    $p->insert(novaExecucao('uma acao do windows', kind: ExecutionKind::Windows));
}

it('recent() SEM filtro continua devolvendo todos os tipos', function () {
    // Guarda de compatibilidade: quem já chamava recent($limit) não pode
    // mudar de comportamento por causa do parâmetro novo.
    $p = new SQLiteProvider(sqliteTestConfig($this->dbPath));
    tresTipos($p);

    expect($p->recent())->toHaveCount(3);
});

it('recent() filtrado devolve só o tipo pedido', function (ExecutionKind $kind, string $esperado) {
    $p = new SQLiteProvider(sqliteTestConfig($this->dbPath));
    tresTipos($p);

    $lidas = $p->recent(100, $kind);

    expect($lidas)->toHaveCount(1)
        ->and($lidas[0]->command)->toBe($esperado)
        ->and($lidas[0]->kind)->toBe($kind);
})->with([
    [ExecutionKind::Comando, 'um comando'],
    [ExecutionKind::Anexo, 'um anexo'],
    [ExecutionKind::Windows, 'uma acao do windows'],
]);

it('recent() FILTRA ANTES DE APLICAR O LIMITE', function () {
    // Este é o teste que derruba um filtro feito em PHP depois da consulta.
    // A execução do Windows é a mais ANTIGA, com cinco comandos na frente
    // dela: quem lesse "as N mais recentes" e filtrasse na memória devolveria
    // lista VAZIA, existindo registro no banco — e nada na tela explicaria.
    $p = new SQLiteProvider(sqliteTestConfig($this->dbPath));
    $p->insert(novaExecucao('uma acao do windows', kind: ExecutionKind::Windows));
    foreach (range(1, 5) as $i) {
        $p->insert(novaExecucao('comando ' . $i));
    }

    $lidas = $p->recent(1, ExecutionKind::Windows);

    expect($lidas)->toHaveCount(1)
        ->and($lidas[0]->command)->toBe('uma acao do windows');
});

it('recent() filtrado devolve lista vazia quando não há daquele tipo', function () {
    $p = new SQLiteProvider(sqliteTestConfig($this->dbPath));
    $p->insert(novaExecucao('só comando'));

    expect($p->recent(100, ExecutionKind::Windows))->toBe([]);
});

it('CLEAR DE UM TIPO NÃO LEVA O OUTRO JUNTO', function () {
    $p = new SQLiteProvider(sqliteTestConfig($this->dbPath));
    tresTipos($p);

    $apagados = $p->clear(ExecutionKind::Windows);

    expect($apagados)->toBe(1)
        ->and($p->recent())->toHaveCount(2)
        ->and($p->recent(100, ExecutionKind::Windows))->toBe([])
        ->and($p->recent(100, ExecutionKind::Comando))->toHaveCount(1)
        ->and($p->recent(100, ExecutionKind::Anexo))->toHaveCount(1);
});

it('clear() SEM filtro continua apagando tudo', function () {
    $p = new SQLiteProvider(sqliteTestConfig($this->dbPath));
    tresTipos($p);

    expect($p->clear())->toBe(3)
        ->and($p->recent())->toBe([]);
});

it('clear() de um tipo ausente devolve zero e não apaga nada', function () {
    $p = new SQLiteProvider(sqliteTestConfig($this->dbPath));
    $p->insert(novaExecucao('só comando'));

    expect($p->clear(ExecutionKind::Windows))->toBe(0)
        ->and($p->recent())->toHaveCount(1);
});

it('grava e relê o tipo windows sem perder o valor', function () {
    $p = new SQLiteProvider(sqliteTestConfig($this->dbPath));
    $p->insert(novaExecucao('winutil -Action audit', 'ok', 0, 8123, ExecutionKind::Windows));

    $lido = $p->recent()[0];

    expect($lido->kind)->toBe(ExecutionKind::Windows)
        ->and($lido->kind->value)->toBe('windows')
        ->and($lido->command)->toBe('winutil -Action audit')
        ->and($lido->durationMs)->toBe(8123);
});
