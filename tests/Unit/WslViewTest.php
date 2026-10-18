<?php

declare(strict_types=1);

use App\Domain\Execution;
use App\Domain\ExecutionKind;
use App\Http\Respond;
use App\Http\WslView;
use App\Wsl\Upload;

/**
 * Monta a /wsl com as execuções e o estado da VM dados e devolve o HTML.
 *
 * @param list<Execution> $rows
 */
function wslHtml(array $rows, ?bool $awake = null, int $maxUploadBytes = 2097152): string
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
        maxUploadBytes: $maxUploadBytes,
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

it('o card de anexos não tem mais Origem, Inverter nem Preencher', function () {
    $html = wslHtml([]);

    expect($html)->not->toContain('id="origem"')
        ->and($html)->not->toContain('name="origem"')
        ->and($html)->not->toContain('btn-inverter')
        ->and($html)->not->toContain('btn-preencher')
        ->and($html)->not->toContain('>Inverter<')
        ->and($html)->not->toContain('>Preencher<')
        ->and($html)->not->toContain('Solte um arquivo aqui para preencher o nome.');
});

it('o anexo é multipart, com acao=anexo, token, um campo de arquivo e o destino', function () {
    $html = wslHtml([]);

    expect($html)->toMatch('~<form method="post" action="/wsl" id="form-anexo" enctype="multipart/form-data" data-sem-reload>\s*<input type="hidden" name="acao" value="anexo">\s*<input type="hidden" name="_csrf" value="t">~')
        ->and($html)->toMatch('~<input type="file" id="arquivo" name="arquivo" class="anexo-arquivo" required\s~')
        ->and($html)->toContain('id="destino" name="destino" value="" required')
        ->and($html)->toContain('<button type="submit" class="btn btn-sm btn-claro" id="btn-anexo">Enviar</button>');

    preg_match('~<form[^>]*id="form-anexo".*?</form>~s', $html, $m);
    expect(substr_count($m[0] ?? '', 'type="file"'))->toBe(1)
        ->and($m[0] ?? '')->not->toContain('multiple');
});

it('a zona é o rótulo do campo de arquivo, que segue alcançável pelo teclado', function () {
    $html = wslHtml([]);

    $campo = strpos($html, 'id="arquivo"');
    $zona  = strpos($html, '<label for="arquivo" class="anexo-solta" id="anexo-solta">');

    expect($zona)->toBeInt()
        ->and($campo)->toBeLessThan($zona)
        ->and($html)->not->toMatch('~id="arquivo"[^>]*(hidden|tabindex="-1"|disabled)~')
        ->and(layoutCss())->toContain('.anexo-arquivo:focus-visible + .anexo-solta')
        ->and(layoutCss())->not->toMatch('~\.anexo-arquivo\s*\{[^}]*display:\s*none~');
});

it('o card mostra o limite em uso e manda o MAX_FILE_SIZE antes do arquivo', function () {
    $html = wslHtml([], null, 2097152);

    expect($html)->toContain('Um arquivo por vez, até 2 MB.')
        ->and(strpos($html, 'name="MAX_FILE_SIZE" value="2097152"'))->toBeLessThan(strpos($html, 'id="arquivo"'))
        ->and(wslHtml([], null, Upload::MAX_BYTES))->toContain('até 25 MB.');
});

it('soltar põe o arquivo no campo de arquivo e não envia nem lê nada', function () {
    $html = wslHtml([]);

    preg_match("~document\\.addEventListener\\('drop'.*?\\n  \\}\\);~s", $html, $m);
    $drop = $m[0] ?? '';

    expect($drop)->not->toBe('')
        ->and($drop)->toContain('campo.files = soltos;')
        ->and($drop)->toContain('soltos.length > 1')
        ->and($drop)->not->toContain('submit')
        ->and($drop)->not->toContain('fetch(')
        ->and($html)->not->toContain('FileReader');
});

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

// ---- Tema do console ----------------------------------------------------------

function layoutCss(): string
{
    $html = Respond::render('layout.php', new \App\Http\LayoutView('PHPorto', '<div class="wrap">x</div>'));
    preg_match('#<style>(.*?)</style>#s', $html, $m);

    return $m[1] ?? '';
}

it('a barra do console não tem mais botões de tema, e a /wsl não guarda nada no navegador', function () {
    $html = wslHtml([]);

    expect($html)->toContain('<button type="button" class="term-btn" id="btn-copiar">Copiar</button>')
        ->and($html)->toContain('<button type="button" class="term-btn" id="btn-copiar-json">Copiar JSON</button>')
        ->and($html)->not->toContain('term-tema')
        ->and($html)->not->toContain('aria-pressed')
        ->and($html)->not->toContain('localStorage')
        ->and($html)->not->toContain('sessionStorage')
        ->and($html)->not->toContain('phporto.wsl.console.tema');
});

it('o CSS tem as duas paletas do console, e o claro só pinta o .term pelo data-tema do site', function () {
    $css = layoutCss();

    expect($css)->toContain('--oh-bg:          #282C34;')
        ->and($css)->toContain('--ohl-bg:          #FAFAFA;')
        ->and($css)->toContain('--ohl-fg:          #383A42;')
        ->and($css)->toContain('--ohl-cursor:      #4F525D;')
        ->and($css)->toContain('html[data-tema="claro"] .term {')
        ->and($css)->toMatch('~@media \(prefers-color-scheme: light\) \{\s*html:not\(\[data-tema\]\) \.term \{~')
        ->and($css)->not->toContain('data-term-tema');

    preg_match_all('~([^{}]*)\{[^{}]*--ohl-~', $css, $m);
    foreach (array_slice($m[1], 1) as $seletor) {
        expect(trim($seletor))->toEndWith('.term');
    }
});

it('o console tem o próprio color-scheme, escuro por padrão e claro nos dois blocos claros', function () {
    $css = layoutCss();

    preg_match('~\n  \.term \{([^}]*)\}~', $css, $base);
    preg_match('~html\[data-tema="claro"\] \.term \{([^}]*)\}~', $css, $claro);
    preg_match('~html:not\(\[data-tema\]\) \.term \{([^}]*)\}~', $css, $sistema);

    expect($base[1] ?? '')->toContain('color-scheme: dark;')
        ->and($base[1] ?? '')->toContain('border: var(--term-borda);')
        ->and($claro[1] ?? '')->toContain('color-scheme: light;')
        ->and($sistema[1] ?? '')->toContain('color-scheme: light;');
});

it('o Enviar do console claro não é branco no #FAFAFA, e o Enviar dos anexos não muda', function () {
    $css = layoutCss();

    preg_match('~html\[data-tema="claro"\] \.term \{([^}]*)\}~', $css, $claro);

    expect($claro[1] ?? '')->toContain('--t-go-bg: var(--ohl-fg); --t-go-fg: var(--ohl-bg);')
        ->and($css)->toContain('.term-go { background: var(--t-go-bg); color: var(--t-go-fg);')
        ->and($css)->toContain('.btn-claro { background: var(--claro-bg); color: var(--claro-fg); border: 1px solid var(--line); font-weight: 600; }')
        ->and($css)->toContain('--claro-bg:      #ffffff;')
        ->and($css)->toContain('--claro-fg:      #1b1f24;')
        ->and($css)->not->toContain('.term-go, .btn-claro');
});
