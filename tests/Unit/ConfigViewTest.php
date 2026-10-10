<?php

declare(strict_types=1);

use App\Http\ConfigView;
use App\Http\Respond;
use App\Win\ElevationState;

/** Monta a /config com a API, o PowerShell e o aviso dados e devolve o HTML. */
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
        // O caminho do arquivo só aparece dentro do <details>.
        ->and(strpos($html, '<summary>Como funciona</summary>'))->toBeLessThan(strpos($html, 'storage/hyperv.json'));
});
