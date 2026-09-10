<?php

declare(strict_types=1);

use App\Win\WinAction;

/**
 * A allowlist do lado PHP.
 *
 * Ela NÃO é a tranca — a tranca é a allowlist do worker.ps1, que roda em
 * integridade Alta. Estes testes cobrem a primeira barreira: recusar no
 * servidor, com mensagem que a pessoa entende, o que o formulário mandou
 * errado.
 */

it('tem as treze ações do menu, na ordem do menu', function () {
    expect(array_map(static fn (WinAction $a): string => $a->value, WinAction::cases()))
        ->toBe([
            'audit', 'tweaks', 'debloat', 'dns', 'performance', 'install',
            'memory', 'network', 'exporter', 'processes', 'optimize', 'gpu',
            'gdid',
        ]);
});

/**
 * A PARIDADE ENTRE AS DUAS ALLOWLISTS.
 *
 * As duas listas são redundantes de propósito, mas a redundância não é
 * automática: nada obriga o $ALLOWLIST do worker.ps1 a acompanhar este enum.
 * Uma ação que entre só de um lado fica pela metade — aceita aqui e recusada
 * lá com "acao fora da allowlist", ou executável por quem escrever no arquivo
 * de trabalho sem que a tela sequer a ofereça.
 *
 * Este teste lê o worker.ps1 como TEXTO, e é o único jeito: PHP não executa
 * PowerShell para perguntar, e um teste do Pester não enxerga o enum. Ler o
 * arquivo é grosseiro, mas é o que fecha a única costura entre as duas
 * linguagens que ninguém mais confere.
 */
it('as duas allowlists conhecem exatamente as mesmas ações', function () {
    $worker = file_get_contents(dirname(__DIR__, 2) . '/src/Win/worker.ps1');

    expect($worker)->toBeString();

    // Recorta o bloco $ALLOWLIST = @{ ... } e pega as chaves de primeiro nível,
    // que são as linhas 'nome' = @{ — as chaves de parâmetro vêm indentadas
    // mais fundo e não casam.
    expect(preg_match('/\$ALLOWLIST\s*=\s*@\{(.*?)\n\}/s', $worker, $bloco))->toBe(1);
    expect(preg_match_all("/^    '([a-z-]+)'\s*=/m", $bloco[1], $achadas))->toBeGreaterThan(0);

    $noWorker = $achadas[1];
    $noPhp    = array_map(static fn (WinAction $a): string => $a->value, WinAction::cases());

    sort($noWorker);
    sort($noPhp);

    expect($noWorker)->toBe($noPhp);
});

it('as ações sem parâmetro devolvem lista vazia', function (WinAction $acao) {
    expect($acao->validate([]))->toBe([]);
})->with([
    WinAction::Audit,
    WinAction::Debloat,
    WinAction::Performance,
    WinAction::Memory,
    WinAction::Processes,
]);

it('performance DESCARTA um state que venha no POST', function () {
    // Agora esta lista é a ÚNICA coisa que descarta. Antes havia duas travas:
    // esta e o param() do winutil-cli.ps1, que não declarava State. Aquele
    // ponto de entrada não existe mais e o bootstrap faz splatting direto em
    // Invoke-Performance, que DECLARA -State [ValidateSet('on','off')].
    expect(WinAction::Performance->validate(['State' => 'off']))->toBe([]);
});

// ---------------------------------------------------------------- tweaks

it('tweaks exige preset', function () {
    WinAction::Tweaks->validate([]);
})->throws(InvalidArgumentException::class);

it('tweaks aceita os três presets', function (string $preset) {
    expect(WinAction::Tweaks->validate(['Preset' => $preset]))->toBe(['Preset' => $preset]);
})->with(['standard', 'minimal', 'advanced']);

it('tweaks recusa preset fora do conjunto', function () {
    WinAction::Tweaks->validate(['Preset' => 'rm -rf /']);
})->throws(InvalidArgumentException::class);

it('tweaks devolve a grafia da LISTA, não a que veio no POST', function () {
    expect(WinAction::Tweaks->validate(['Preset' => 'STANDARD']))->toBe(['Preset' => 'standard']);
});

it('tweaks só inclui Undo quando o checkbox veio marcado', function () {
    expect(WinAction::Tweaks->validate(['Preset' => 'minimal']))
        ->toBe(['Preset' => 'minimal'])
        ->and(WinAction::Tweaks->validate(['Preset' => 'minimal', 'Undo' => '1']))
        ->toBe(['Preset' => 'minimal', 'Undo' => true])
        ->and(WinAction::Tweaks->validate(['Preset' => 'minimal', 'Undo' => '0']))
        ->toBe(['Preset' => 'minimal']);
});

// ---------------------------------------------------------------- dns

