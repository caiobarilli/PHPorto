<?php

declare(strict_types=1);

use App\Domain\Execution;
use App\Domain\ExecutionKind;

it('converte duração de milissegundos para segundos', function () {
    $e = new Execution('ls', '', 0, 2340, ExecutionKind::Comando, false);

    expect($e->durationSeconds())->toBe(2.34);
});

it('distingue exit code 0 de exit code ausente', function () {
    $sucesso = new Execution('ls', '', 0, 1, ExecutionKind::Comando, false);
    $ausente = new Execution('ls', '', null, 1, ExecutionKind::Comando, false);

    expect($sucesso->exitCode)->toBe(0)
        ->and($ausente->exitCode)->toBeNull()
        ->and($sucesso->exitCode)->not->toBe($ausente->exitCode);
});

it('ExecutionKind vai e volta pelo valor de armazenamento', function () {
    expect(ExecutionKind::Comando->value)->toBe('comando')
        ->and(ExecutionKind::Anexo->value)->toBe('anexo')
        ->and(ExecutionKind::fromStorage('anexo'))->toBe(ExecutionKind::Anexo);
});

it('ExecutionKind cai em Comando para valor ausente ou desconhecido', function (mixed $bruto) {
    expect(ExecutionKind::fromStorage($bruto))->toBe(ExecutionKind::Comando);
})->with([null, '', 'coisa-que-nao-existe', 123]);
