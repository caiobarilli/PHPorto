<?php

declare(strict_types=1);

use App\Http\OllamaView;
use App\Http\Respond;
use App\Win\Ollama;
use App\Win\OllamaModel;
use App\Win\OllamaStatus;

/**
 * @param array<string, int>|null $modelos
 */
function ollamaAdicionarView(?array $modelos = [], ?string $versao = '0.40.13', ?array $pull = null, ?string $recusa = null): OllamaView
{
    $st = OllamaStatus::parse("NIVEL=medio\nEXE=sim\nPROC=1\nGPU=GTX 1650, 4096, 0\n");

    return new OllamaView(
        blocked: $recusa,
        status: $st,
        version: $versao,
        models: $modelos,
        installerReady: true,
        download: null,
        pull: $pull,
        loaded: $versao !== null ? [] : null,
    );
}

it('aceita nomes no formato name:tag e com namespace', function (string $nome) {
    expect(OllamaModel::isValid($nome))->toBeTrue()
        ->and(OllamaModel::parse($nome)->value)->toBe($nome);
})->with([
    ['gemma3:1b'],
    ['phi3:mini'],
    ['mistral'],
    ['library/mistral:7b'],
    ['hf.co/org/model'],
    ['hf.co/org/model:latest'],
    ['qwen3:4b'],
    ['llama3.2:3b'],
    ['a'],
    ['a:b'],
]);

it('recusa injeção, maiúsculas, espaços e caminho', function (string $nome) {
    expect(OllamaModel::isValid($nome))->toBeFalse()
        ->and(fn () => OllamaModel::parse($nome))->toThrow(InvalidArgumentException::class);
})->with([
    ['gemma3:1b;rm -rf /'],
    ['gemma3:1b|id'],
    ['gemma3:1b && reboot'],
    ['$(curl evil)'],
    ['`id`'],
    ['Gemma3:1b'],
    [' gemma3:1b'],
    ['gemma3:1b '],
    ['../x'],
    ['a/b/c/d'],
    ['model name'],
    ['model/../../../etc'],
    [str_repeat('n', 129)],
]);

it('o pull filho aceita modelo fora da lista recomendada', function () {
    $modelo = OllamaModel::parse('gemma3:1b');
    $php = Ollama::pullChild($modelo, 'C:\\x\\pull.json');

    expect(OllamaModel::isRecommended('gemma3:1b'))->toBeFalse()
        ->and($php)->toContain('gemma3:1b')
        ->and($php)->toContain('http://127.0.0.1:11434/api/pull');

    $f = tempnam(sys_get_temp_dir(), 'olp') . '.php';
    file_put_contents($f, $php);
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($f), $saida, $rc);
    unlink($f);
    expect($rc)->toBe(0);
});

it('depois do download o modelo aparece na lista principal com Remover', function () {
    $modelos = [
        'qwen3:4b' => 2_684_354_560,
        'gemma3:1b' => 815_000_000,
    ];
    $v = ollamaAdicionarView($modelos);
    $html = Respond::render('ollama.php', $v);

    expect($v->extraModels())->toBe(['gemma3:1b' => 815_000_000])
        ->and($html)->toContain('name="Model" value="gemma3:1b"')
        ->and($html)->toContain('<code>gemma3:1b</code>')
        ->and($v->modelButtons('gemma3:1b'))->toBe(['pull' => false, 'remove' => true])
        ->and($html)->not->toContain('<h3>Fora da lista</h3>');
});

it('a tela tem Adicionar novo, o modal e o link da biblioteca', function () {
    $html = Respond::render('ollama.php', ollamaAdicionarView([]));

    expect($html)->toContain('id="btn-ollama-adicionar"')
        ->and($html)->toContain('>Adicionar novo<')
        ->and($html)->toContain('<dialog id="modal-ollama-adicionar">')
        ->and($html)->toContain('href="https://ollama.com/library"')
        ->and($html)->toContain('name="SubAction" value="pull"')
        ->and($html)->toContain('id="ollama-modelo-novo"')
        ->and($html)->toContain('maxlength="128"')
        ->and($html)->toContain('showModal');
});

it('Adicionar novo fica desabilitado sem API ou com pull andando', function () {
    $ok = ollamaAdicionarView([]);
    $semApi = ollamaAdicionarView([], null);
    $andando = ollamaAdicionarView([], '0.40.13', ['estado' => 'pulling', 'bytes' => 1, 'total' => 2]);
    $recusa = ollamaAdicionarView([], '0.40.13', null, 'não');

    expect($ok->canAddModel())->toBeTrue()
        ->and($semApi->canAddModel())->toBeFalse()
        ->and($andando->canAddModel())->toBeFalse()
        ->and($recusa->canAddModel())->toBeFalse();

    $htmlOk = Respond::render('ollama.php', $ok);
    $htmlAndando = Respond::render('ollama.php', $andando);

    expect($htmlOk)->toMatch('/id="btn-ollama-adicionar"(?! disabled)/')
        ->and($htmlAndando)->toContain('id="btn-ollama-adicionar" disabled');
});

it('aviso de VRAM usa o tamanho do /api/tags para modelo fora da lista', function () {
    $st = OllamaStatus::parse("NIVEL=medio\nGPU=GTX 1650, 4096, 500\n");
    $v = new OllamaView(
        blocked: null,
        status: $st,
        version: '0.40.13',
        models: ['mistral:7b' => 4_294_967_296],
        installerReady: true,
        download: null,
        pull: null,
        loaded: [],
    );
    $html = Respond::render('ollama.php', $v);

    expect($v->fitsInGpu('mistral:7b'))->toBeFalse()
        ->and(OllamaModel::minVramFromBytes(4_294_967_296))->toBe(4096 + 1536)
        ->and($html)->toContain('não cabe inteiro na GPU (usa a CPU também)');
});

it('o modal fica fora do miolo que o sem-reload troca', function () {
    $completa = Respond::render('ollama.php', ollamaAdicionarView([]));
    $estado = Respond::render('ollama.php', new OllamaView(
        blocked: null,
        status: OllamaStatus::parse("NIVEL=medio\nEXE=sim\nPROC=0\n"),
        version: '0.40.13',
        models: [],
        installerReady: true,
        download: null,
        pull: null,
        loaded: [],
        parte: OllamaView::PARTE_ESTADO,
    ));
    $modal = strpos($completa, '<dialog id="modal-ollama-adicionar">');
    $leitura = strpos($completa, 'id="ollama-leitura"');

    expect($modal)->toBeGreaterThan($leitura)
        ->and(str_contains(substr($completa, (int) $leitura, (int) $modal - (int) $leitura), '</div>'))->toBeTrue()
        ->and($estado)->toContain('id="ollama-leitura"')
        ->and($estado)->not->toContain('<dialog')
        ->and($estado)->toContain('btn-ollama-adicionar');
});