it('a lista de providers é a do dns.json, com os nove mais Default e DHCP', function () {
    expect(WinAction::DNS_PROVIDERS)->toContain(
        'Google',
        'Cloudflare',
        'Cloudflare_Malware',
        'Cloudflare_Malware_Adult',
        'Open_DNS',
        'Quad9',
        'AdGuard_Ads_Trackers',
        'AdGuard_Ads_Trackers_Malware_Adult',
        'Custom',
        'Default',
        'DHCP',
    );
});

it('dns exige provider', function () {
    WinAction::Dns->validate([]);
})->throws(InvalidArgumentException::class);

it('dns aceita provider da lista e ignora IP quando não é custom', function () {
    expect(WinAction::Dns->validate(['Provider' => 'Cloudflare', 'PrimaryDNS' => '1.1.1.1']))
        ->toBe(['Provider' => 'Cloudflare']);
});

it('dns aceita provider em qualquer caixa e devolve a grafia do arquivo', function () {
    expect(WinAction::Dns->validate(['Provider' => 'cloudflare']))->toBe(['Provider' => 'Cloudflare']);
});

it('dns custom exige o primário', function () {
    WinAction::Dns->validate(['Provider' => 'Custom']);
})->throws(InvalidArgumentException::class);

it('dns custom aceita primário e secundário válidos', function () {
    expect(WinAction::Dns->validate([
        'Provider'     => 'Custom',
        'PrimaryDNS'   => '192.168.1.10',
        'SecondaryDNS' => '1.0.0.1',
    ]))->toBe([
        'Provider'     => 'Custom',
        'PrimaryDNS'   => '192.168.1.10',
        'SecondaryDNS' => '1.0.0.1',
    ]);
});

it('dns custom aceita IPv6', function () {
    expect(WinAction::Dns->validate(['Provider' => 'Custom', 'PrimaryDNS' => '2606:4700:4700::1111']))
        ->toBe(['Provider' => 'Custom', 'PrimaryDNS' => '2606:4700:4700::1111']);
});

it('dns custom recusa o que não é IP', function (string $valor) {
    WinAction::Dns->validate(['Provider' => 'Custom', 'PrimaryDNS' => $valor]);
})->with(['nao-e-ip', '999.999.999.999', '1.1.1.1; shutdown'])->throws(InvalidArgumentException::class);

it('dns custom omite o secundário quando vem vazio', function () {
    expect(WinAction::Dns->validate([
        'Provider'     => 'Custom',
        'PrimaryDNS'   => '9.9.9.9',
        'SecondaryDNS' => '',
    ]))->toBe(['Provider' => 'Custom', 'PrimaryDNS' => '9.9.9.9']);
});

// ---------------------------------------------------------------- install

it('install exige apps', function () {
    WinAction::Install->validate([]);
})->throws(InvalidArgumentException::class);

it('install aceita TEXTO LIVRE, sem curar o catálogo do winget', function () {
    // Lista curada envelheceria em semanas. O valor nunca entra em linha de
    // comando — vai como literal dentro do script gerado pelo worker.
    expect(WinAction::Install->validate(['Apps' => 'Git.Git,Microsoft.VSCode,Docker.DockerDesktop']))
        ->toBe(['Apps' => 'Git.Git,Microsoft.VSCode,Docker.DockerDesktop'])
        ->and(WinAction::Install->validate(['Apps' => 'Publisher.App-With_Odd.Name+2']))
        ->toBe(['Apps' => 'Publisher.App-With_Odd.Name+2']);
});

it('install recusa entrada que só tem vírgulas', function () {
    WinAction::Install->validate(['Apps' => ' , , ']);
})->throws(InvalidArgumentException::class);

it('install recusa acima do teto de bytes', function () {
    WinAction::Install->validate(['Apps' => str_repeat('a', WinAction::MAX_PARAM_BYTES + 1)]);
})->throws(InvalidArgumentException::class);

it('install aceita exatamente no teto', function () {
    $valor = str_repeat('a', WinAction::MAX_PARAM_BYTES);

    expect(WinAction::Install->validate(['Apps' => $valor]))->toBe(['Apps' => $valor]);
});

// ---------------------------------------------------------------- network

it('network exige a interface, porque sem ela a ação recusa', function () {
    WinAction::Network->validate(['Duration' => '30']);
})->throws(InvalidArgumentException::class);

it('network aceita interface sem duração', function () {
    expect(WinAction::Network->validate(['Interface' => 'Ethernet']))->toBe(['Interface' => 'Ethernet']);
});

it('network aceita duração dentro da faixa, como inteiro', function () {
    expect(WinAction::Network->validate(['Interface' => 'Ethernet', 'Duration' => '60']))
        ->toBe(['Interface' => 'Ethernet', 'Duration' => 60]);
});

it('network recusa duração fora da faixa', function (string $d) {
    WinAction::Network->validate(['Interface' => 'Ethernet', 'Duration' => $d]);
})->with(['0', '3601', '99999'])->throws(InvalidArgumentException::class);

