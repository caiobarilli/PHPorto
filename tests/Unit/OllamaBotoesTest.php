<?php

declare(strict_types=1);

use App\Http\OllamaView;
use App\Http\Respond;
use App\Win\OllamaStatus;

function ollamaView(bool $instalado = true, int $proc = 0, ?string $versao = null, ?array $modelos = [], ?array $pull = null, ?array $download = null, ?string $recusa = null): OllamaView
{
    $st = OllamaStatus::parse("NIVEL=medio\nEXE=" . ($instalado ? 'sim' : 'nao') . "\nPROC=$proc\n");

    return new OllamaView($recusa, $st, $versao, $modelos, true, $download, $pull);
}

function botao(string $html, string $sub, ?string $modelo = null): string
{
    $re = '#name="SubAction" value="' . $sub . '">' . ($modelo !== null ? '<input type="hidden" name="Model" value="' . preg_quote($modelo, '#') . '">' : '') . '(<button[^>]*>)#';
    preg_match($re, $html, $m);

    return $m[1] ?? '';
}

it('Instalar fica desabilitado depois de instalado, e Desinstalar só ativo quando instalado', function () {
    expect(ollamaView(instalado: false)->buttons())->toMatchArray(['install' => true, 'uninstall' => false])
        ->and(ollamaView(instalado: true)->buttons())->toMatchArray(['install' => false, 'uninstall' => true]);
});

it('Instalar fica desabilitado enquanto o instalador baixa', function () {
    expect(ollamaView(instalado: false, download: ['estado' => 'baixando', 'bytes' => 1, 'total' => 2])->buttons()['install'])->toBeFalse();
});

it('Iniciar fica desabilitado com a API respondendo, e Parar só ativo rodando', function () {
    expect(ollamaView()->buttons())->toMatchArray(['start' => true, 'stop' => false])
        ->and(ollamaView(versao: '0.40.13')->buttons())->toMatchArray(['start' => false, 'stop' => true])
        ->and(ollamaView(proc: 2)->buttons())->toMatchArray(['start' => true, 'stop' => true]);
});

it('Baixar fica desabilitado com o modelo baixado ou com download andando, e Remover só com o modelo baixado', function () {
    $v = ollamaView(versao: '0.40.13', modelos: ['qwen3:4b' => 2_500_000_000]);
    expect($v->modelButtons('qwen3:4b'))->toBe(['pull' => false, 'remove' => true])
        ->and($v->modelButtons('llama3.2:3b'))->toBe(['pull' => true, 'remove' => false]);

    $andando = ollamaView(versao: '0.40.13', pull: ['estado' => 'pulling', 'bytes' => 1, 'total' => 2]);
    expect($andando->modelButtons('llama3.2:3b'))->toBe(['pull' => false, 'remove' => false]);

    $fim = ollamaView(versao: '0.40.13', pull: ['estado' => 'success', 'bytes' => 2, 'total' => 2]);
    expect($fim->modelButtons('llama3.2:3b')['pull'])->toBeTrue();
});

it('recusa de nível desabilita todos os botões', function () {
    $v = ollamaView(versao: '0.40.13', modelos: ['qwen3:4b' => 1], recusa: 'não');
    expect(array_filter($v->buttons()))->toBe([])
        ->and(array_filter($v->modelButtons('qwen3:4b')))->toBe([]);
});

it('a tela renderiza o disabled conforme o estado', function () {
    $html = Respond::render('ollama.php', ollamaView(versao: '0.40.13', modelos: ['qwen3:4b' => 2_500_000_000]));

    expect(botao($html, 'install'))->toContain(' disabled')
        ->and(botao($html, 'uninstall'))->not->toContain('disabled')
        ->and(botao($html, 'start'))->toContain(' disabled')
        ->and(botao($html, 'stop'))->not->toContain('disabled')
        ->and(botao($html, 'pull', 'qwen3:4b'))->toContain(' disabled')
        ->and(botao($html, 'remove', 'qwen3:4b'))->not->toContain('disabled')
        ->and(botao($html, 'pull', 'llama3.2:3b'))->not->toContain('disabled')
        ->and(botao($html, 'remove', 'llama3.2:3b'))->toContain(' disabled');
});

it('com download de modelo andando a tela segue recarregando sozinha', function () {
    $html = Respond::render('ollama.php', ollamaView(versao: '0.40.13', pull: ['estado' => 'pulling', 'bytes' => 1, 'total' => 2]));

    expect($html)->toContain("location.replace('/ollama')")
        ->and(botao($html, 'pull', 'qwen3:4b'))->toContain(' disabled');
});
