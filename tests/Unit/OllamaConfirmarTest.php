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
function ollamaConfirmarView(
    ?string $confirm = 'gemma3:1b',
    ?int $disk = 53_687_091_200,
    ?array $modelos = [],
    ?string $versao = '0.40.13',
    ?OllamaStatus $status = null,
): OllamaView {
    $st = $status ?? OllamaStatus::parse("NIVEL=medio\nEXE=sim\nPROC=1\nGPU=GTX 1650, 4096, 0\n");

    return new OllamaView(
        blocked: null,
        status: $st,
        version: $versao,
        models: $modelos,
        installerReady: true,
        download: null,
        pull: null,
        loaded: $versao !== null ? [] : null,
        confirmModel: $confirm,
        diskFreeBytes: $disk,
    );
}

it('modelo desconhecido sem confirmação é recusado e o pull não dispara', function () {
    $modelo = OllamaModel::parse('gemma3:1b');

    expect(Ollama::pullNeedsConfirm($modelo, []))->toBeTrue()
        ->and(Ollama::pullUnconfirmedRefusal($modelo, [], false))->toBe(Ollama::pullConfirmRefusal())
        ->and(Ollama::pullUnconfirmedRefusal($modelo, null, false))->toBe(Ollama::pullConfirmRefusal())
        ->and(Ollama::isPullConfirmed(null))->toBeFalse()
        ->and(Ollama::isPullConfirmed(''))->toBeFalse()
        ->and(Ollama::isPullConfirmed('0'))->toBeFalse()
        ->and(Ollama::isPullConfirmed('sim'))->toBeFalse()
        ->and(Ollama::vramRefusal($modelo, OllamaStatus::parse("NIVEL=medio\nGPU=GTX 1650, 4096, 0\n")))->toBeNull();
});

it('com confirmação o gate libera o pull do modelo desconhecido', function () {
    $modelo = OllamaModel::parse('gemma3:1b');

    expect(Ollama::isPullConfirmed('1'))->toBeTrue()
        ->and(Ollama::pullUnconfirmedRefusal($modelo, [], true))->toBeNull()
        ->and(Ollama::REGISTRO_PULL_NOVO_CONFIRMADO)->toBe('pull de modelo novo, confirmado')
        ->and(Ollama::CONFIRM_PULL)->toBe('ConfirmPull');

    $php = Ollama::pullChild($modelo, 'C:\\x\\pull.json');
    expect($php)->toContain('gemma3:1b')
        ->and($php)->toContain('http://127.0.0.1:11434/api/pull');
});

it('modelo recomendado não pede confirmação', function () {
    $qwen = OllamaModel::parse('qwen3:4b');
    $llama = OllamaModel::parse('llama3.2:3b');

    expect(Ollama::pullNeedsConfirm($qwen, []))->toBeFalse()
        ->and(Ollama::pullNeedsConfirm($llama, []))->toBeFalse()
        ->and(Ollama::pullUnconfirmedRefusal($qwen, [], false))->toBeNull()
        ->and(Ollama::pullUnconfirmedRefusal($llama, null, false))->toBeNull();
});

it('modelo já instalado fora da lista não pede confirmação', function () {
    $modelo = OllamaModel::parse('gemma3:1b');
    $instalados = ['gemma3:1b' => 815_000_000];

    expect(Ollama::pullNeedsConfirm($modelo, $instalados))->toBeFalse()
        ->and(Ollama::pullUnconfirmedRefusal($modelo, $instalados, false))->toBeNull();
});

it('a confirmação mostra VRAM e disco medidos', function () {
    $v = ollamaConfirmarView();
    $html = Respond::render('ollama.php', $v);

    expect($v->confirmPull())->toBeTrue()
        ->and($v->confirmVramText())->toBe('A placa tem 4096 MiB de VRAM.')
        ->and($v->confirmDiskText())->toBe('O disco tem 50,0 GB livres (pasta dos modelos do Ollama).')
        ->and($html)->toContain('id="modal-ollama-confirmar"')
        ->and($html)->toContain('Baixar modelo sem tamanho conhecido?')
        ->and($html)->toContain('são desconhecidos antes do download')
        ->and($html)->toContain('A placa tem 4096 MiB de VRAM.')
        ->and($html)->toContain('O disco tem 50,0 GB livres (pasta dos modelos do Ollama).')
        ->and($html)->toContain('>Baixar mesmo assim<')
        ->and($html)->toContain('name="ConfirmPull" value="1"')
        ->and($html)->toContain('id="btn-ollama-confirmar-baixar"')
        ->and($html)->toContain('confirmar.showModal');
});

