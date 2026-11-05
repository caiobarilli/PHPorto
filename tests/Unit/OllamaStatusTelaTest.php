<?php

declare(strict_types=1);

use App\Http\OllamaView;
use App\Http\Pages\OllamaPage;
use App\Http\Respond;
use App\Win\Ollama;
use App\Win\OllamaStatus;

/**
 * @param list<array{name: string, size: int, size_vram: int, expires_at: ?string}>|null $loaded
 */
function ollamaStatusView(
    ?OllamaStatus $status = null,
    ?string $versao = null,
    ?array $loaded = null,
    string $parte = OllamaView::PARTE_COMPLETA,
): OllamaView {
    $st = $status ?? OllamaStatus::parse("NIVEL=medio\nEXE=sim\nPROC=0\n");

    return new OllamaView(
        blocked: null,
        status: $st,
        version: $versao,
        models: $versao !== null ? [] : null,
        installerReady: true,
        download: null,
        pull: null,
        loaded: $loaded,
        parte: $parte,
    );
}

it('serviço parado quando instalado sem API e sem processos', function () {
    $html = Respond::render('ollama.php', ollamaStatusView());

    expect($html)->toContain('<dt>Serviço</dt><dd>parado</dd>')
        ->and($html)->toContain('<dt>Endpoint</dt><dd><code>127.0.0.1:11434</code></dd>')
        ->and($html)->toContain('sem resposta em 127.0.0.1:11434');
});

it('serviço em execução quando a API responde', function () {
    $html = Respond::render('ollama.php', ollamaStatusView(versao: '0.40.13', loaded: []));

    expect($html)->toContain('<dt>Serviço</dt><dd>em execução</dd>')
        ->and($html)->toContain('igual à fixada ' . Ollama::VERSION);
});

it('versão diferente da fixada aparece com a tag da fixada', function () {
    $html = Respond::render('ollama.php', ollamaStatusView(versao: '0.41.0', loaded: []));

    expect($html)->toContain('0.41.0')
        ->and($html)->toContain('fixada ' . Ollama::VERSION)
        ->and($html)->not->toContain('igual à fixada');
});

it('sem modelos carregados mostra nenhum', function () {
    $html = Respond::render('ollama.php', ollamaStatusView(versao: '0.40.13', loaded: []));

    expect($html)->toContain('<dt>Modelos carregados</dt>')
        ->and($html)->toContain('nenhum');
});

it('modelos carregados listam nome, VRAM e expiração', function () {
    $loaded = [[
        'name' => 'llama3.2:3b',
        'size' => 2_000_000_000,
        'size_vram' => 2_147_483_648,
        'expires_at' => '2026-11-05T23:00:00Z',
    ]];
    $html = Respond::render('ollama.php', ollamaStatusView(versao: '0.40.13', loaded: $loaded));

    expect($html)->toContain('llama3.2:3b')
        ->and($html)->toContain('2048 MiB na GPU')
        ->and($html)->toContain('expira 2026-11-05T23:00:00Z')
        ->and($html)->toContain('carregado');
});

it('VRAM total usada e livre quando a placa tem leitura completa', function () {
    $st = OllamaStatus::parse("NIVEL=medio\nEXE=sim\nPROC=1\nGPU=GTX 1650, 4096, 1024\n");
    $html = Respond::render('ollama.php', ollamaStatusView($st, '0.40.13', []));

    expect($html)->toContain('total 4096 MiB')
        ->and($html)->toContain('usada 1024 MiB')
        ->and($html)->toContain('livre 3072 MiB');
});

it('serviço off na API sem versão e sem processos', function () {
    $st = OllamaStatus::parse("NIVEL=medio\nEXE=nao\nPROC=0\n");
    $html = Respond::render('ollama.php', ollamaStatusView($st));

    expect($html)->toContain('<dt>Serviço</dt><dd>não instalado</dd>');
});

it('Atualizar status usa o padrão parcial da /hyperv', function () {
    $completa = Respond::render('ollama.php', ollamaStatusView(versao: '0.40.13', loaded: []));
    $parcial = Respond::render('ollama.php', ollamaStatusView(versao: '0.40.13', loaded: [], parte: OllamaView::PARTE_ESTADO));

    expect($completa)->toContain('id="ollama-leitura"')
        ->and($completa)->toContain('data-atualizar>Atualizar status</a>')
        ->and($completa)->toContain("fetch('/ollama?parte=estado'")
        ->and($completa)->toContain("'X-PHPorto-Sem-Reload': '1'")
        ->and($completa)->toContain('<h1>Ollama</h1>')
        ->and($parcial)->toContain('id="ollama-leitura"')
        ->and($parcial)->not->toContain('<h1>Ollama</h1>')
        ->and($parcial)->not->toContain("fetch('/ollama?parte=estado'");
});

it('parte desconhecida cai em completa', function () {
    expect(OllamaPage::ollamaParte(null))->toBe(OllamaView::PARTE_COMPLETA)
        ->and(OllamaPage::ollamaParte('completa'))->toBe(OllamaView::PARTE_COMPLETA)
        ->and(OllamaPage::ollamaParte('estado'))->toBe(OllamaView::PARTE_ESTADO)
        ->and(OllamaPage::ollamaParte('outra'))->toBe(OllamaView::PARTE_COMPLETA);
});

it('versionMatches ignora o prefixo v', function () {
    expect(Ollama::versionMatches('0.40.13'))->toBeTrue()
        ->and(Ollama::versionMatches('v0.40.13'))->toBeTrue()
        ->and(Ollama::versionMatches('0.41.0'))->toBeFalse()
        ->and(Ollama::versionMatches(null))->toBeNull();
});

it('parsePs lê name size size_vram expires_at', function () {
    $json = '{"models":[{"name":"qwen3:4b","size":100,"size_vram":200,"expires_at":"2026-01-01T00:00:00Z"},{"name":1}]}';

    expect(Ollama::parsePs($json))->toBe([
        [
            'name' => 'qwen3:4b',
            'size' => 100,
            'size_vram' => 200,
            'expires_at' => '2026-01-01T00:00:00Z',
        ],
    ])->and(Ollama::parsePs('{}'))->toBe([])
        ->and(Ollama::parsePs('nao-json'))->toBe([]);
});

it('apiUrl aceita /api/ps e continua recusando o resto', function () {
    expect(Ollama::apiUrl('/api/ps'))->toBe('http://127.0.0.1:11434/api/ps')
        ->and(fn () => Ollama::apiUrl('/api/generate'))->toThrow(InvalidArgumentException::class);
});
