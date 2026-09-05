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
