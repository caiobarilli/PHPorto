<?php

declare(strict_types=1);

use App\Http\OllamaView;
use App\Http\Respond;
use App\Win\OllamaStatus;

/**
 * @param list<array{name: string, size: int, size_vram: int, expires_at: ?string}>|null $loaded
 */
function ollamaVramView(?OllamaStatus $status, ?array $loaded = null): OllamaView
{
    return new OllamaView(null, $status, '0.40.13', [], true, null, null, loaded: $loaded);
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

it('modelo já carregado não recebe aviso mesmo com VRAM livre apertada', function () {
    $st = OllamaStatus::parse("NIVEL=medio\nGPU=GTX 1650, 4096, 3800\n");
    $loaded = [[
        'name' => 'qwen3:4b',
        'size' => 2_500_000_000,
        'size_vram' => 3_800_000_000,
        'expires_at' => null,
    ]];
    $v = ollamaVramView($st, $loaded);
    $html = Respond::render('ollama.php', $v);

    expect($v->fitsInGpu('qwen3:4b'))->toBeTrue()
        ->and($v->fitsInGpu('llama3.2:3b'))->toBeFalse()
        ->and($html)->toContain('carregado')
        ->and(substr_count($html, 'não cabe inteiro na GPU'))->toBe(1);
});

it('demais modelos são checados contra a VRAM livre com um carregado', function () {
    $st = OllamaStatus::parse("NIVEL=medio\nGPU=GTX 1650, 4096, 2048\n");
    $loaded = [[
        'name' => 'llama3.2:3b',
        'size' => 2_000_000_000,
        'size_vram' => 2_147_483_648,
        'expires_at' => null,
    ]];
    $v = ollamaVramView($st, $loaded);
    $html = Respond::render('ollama.php', $v);

    expect($v->fitsInGpu('llama3.2:3b'))->toBeTrue()
        ->and($v->fitsInGpu('qwen3:4b'))->toBeFalse()
        ->and($html)->toContain('não cabe inteiro na GPU (usa a CPU também)')
        ->and($html)->toContain('carregado');
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
