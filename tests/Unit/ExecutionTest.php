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
        ->and(ExecutionKind::Windows->value)->toBe('windows')
        ->and(ExecutionKind::fromStorage('anexo'))->toBe(ExecutionKind::Anexo)
        ->and(ExecutionKind::fromStorage('windows'))->toBe(ExecutionKind::Windows);
});

it('ExecutionKind cai em Comando para valor ausente ou desconhecido', function (mixed $bruto) {
    expect(ExecutionKind::fromStorage($bruto))->toBe(ExecutionKind::Comando);
})->with([null, '', 'coisa-que-nao-existe', 123]);

it('os tipos do WSL são todos os casos menos o do Windows', function () {
    // Tipo novo do lado WSL que não entrar aqui some da /wsl sem aviso.
    $esperado = array_values(array_filter(ExecutionKind::cases(), static fn (ExecutionKind $k): bool => $k !== ExecutionKind::Windows));

    expect(ExecutionKind::WSL)->toBe($esperado);
});