it('a confirmação fala quando VRAM ou disco não deram para medir', function () {
    $st = OllamaStatus::parse("NIVEL=medio\nEXE=sim\nPROC=1\n");
    $v = ollamaConfirmarView(disk: null, status: $st);
    $html = Respond::render('ollama.php', $v);

    expect($v->confirmVramText())->toBe('Não deu para medir a VRAM da placa.')
        ->and($v->confirmDiskText())->toBe('Não deu para medir o espaço livre no disco onde o Ollama guarda os modelos.')
        ->and($html)->toContain('Não deu para medir a VRAM da placa.')
        ->and($html)->toContain('Não deu para medir o espaço livre no disco onde o Ollama guarda os modelos.');
});

it('o nome e o link da confirmação saem escapados na tela', function () {
    $v = ollamaConfirmarView(confirm: 'a"<b>:1b');
    $html = Respond::render('ollama.php', $v);

    expect($html)->toContain('<code>a&quot;&lt;b&gt;:1b</code>')
        ->and($html)->toContain('name="Model" value="a&quot;&lt;b&gt;:1b"')
        ->and($html)->not->toContain('value="a"<b>:1b"')
        ->and($html)->toContain('href="https://ollama.com/library"');
});

it('o link da biblioteca usa o nome sem a tag', function () {
    expect(OllamaModel::parse('gemma3:1b')->libraryPath())->toBe('gemma3')
        ->and(OllamaModel::parse('gemma3:1b')->libraryUrl())->toBe('https://ollama.com/library/gemma3')
        ->and(OllamaModel::parse('library/mistral:7b')->libraryUrl())->toBe('https://ollama.com/library/library/mistral')
        ->and(OllamaModel::parse('mistral')->libraryUrl())->toBe('https://ollama.com/library/mistral');

    $html = Respond::render('ollama.php', ollamaConfirmarView('phi3:mini'));
    expect($html)->toContain('href="https://ollama.com/library/phi3"')
        ->and($html)->toContain('página do modelo na biblioteca do Ollama');
});

it('modelsDir honra OLLAMA_MODELS e diskFreeBytes mede ou devolve null', function () {
    $antes = getenv('OLLAMA_MODELS');
    putenv('OLLAMA_MODELS=/tmp/phporto-ollama-models-teste');
    expect(Ollama::modelsDir())->toBe('/tmp/phporto-ollama-models-teste');

    if (is_string($antes) && $antes !== '') {
        putenv('OLLAMA_MODELS=' . $antes);
    } else {
        putenv('OLLAMA_MODELS');
    }

    $dir = sys_get_temp_dir();
    $livre = Ollama::diskFreeBytes($dir);
    expect($livre)->toBeInt()->and($livre)->toBeGreaterThan(0);

    expect(Ollama::diskFreeBytes(''))->toBeNull();

    $inexistente = $dir . DIRECTORY_SEPARATOR . 'phporto-ollama-' . uniqid('x', true);
    expect(is_dir($inexistente))->toBeFalse()
        ->and(Ollama::diskFreeBytes($inexistente))->toBeInt()
        ->and(Ollama::diskFreeBytes($inexistente))->toBeGreaterThan(0);
});

it('o modal de confirmação fica fora do miolo que o sem-reload troca', function () {
    $completa = Respond::render('ollama.php', ollamaConfirmarView());
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
        confirmModel: 'gemma3:1b',
        diskFreeBytes: 1000,
    ));

    expect($completa)->toContain('id="modal-ollama-confirmar"')
        ->and($estado)->toContain('id="ollama-leitura"')
        ->and($estado)->not->toContain('modal-ollama-confirmar');
});
