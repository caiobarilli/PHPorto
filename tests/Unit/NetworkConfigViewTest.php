<?php

declare(strict_types=1);

use App\Http\ConfigView;
use App\Http\Respond;
use App\Win\ElevationState;

function networkConfigHtml(bool $enabled, ?string $address = null, array $allow = []): string
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
        csrfToken: 't',
        csrfField: '_csrf',
        win: new ElevationState(on: false),
        winRetry: false,
        winProofTimeout: 30,
        hypervEnabled: false,
        hypervEnabledAt: null,
        uacOk: true,
        uacSummary: 'UAC ok.',
        tz: 'America/Sao_Paulo',
        networkEnabled: $enabled,
        networkAddress: $address,
        networkAllow: $allow,
        networkUpdatedAt: $enabled ? '2026-11-07T10:00:00+00:00' : null,
    ));
}

it('com a rede desligada a tela manda ligar pelo terminal e não tem botão', function () {
    $html = networkConfigHtml(false);

    expect($html)->toContain('id="rede"')->toContain('php phporto network on')
        ->and($html)->not->toContain('rede-desligar')
        ->and($html)->not->toContain('Desligar já');
});

it('com a rede ligada a tela mostra o endereço e a allowlist escapados e o botão Desligar já com CSRF', function () {
    $html = networkConfigHtml(true, 'http://192.168.1.20<:4001', ['192.168.1.0/24<script>alert(1)</script>']);

    expect($html)->toContain('Desligar já')
        ->and($html)->toContain('value="rede-desligar"')
        ->and($html)->toContain('name="_csrf" value="t"')
        ->and($html)->toContain('http://192.168.1.20&lt;:4001')
        ->and($html)->not->toContain('192.168.1.20<:')
        ->and($html)->toContain('192.168.1.0/24&lt;script&gt;alert(1)&lt;/script&gt;')
        ->and($html)->not->toContain('<script>alert(1)');
});

it('nenhuma tela liga a rede: só o terminal grava o estado com confirmação', function () {
    $pagina = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Http/Pages/ConfigPage.php');

    expect($pagina)->not->toContain('writeForCli')
        ->and($pagina)->not->toContain('$manager->enable')
        ->and($pagina)->toContain('->disable()');
});
