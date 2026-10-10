<?php

declare(strict_types=1);

use App\Domain\Execution;
use App\Domain\ExecutionKind;
use App\Http\Respond;
use App\Http\WslView;

/**
 * Monta a /wsl com as execuções e o estado da VM dados e devolve o HTML.
 *
 * @param list<Execution> $rows
 */
function wslHtml(array $rows, ?bool $awake = null, string $windowsRoot = ''): string
{
    return Respond::render('wsl.php', new WslView(
        rows: $rows,
        result: $rows[0] ?? null,
        blocked: null,
        notice: null,
        csrfToken: 't',
        csrfField: '_csrf',
        distro: 'Debian',
        root: '/home/x',
        timeout: 120,
        tz: 'America/Sao_Paulo',
        coldStartSeconds: 5,
        maxOutputBytes: 1048576,
        maxCommandBytes: 65536,
        maxPathBytes: 4096,
        awake: $awake,
        windowsRoot: $windowsRoot,
    ));
}

it('a /wsl mostra a data em dd/mm/aaaa hh:mm:ss no fuso, e não o UTC do banco', function () {
    $html = wslHtml([new Execution('ls', '', 0, 10, ExecutionKind::Comando, false, '2026-09-09 02:54:17')]);

    // Uma no painel da última execução, uma na tabela.
    expect(substr_count($html, '08/09/2026 23:54:17'))->toBe(2)
        ->and($html)->not->toContain('>2026-09-09 02:54:17');
});

it('o JSON que o botão de copiar entrega continua com o UTC gravado', function () {
    $html = wslHtml([new Execution('ls', '', 0, 10, ExecutionKind::Comando, false, '2026-09-09 02:54:17')]);

    preg_match('/data-logs="([^"]*)"/', $html, $m);
    $logs = json_decode(html_entity_decode($m[1] ?? '', ENT_QUOTES), true);

    expect($logs[0]['created_at'] ?? null)->toBe('2026-09-09 02:54:17');
});

it('o card de anexos tem o botão de inverter, que não envia o formulário', function () {
    expect(wslHtml([]))->toContain('<button type="button" class="btn btn-sm btn-ghost" id="btn-inverter" title="Inverter origem e destino">Inverter</button>');
});

it('os caminhos do anexo começam vazios, sem link nem texto de sentido', function () {
    $html = wslHtml([], null, '/mnt/c/Users/x');

    expect($html)->toContain('id="origem" name="origem" value=""')
        ->and($html)->toContain('id="destino" name="destino" value=""')
        ->and($html)->not->toContain('&rarr;')
        ->and($html)->not->toContain('Do Windows para a distro');
});

it('Preencher leva o perfil do Windows na origem e a raiz no destino, sem enviar', function () {
    $html = wslHtml([], null, '/mnt/c/Users/x');

    expect($html)->toMatch('~<button type="button"[^>]*id="btn-preencher"\s+data-origem="/mnt/c/Users/x" data-destino="/home/x">Preencher</button>~');
});

it('o perfil do Windows vira caminho /mnt/<letra> do WSL', function (string $windows, string $wsl) {
    expect(WslView::mntPath($windows))->toBe($wsl);
})->with([
    'perfil'          => ['C:\\Users\\x', '/mnt/c/Users/x'],
    'barra no fim'    => ['C:\\Users\\x\\', '/mnt/c/Users/x'],
    'outra letra'     => ['D:\\dados', '/mnt/d/dados'],
    'barra normal'    => ['C:/Users/x', '/mnt/c/Users/x'],
    'raiz do disco'   => ['C:\\', '/mnt/c'],
    'vazio'           => ['', ''],
    'rede'            => ['\\\\srv\\perfil', ''],
    'sem letra'       => ['/home/x', ''],
]);

it('o console: saída em #output fora de textarea, prompt $ com o campo de uma linha e o Enviar', function () {
    $html = wslHtml([new Execution('ls', "a\nb", 0, 10, ExecutionKind::Comando, false, '2026-09-09 02:54:17')]);

    expect($html)->toContain('<pre class="term-scroll" id="output">a' . "\n" . 'b</pre>')
        ->and($html)->toContain('<form method="post" action="/wsl" id="form-cmd" class="term-prompt" data-sem-reload>')
        ->and($html)->toContain('<textarea id="input" name="cmd" rows="1"')
        ->and($html)->toContain('<button type="submit" class="term-go" id="btn-enviar">Enviar</button>')
        ->and($html)->toContain('<span id="estado-cmd"></span>')
        ->and($html)->not->toContain('btn-limpar-campo');
});

it('Enter envia pelo botão com requestSubmit, e Shift+Enter fica para quebrar a linha', function () {
    $html = wslHtml([]);

    expect($html)->toContain("ev.key !== 'Enter' || ev.shiftKey")
        ->and($html)->toContain('botao.form.requestSubmit(botao)');
});

it('o indicador diz acordada, dormindo, ou se cala quando não se sabe', function () {
    expect(wslHtml([], true))->toContain('<span class="ok">acordada</span>')
        ->and(wslHtml([], false))->toContain('dormindo — o primeiro comando a acorda, em ~5 s')
        ->and(wslHtml([]))->not->toContain('acordada')
        ->and(wslHtml([]))->not->toContain('dormindo');
});

// ---- Sem reload (fase 1) ----------------------------------------------------

it('sem reload: os três formulários da /wsl são marcados', function () {
    $html = wslHtml([]);

    preg_match_all('/<form method="post"[^>]*>/', $html, $m);

    expect($m[0])->toHaveCount(3);
    foreach ($m[0] as $form) {
        expect($form)->toContain('data-sem-reload');
    }
});

it('sem reload: os registros vão no data-logs escapado, e não numa variável do script', function () {
    $html = wslHtml([new Execution('echo "</script><b>"', '', 0, 10, ExecutionKind::Comando, false, '2026-09-09 02:54:17')]);

    expect($html)->not->toContain('</script><b>')
        ->and($html)->not->toContain('var LOGS')
        ->and($html)->toContain('id="registros" data-logs="[{&quot;kind&quot;');
});

it('sem reload: apagar registros usa requestSubmit, com submit() só de recaída', function () {
    $html = wslHtml([]);

    expect($html)->toContain('if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }')
        ->and(substr_count($html, '.submit()'))->toBe(1);
});
