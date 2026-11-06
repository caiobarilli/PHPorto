<?php

declare(strict_types=1);

use App\Http\OllamaView;
use App\Http\Respond;
use App\Win\OllamaStatus;

/**
 * @param array<string, int>|null $modelos
 */
function ollamaModelosView(?array $modelos, ?string $versao = '0.40.13'): OllamaView
{
    $st = OllamaStatus::parse("NIVEL=medio\nEXE=sim\nPROC=0\n");

    return new OllamaView(
        blocked: null,
        status: $st,
        version: $versao,
        models: $modelos,
        installerReady: true,
        download: null,
        pull: null,
        loaded: $versao !== null ? [] : null,
    );
}

function ollamaModelosBaixado(string $html, string $modelo): string
{
    $re = '#<td>' . preg_quote($modelo, '#')
        . '(?: <span class="tag">carregado</span>)?</td>\s*'
        . '<td>[^<]+</td>\s*'
        . '<td>.*?</td>\s*'
        . '<td>([^<]+)</td>#s';

    if (preg_match($re, $html, $m) !== 1) {
        return '';
    }

    return $m[1];
}

it('serviço off deixa Baixado em traço e sem lista fora da lista', function () {
    $html = Respond::render('ollama.php', ollamaModelosView(null, null));

    expect(ollamaModelosBaixado($html, 'qwen3:4b'))->toBe('—')
        ->and(ollamaModelosBaixado($html, 'llama3.2:3b'))->toBe('—')
        ->and($html)->not->toContain('id="ollama-modelos-fora"')
        ->and($html)->not->toContain('<h3>Fora da lista</h3>');
});

it('nenhum modelo instalado marca não e não lista fora da lista', function () {
    $html = Respond::render('ollama.php', ollamaModelosView([]));

    expect(ollamaModelosBaixado($html, 'qwen3:4b'))->toBe('não')
        ->and(ollamaModelosBaixado($html, 'llama3.2:3b'))->toBe('não')
        ->and($html)->not->toContain('id="ollama-modelos-fora"')
        ->and(ollamaModelosView([])->extraModels())->toBe([]);
});

it('um modelo do catálogo mostra o tamanho real e Baixar/Remover coerentes', function () {
    $modelos = ['llama3.2:3b' => 2_147_483_648];
    $v = ollamaModelosView($modelos);
    $html = Respond::render('ollama.php', $v);

    expect(ollamaModelosBaixado($html, 'llama3.2:3b'))->toBe('2,0 GB')
        ->and(ollamaModelosBaixado($html, 'qwen3:4b'))->toBe('não')
        ->and($v->modelButtons('llama3.2:3b'))->toBe(['pull' => false, 'remove' => true])
        ->and($v->modelButtons('qwen3:4b'))->toBe(['pull' => true, 'remove' => false])
        ->and($html)->not->toContain('id="ollama-modelos-fora"')
        ->and($v->extraModels())->toBe([]);
});

it('os dois do catálogo instalados mostram o tamanho real de cada um', function () {
    $modelos = [
        'qwen3:4b' => 2_684_354_560,
        'llama3.2:3b' => 2_147_483_648,
    ];
    $v = ollamaModelosView($modelos);
    $html = Respond::render('ollama.php', $v);

    expect(ollamaModelosBaixado($html, 'qwen3:4b'))->toBe('2,5 GB')
        ->and(ollamaModelosBaixado($html, 'llama3.2:3b'))->toBe('2,0 GB')
        ->and($v->modelButtons('qwen3:4b')['remove'])->toBeTrue()
        ->and($v->modelButtons('llama3.2:3b')['remove'])->toBeTrue()
        ->and($html)->not->toContain('id="ollama-modelos-fora"');
});

it('modelo fora do catálogo aparece na lista sem botões', function () {
    $modelos = [
        'qwen3:4b' => 2_684_354_560,
        'mistral:7b' => 4_294_967_296,
        'phi3:mini' => 2_306_000_000,
    ];
    $v = ollamaModelosView($modelos);
    $html = Respond::render('ollama.php', $v);

    expect($v->extraModels())->toBe([
        'mistral:7b' => 4_294_967_296,
        'phi3:mini' => 2_306_000_000,
    ])
        ->and($html)->toContain('<h3>Fora da lista</h3>')
        ->and($html)->toContain('id="ollama-modelos-fora"')
        ->and($html)->toContain('<code>mistral:7b</code> — 4,0 GB')
        ->and($html)->toContain('<code>phi3:mini</code> — 2,1 GB')
        ->and($html)->not->toContain('name="Model" value="mistral:7b"')
        ->and($html)->not->toContain('name="Model" value="phi3:mini"')
        ->and(ollamaModelosBaixado($html, 'qwen3:4b'))->toBe('2,5 GB')
        ->and(ollamaModelosBaixado($html, 'llama3.2:3b'))->toBe('não');
});

it('parseTags alimenta a tabela com o tamanho real do /api/tags', function () {
    $json = '{"models":[{"name":"llama3.2:3b","size":2147483648},{"name":"outro:1b","size":1000000000}]}';
    $tags = \App\Win\Ollama::parseTags($json);
    $v = ollamaModelosView($tags);

    expect($tags)->toBe(['llama3.2:3b' => 2147483648, 'outro:1b' => 1000000000])
        ->and($v->extraModels())->toBe(['outro:1b' => 1000000000]);
});

it('não há botões de carregar ou descarregar modelo', function () {
    $html = Respond::render('ollama.php', ollamaModelosView(['qwen3:4b' => 1]));

    expect($html)->not->toContain('value="load"')
        ->and($html)->not->toContain('value="unload"')
        ->and($html)->not->toContain('>Carregar<')
        ->and($html)->not->toContain('>Descarregar<');
});
