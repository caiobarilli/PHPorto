<?php

declare(strict_types=1);

use App\Domain\OllamaExecution;
use App\Domain\OllamaExecutionAction;
use App\Domain\OllamaExecutionStatus;
use App\Providers\SQLiteProvider;
use App\Services\ExecutionLogService;

beforeEach(function () {
    $this->dbPath = tempnam(sys_get_temp_dir(), 'phporto_ollama_') ?: '';
});

afterEach(function () {
    if (is_string($this->dbPath) && $this->dbPath !== '' && file_exists($this->dbPath)) {
        @unlink($this->dbPath);
    }
});

it('cria ollama_executions no SQLite e grava, pagina, retém e apaga', function () {
    $p = new SQLiteProvider(['path' => $this->dbPath, 'table' => 'executions']);
    $svc = new ExecutionLogService($p);

    $a = $svc->recordOllama(new OllamaExecution(
        action: OllamaExecutionAction::Pull,
        output: 'pull iniciado',
        status: OllamaExecutionStatus::Iniciado,
        durationMs: 0,
        model: 'qwen3:4b',
    ));

    expect($a->id)->not->toBeNull()
        ->and($p->openOllamaPull('qwen3:4b')?->id)->toBe($a->id);

    $fim = $svc->finalizeOllama((int) $a->id, OllamaExecutionStatus::Ok, 'pull de modelo novo, confirmado', 1500);
    expect($fim?->status)->toBe(OllamaExecutionStatus::Ok)
        ->and($fim?->durationMs)->toBe(1500)
        ->and($p->openOllamaPull())->toBeNull();

    for ($i = 0; $i < 55; $i++) {
        $svc->recordOllama(new OllamaExecution(
            action: OllamaExecutionAction::Remove,
            output: 'r' . $i,
            status: OllamaExecutionStatus::Ok,
            durationMs: 1,
            model: 'llama3.2:3b',
        ));
    }

    $pagina = $svc->ollamaPage(1);
    expect($pagina['rows'])->toHaveCount(50)
        ->and($pagina['total'])->toBe(56)
        ->and($pagina['pages'])->toBe(2)
        ->and($svc->ollamaPage(2)['rows'])->toHaveCount(6);

    expect($svc->clearOllama())->toBe(56)
        ->and($p->countOllama())->toBe(0);
});

it('o trim do SQLite corta o excesso acima de 1000', function () {
    $p = new SQLiteProvider(['path' => $this->dbPath, 'table' => 'executions']);
    $svc = new ExecutionLogService($p);

    for ($i = 0; $i < 1005; $i++) {
        $svc->recordOllama(new OllamaExecution(
            action: OllamaExecutionAction::Install,
            output: 'x' . $i,
            status: OllamaExecutionStatus::Ok,
            durationMs: 1,
        ));
    }

    expect($p->countOllama())->toBe(1000);
});

it('mascara segredos no SQLite antes de persistir', function () {
    $p = new SQLiteProvider(['path' => $this->dbPath, 'table' => 'executions']);
    $svc = new ExecutionLogService($p);

    $svc->recordOllama(new OllamaExecution(
        action: OllamaExecutionAction::Chat,
        output: '-Password secreto -Pin 9999',
        status: OllamaExecutionStatus::Ok,
        durationMs: 3,
        model: 'llama3.2:3b',
        prompt: 'token=abcdef',
    ));

    $row = $p->recentOllama(1)[0];
    expect($row->output)->toContain('-Password ***')
        ->and($row->output)->not->toContain('secreto')
        ->and($row->prompt)->toContain('token=***')
        ->and($row->prompt)->not->toContain('abcdef')
        ->and($row->user)->toBe('—');
});
