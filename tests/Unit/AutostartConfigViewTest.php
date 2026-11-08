<?php

declare(strict_types=1);

use App\Http\ConfigView;
use App\Http\Respond;
use App\Win\ElevationState;

function autostartConfigHtml(bool $autostart, ?string $at = null, bool $network = false): string
{
    return Respond::render('config.php', new ConfigView(
        provider: 'sqlite',
        details: [],
        apiEnabled: false,
        apiFromEnv: false,
        apiOverridden: false,
        corsOrigin: 'http://127.0.0.1:4001',
        dbPath: '',
        dbExists: false,
        notice: null,
        csrfToken: 'tok',
        csrfField: '_csrf',
        win: new ElevationState(on: false),
        winRetry: false,
        winProofTimeout: 30,
        hypervEnabled: false,
        hypervEnabledAt: null,
        uacOk: true,
        uacSummary: 'UAC ok.',
        tz: 'America/Sao_Paulo',
        networkEnabled: $network,
        networkAddress: $network ? 'http://192.168.1.20:4001' : null,
        networkAllow: $network ? ['192.168.1.0/24'] : [],
        networkUpdatedAt: $network ? '2026-11-07T10:00:00+00:00' : null,
        autostartEnabled: $autostart,
        autostartUpdatedAt: $at,
    ));
}

it('a seção Iniciar com o Windows é separada da rede e tem os três botões com CSRF', function () {
    $html = autostartConfigHtml(false);

    expect($html)->toContain('id="iniciar"')->toContain('Iniciar com o Windows')
        ->and($html)->toContain('value="autostart-ligar"')->toContain('value="autostart-desligar"')->toContain('value="autostart-parar"')
        ->and($html)->toContain('Parar agora')
        ->and(substr_count($html, 'name="_csrf" value="tok"'))->toBeGreaterThanOrEqual(3)
        ->and($html)->toContain('Estado: <strong>desligado</strong>')
        ->and($html)->toContain('id="rede"')
        ->and($html)->toContain('ligar um não liga o outro');
});

it('a seção mostra ligado e a data, independente da rede', function (bool $network) {
    $html = autostartConfigHtml(true, '2026-11-07T10:00:00+00:00', $network);

    expect($html)->toContain('Estado: <strong>ligado</strong>')->toContain('desde <strong>');
})->with([true, false]);

it('a tela não pede, recebe nem guarda senha para o início com o Windows', function () {
    $html = autostartConfigHtml(true, '2026-11-07T10:00:00+00:00');
    [, $trecho] = explode('id="iniciar"', $html, 2);
    $trecho = explode('</section>', $trecho, 2)[0];

    expect($trecho)->not->toMatch('/type="password"|name="[^"]*(senha|password|pass)[^"]*"/i');

    $pagina = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Http/Pages/ConfigPage.php');
    $inicio = (int) strpos($pagina, 'private function handleAutostart');
    $metodo = substr($pagina, $inicio, (int) strpos($pagina, 'private function handleNetworkOff') - $inicio);

    expect($metodo)->not->toMatch('/\$_POST|password|senha|credential/i');
});

it('só ligar, desligar e parar passam pelo POST da /config, depois do CSRF', function () {
    $pagina = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Http/Pages/ConfigPage.php');

    expect($pagina)->toContain("['autostart-ligar', 'autostart-desligar', 'autostart-parar']")
        ->and(strpos($pagina, 'Csrf::consume()'))->toBeLessThan((int) strpos($pagina, "'autostart-ligar'"));
});
