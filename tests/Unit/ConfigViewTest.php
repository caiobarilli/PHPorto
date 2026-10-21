<?php

declare(strict_types=1);

use App\Http\ConfigView;
use App\Http\Respond;
use App\Win\ElevationState;

function configHtml(
    bool $api = false,
    bool $ps = false,
    ?string $aviso = null,
    bool $retry = false,
    bool $hyperv = false,
    ?string $hypervAt = null,
): string {
    return Respond::render('config.php', new ConfigView(
        provider: 'sqlite',
        details: [],
        apiEnabled: $api,
        apiFromEnv: false,
        apiOverridden: false,
        corsOrigin: 'http://127.0.0.1:4001',
        dbPath: '/tmp/x.sqlite',
        dbExists: false,
        notice: $aviso,
        csrfToken: 't',
        csrfField: '_csrf',
        win: $ps ? new ElevationState(on: true, psPid: 1, provedAt: '2026-10-09 12:00:00') : new ElevationState(on: false),
        winRetry: $retry,
        winProofTimeout: 30,
        hypervEnabled: $hyperv,
        hypervEnabledAt: $hypervAt,
        uacOk: true,
        uacSummary: 'UAC ok.',
        tz: 'America/Sao_Paulo',
    ));
}

it('sem reload: os quatro formulários da /config são marcados', function () {
    preg_match_all('/<form method="post"[^>]*>/', configHtml(), $m);

    expect($m[0])->toHaveCount(4);
    foreach ($m[0] as $form) {
        expect($form)->toContain('data-sem-reload');
    }
});

it('sem reload: "já estava ligada" vem do atributo do formulário, e não do script', function (bool $api, bool $ps) {
    $html = configHtml(api: $api, ps: $ps);

    expect($html)->toContain('id="form-api" data-sem-reload data-ligada="' . ($api ? '1' : '0') . '"')
        ->and($html)->toContain('id="form-ps" data-sem-reload data-ligado="' . ($ps ? '1' : '0') . '"')
        ->and($html)->not->toContain('ligadaAgora')
        ->and($html)->not->toContain('psLigado');
})->with([
    'tudo desligado' => [false, false],
    'tudo ligado'    => [true, true],
]);

it('sem reload: o aviso leva data-aviso, para o rodapé repetir', function () {
    expect(configHtml(aviso: 'Pronto <ok>'))->toContain('<div class="alert alert-note" data-aviso>Pronto &lt;ok&gt;</div>')
        ->and(configHtml())->not->toContain('data-aviso');
});

it('sem reload: o "Tentar novamente" continua mandando ps_enabled=1 pelo próprio botão', function () {
    expect(configHtml(retry: true))->toContain('name="ps_enabled" value="1" id="btn-retentar" data-uac>');
});

it('sem reload: o Salvar do PowerShell desligado diz que espera o UAC, e o ligado não', function () {
    expect(configHtml())->toContain('id="btn-salvar-ps" data-uac')
        ->and(configHtml(ps: true))->not->toContain('id="btn-salvar-ps" data-uac');
});

it('sem reload: as confirmações usam requestSubmit, com submit() só de recaída', function () {
    $html = configHtml();

    expect($html)->toContain('if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }')
        ->and(substr_count($html, '.submit()'))->toBe(1);
});

it('Hyper-V: a data de ligado sai no fuso do Windows, não em ATOM cru', function () {
    $html = configHtml(hyperv: true, hypervAt: '2026-10-09T16:40:00+00:00');

    expect($html)->toContain('desde <strong>09/10/2026 13:40:00</strong>')
        ->and($html)->not->toContain('2026-10-09T16:40:00+00:00')
        ->and($html)->not->toContain('(UTC)');
});

it('Hyper-V: texto em uma linha, e o detalhe técnico recolhido', function () {
    $html = configHtml();

    expect($html)->toContain('Mostra a tela Hyper-V com as máquinas virtuais deste computador. Só leitura.')
        ->and(substr_count($html, 'storage/hyperv.json'))->toBe(1)
        ->and(strpos($html, '<summary>Como funciona</summary>'))->toBeLessThan(strpos($html, 'storage/hyperv.json'));
});

