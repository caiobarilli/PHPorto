<?php

declare(strict_types=1);

use App\Http\OllamaView;
use App\Http\Respond;
use App\Win\OllamaStatus;

function ollamaVramView(?OllamaStatus $status): OllamaView
{
    return new OllamaView(null, $status, '0.40.13', [], true, null, null);
}

it('cabe quando a VRAM livre cobre o mínimo do modelo', function () {
    $st = OllamaStatus::parse("NIVEL=medio\nGPU=GTX 1650, 4096, 0\n");

    expect(ollamaVramView($st)->fitsInGpu('llama3.2:3b'))->toBeTrue()
        ->and(ollamaVramView($st)->fitsInGpu('qwen3:4b'))->toBeTrue();
});

it('não cabe quando o mínimo do modelo passa da VRAM livre', function () {
    $st = OllamaStatus::parse("NIVEL=medio\nGPU=GTX 1650, 4096, 500\n");

    expect(ollamaVramView($st)->fitsInGpu('qwen3:4b'))->toBeFalse()
        ->and(ollamaVramView($st)->fitsInGpu('llama3.2:3b'))->toBeTrue();
});

it('sem placa não avisa', function () {
    $st = OllamaStatus::parse("NIVEL=medio\nEXE=sim\nPROC=0\n");

    expect(ollamaVramView($st)->fitsInGpu('qwen3:4b'))->toBeNull();
});

it('leitura de VRAM falhou ou incompleta não avisa', function () {
    $aprox = OllamaStatus::parse("NIVEL=medio\nGPUAPROX=GTX 1650,4095\n");

    expect(ollamaVramView($aprox)->fitsInGpu('qwen3:4b'))->toBeNull()
        ->and(ollamaVramView(null)->fitsInGpu('qwen3:4b'))->toBeNull();
});

it('a tela só mostra o aviso quando não cabe', function () {
    $cabe = ollamaVramView(OllamaStatus::parse("NIVEL=medio\nGPU=GTX 1650, 4096, 0\n"));
    $nao = ollamaVramView(OllamaStatus::parse("NIVEL=medio\nGPU=GTX 1650, 4096, 500\n"));
    $sem = ollamaVramView(OllamaStatus::parse("NIVEL=medio\nEXE=sim\nPROC=0\n"));

    expect(Respond::render('ollama.php', $cabe))->not->toContain('não cabe inteiro na GPU')
        ->and(Respond::render('ollama.php', $nao))->toContain('não cabe inteiro na GPU (usa a CPU também)')
        ->and(Respond::render('ollama.php', $sem))->not->toContain('não cabe inteiro na GPU');
});