it('network recusa duração que não é número', function () {
    WinAction::Network->validate(['Interface' => 'Ethernet', 'Duration' => '30; shutdown']);
})->throws(InvalidArgumentException::class);

// ---------------------------------------------------------------- exporter / gpu

it('exporter e gpu têm conjuntos DIFERENTES de subações', function () {
    expect(WinAction::EXPORTER_SUBACTIONS)->toContain('firewall')
        ->and(WinAction::EXPORTER_SUBACTIONS)->not->toContain('uninstall')
        ->and(WinAction::GPU_SUBACTIONS)->toContain('uninstall')
        ->and(WinAction::GPU_SUBACTIONS)->not->toContain('firewall');
});

it('exporter exige subação', function () {
    WinAction::Exporter->validate([]);
})->throws(InvalidArgumentException::class);

it('exporter aceita as próprias e recusa a do gpu', function () {
    expect(WinAction::Exporter->validate(['SubAction' => 'firewall']))->toBe(['SubAction' => 'firewall']);

    expect(static fn () => WinAction::Exporter->validate(['SubAction' => 'uninstall']))
        ->toThrow(InvalidArgumentException::class);
});

it('gpu aceita as próprias e recusa a do exporter', function () {
    expect(WinAction::Gpu->validate(['SubAction' => 'uninstall']))->toBe(['SubAction' => 'uninstall']);

    expect(static fn () => WinAction::Gpu->validate(['SubAction' => 'firewall']))
        ->toThrow(InvalidArgumentException::class);
});

// ---------------------------------------------------------------- gdid

it('gdid exige subação', function () {
    WinAction::Gdid->validate([]);
})->throws(InvalidArgumentException::class);

it('gdid aceita as três subações', function (string $sub) {
    expect(WinAction::Gdid->validate(['SubAction' => $sub]))->toBe(['SubAction' => $sub]);
})->with(['status', 'disable', 'enable']);

it('gdid não compartilha subação com exporter nem com gpu', function () {
    // Ele liga e desliga um pipeline; não instala nem para processo.
    foreach (['install', 'start', 'stop', 'metrics', 'firewall', 'uninstall'] as $alheia) {
        expect(static fn () => WinAction::Gdid->validate(['SubAction' => $alheia]))
            ->toThrow(InvalidArgumentException::class);
    }
});

it('gdid devolve a grafia da LISTA, não a que veio no POST', function () {
    expect(WinAction::Gdid->validate(['SubAction' => 'DISABLE']))->toBe(['SubAction' => 'disable']);
});

it('gdid não entra em preset nenhum do optimize nem dos tweaks', function () {
    // Bloquear domínio de notificação é efeito amplo: tem de ser escolhido a
    // dedo, nunca herdado de quem pediu outra coisa.
    expect(WinAction::TWEAK_PRESETS)->not->toContain('gdid')
        ->and(WinAction::OPTIMIZE_PRESETS)->not->toContain('gdid');
});

// ---------------------------------------------------------------- optimize

it('optimize recusa chamada que não faria nada', function () {
    // Sem preset, sem lista e sem undo o Invoke-Optimize não tem o que fazer,
    // e a execução viraria uma linha no log parecendo falha.
    WinAction::Optimize->validate([]);
})->throws(InvalidArgumentException::class);

it('optimize NÃO aceita só KeepUser', function () {
    WinAction::Optimize->validate(['KeepUser' => 'caiob']);
})->throws(InvalidArgumentException::class);

it('optimize aceita os dois presets', function (string $preset) {
    expect(WinAction::Optimize->validate(['Preset' => $preset]))->toBe(['Preset' => $preset]);
})->with(['ssh', 'kill-rdp']);

it('optimize recusa preset que é dos tweaks', function () {
    WinAction::Optimize->validate(['Preset' => 'standard']);
})->throws(InvalidArgumentException::class);

it('optimize aceita lista de processos sozinha', function () {
    expect(WinAction::Optimize->validate(['Kill' => 'notepad,calc']))->toBe(['Kill' => 'notepad,calc']);
});

it('optimize aceita undo sozinho', function () {
    expect(WinAction::Optimize->validate(['Undo' => '1']))->toBe(['Undo' => true]);
});

it('optimize combina preset, lista, usuário protegido e undo', function () {
    expect(WinAction::Optimize->validate([
        'Preset'   => 'kill-rdp',
        'Kill'     => 'notepad',
        'KeepUser' => 'caiob',
        'Undo'     => '1',
    ]))->toBe([
        'Preset'   => 'kill-rdp',
        'Kill'     => 'notepad',
        'KeepUser' => 'caiob',
        'Undo'     => true,
    ]);
});

it('optimize recusa lista acima do teto de bytes', function () {
    WinAction::Optimize->validate(['Kill' => str_repeat('x', WinAction::MAX_PARAM_BYTES + 1)]);
})->throws(InvalidArgumentException::class);