it('a Aparência vem antes da fábrica, com Claro, Escuro e Sistema e o Sistema marcado', function () {
    $html = configHtml();
    $aparencia = (int) strpos($html, '<section id="aparencia">');

    expect($aparencia)->toBeGreaterThan(0)
        ->and($aparencia)->toBeLessThan((int) strpos($html, '<h2>Restaurar configurações de fábrica</h2>'))
        ->and($html)->toContain('<div class="linha-acao" role="group" aria-label="Aparência">')
        ->and($html)->toContain('<button type="button" class="btn btn-sm btn-ghost" data-tema="claro" aria-pressed="false">Claro</button>')
        ->and($html)->toContain('<button type="button" class="btn btn-sm btn-ghost" data-tema="escuro" aria-pressed="false">Escuro</button>')
        ->and($html)->toContain('<button type="button" class="btn btn-sm btn-ghost" data-tema="sistema" aria-pressed="true">Sistema</button>')
        ->and(substr_count($html, 'aria-pressed="true"'))->toBe(1)
        ->and($html)->toContain('A escolha fica')
        ->and($html)->toContain('guardada só neste navegador, e a restauração de fábrica não mexe nela.');
});

it('o texto do topo continua verdadeiro: a aparência não vai para o flags.json', function () {
    expect(configHtml())->toContain('o <code>.env</code> nunca é reescrito. A aparência fica só neste navegador.</p>');
});

it('o script da /config grava phporto.tema, com Sistema apagando a chave, e troca o data-tema na hora', function () {
    $html = configHtml();

    expect($html)->toContain("var TEMA = 'phporto.tema';")
        ->and($html)->toContain('if (fixo) { localStorage.setItem(TEMA, modo); } else { localStorage.removeItem(TEMA); }')
        ->and($html)->toContain("if (fixo) { raiz.setAttribute('data-tema', modo); } else { raiz.removeAttribute('data-tema'); }")
        ->and($html)->toMatch('~try \{\s*if \(fixo\) \{ localStorage\.setItem~')
        ->and($html)->toContain("ev.target.closest('#aparencia [data-tema]')")
        ->and($html)->not->toContain('sessionStorage');
});

it('o botão marcado é repintado na carga e depois de cada troca sem reload', function () {
    $html = configHtml();

    expect($html)->toContain("document.addEventListener('phporto:trocou', pintarTema);\n  pintarTema();");
});

it('a restauração de fábrica não toca no tema', function () {
    $pages = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Http/Pages.php');

    expect($pages)->not->toContain('phporto.tema')
        ->and($pages)->not->toContain('data-tema');
});

it('sem reload fase 2: API e Hyper-V enviam ao mudar o interruptor, e o Salvar fica só sem JS', function () {
    $html = configHtml();

    expect($html)->toContain('id="api-toggle" data-envia-ao-mudar')
        ->and($html)->toContain('id="hyperv-toggle" data-envia-ao-mudar')
        ->and($html)->toContain('id="btn-salvar-api" data-so-sem-js>Salvar</button>')
        ->and($html)->toContain('id="btn-salvar-hyperv" data-so-sem-js>Salvar</button>')
        ->and($html)->toContain("ev.target.form.requestSubmit()")
        ->and($html)->toContain("document.querySelectorAll('[data-so-sem-js]')");
});

it('sem reload fase 2: o PowerShell mantém o Salvar visível e o modal, e não envia ao mudar', function () {
    $html = configHtml();
    $ps   = substr($html, (int) strpos($html, '<form method="post" id="form-ps"'));
    $ps   = substr($ps, 0, (int) strpos($ps, '</form>'));

    expect($ps)->toContain('id="btn-salvar-ps"')
        ->and($ps)->toContain('>Salvar</button>')
        ->and($ps)->not->toContain('data-envia-ao-mudar')
        ->and($ps)->not->toContain('data-so-sem-js')
        ->and($html)->toContain('<dialog id="modal-ps">');
});

it('sem reload fase 2: ligar a API ainda passa pelo modal, e cancelar volta o interruptor', function () {
    $html = configHtml();

    expect($html)->toContain("modal = 'modal-api';")
        ->and($html)->toContain("modalApi.addEventListener('close'")
        ->and($html)->toContain("caixa.checked = form.getAttribute('data-ligada') === '1';")
        ->and(substr_count($html, '.submit()'))->toBe(1)
        ->and($html)->not->toContain('innerHTML');
});
