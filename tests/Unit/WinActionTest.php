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

it('tem as dezesseis ações: as quinze do menu em ordem, e o hyperv fora dele', function () {
    expect(array_map(static fn (WinAction $a): string => $a->value, WinAction::cases()))
        ->toBe([
            'audit', 'tweaks', 'debloat', 'dns', 'performance', 'install',
            'memory', 'network', 'exporter', 'processes', 'optimize', 'gpu',
            'gdid', 'rdp', 'sunshine',
            // Fora do menu da /win: atende a rota /hyperv, e por isso no fim.
            'hyperv',
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

it('performance aceita State on e off, na grafia da lista', function () {
    expect(WinAction::Performance->validate(['State' => 'off']))->toBe(['State' => 'off'])
        ->and(WinAction::Performance->validate(['State' => 'ON']))->toBe(['State' => 'on']);
});

it('performance recusa State fora de on e off', function () {
    WinAction::Performance->validate(['State' => 'turbo']);
})->throws(InvalidArgumentException::class, 'Valor inválido para State.');

it('performance off reverte o estado aplicado', function () {
    expect(WinAction::Performance->stateChange(['State' => 'off'])?->applied)->toBeFalse()
        ->and(WinAction::Performance->stateChange(['State' => 'on'])?->applied)->toBeTrue();
});

// ---------------------------------------------------------------- audit

it('audit aceita as subações run e open', function (string $sub) {
    expect(WinAction::Audit->validate(['SubAction' => $sub]))->toBe(['SubAction' => $sub]);
})->with(['run', 'open']);

it('audit recusa subação que não existe', function () {
    WinAction::Audit->validate(['SubAction' => 'delete']);
})->throws(InvalidArgumentException::class, 'Valor inválido para SubAction.');

it('audit open não afirma nada sobre estado', function () {
    expect(WinAction::Audit->stateChange(['SubAction' => 'open']))->toBeNull();
});

// ---------------------------------------------------------------- tweaks

it('tweaks exige preset ou itens', function () {
    WinAction::Tweaks->validate([]);
})->throws(InvalidArgumentException::class, 'Marque ao menos um tweak.');

it('tweaks aceita itens e devolve a grafia do tweaks.json', function () {
    expect(WinAction::Tweaks->validate(['Items' => 'wpftweakstelemetry, WPFTweaksServices']))
        ->toBe(['Items' => 'WPFTweaksTelemetry,WPFTweaksServices']);
});

it('tweaks recusa item que não está no tweaks.json', function () {
    WinAction::Tweaks->validate(['Items' => 'WPFTweaksTelemetry,WPFTweaksInventado']);
})->throws(InvalidArgumentException::class, 'Tweak desconhecido: WPFTweaksInventado.');

it('tweaks recusa os controles da janela do WinUtil, pelo Type', function (string $chave) {
    WinAction::Tweaks->validate(['Items' => $chave]);
})->throws(InvalidArgumentException::class, 'Tweak desconhecido')->with(['WPFOOSUbutton', 'WPFchangedns', 'WPFAddUltPerf', 'WPFRemoveUltPerf']);

it('tweaks recusa item repetido', function () {
    WinAction::Tweaks->validate(['Items' => 'WPFTweaksTelemetry,wpftweakstelemetry']);
})->throws(InvalidArgumentException::class, 'Tweak repetido: WPFTweaksTelemetry.');

it('tweaks recusa preset e itens juntos', function () {
    WinAction::Tweaks->validate(['Preset' => 'minimal', 'Items' => 'WPFTweaksTelemetry']);
})->throws(InvalidArgumentException::class, 'Escolha um preset ou marque tweaks, não os dois.');

it('tweaks por itens leva o Undo junto', function () {
    expect(WinAction::Tweaks->validate(['Items' => 'WPFTweaksTelemetry', 'Undo' => '1']))
        ->toBe(['Items' => 'WPFTweaksTelemetry', 'Undo' => true]);
});

// --------------------------------------------------------------- debloat

it('debloat sem lista devolve vazio: a ação remove o arquivo inteiro', function () {
    expect(WinAction::Debloat->validate([]))->toBe([]);
});

it('debloat aceita pacotes do debloat.json', function () {
    expect(WinAction::Debloat->validate(['Packages' => 'microsoftteams,Microsoft.BingNews']))
        ->toBe(['Packages' => 'MicrosoftTeams,Microsoft.BingNews']);
});

it('debloat recusa pacote fora do arquivo', function () {
    WinAction::Debloat->validate(['Packages' => 'Microsoft.WindowsCalculator']);
})->throws(InvalidArgumentException::class, 'Pacote desconhecido: Microsoft.WindowsCalculator.');

it('debloat vindo do formulário da tela sem nenhuma caixa é recusado, e não vira todos', function () {
    WinAction::Debloat->validate(['PackagesForm' => '1']);
})->throws(InvalidArgumentException::class, 'Marque ao menos um pacote.');

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

it('dns aceita cada chave do dns.json e o DHCP', function (string $provider) {
    $params = $provider === 'Custom' ? ['Provider' => $provider, 'PrimaryDNS' => '192.168.1.1'] : ['Provider' => $provider];

    expect(WinAction::Dns->validate($params)['Provider'])->toBe($provider);
})->with(fn (): array => [...\App\Win\WinConfig::dnsProviders(), 'DHCP']);

it('dns recusa o Default, que no WinUtil quer dizer "não mexer" e não faz nada', function () {
    WinAction::Dns->validate(['Provider' => 'Default']);
})->throws(InvalidArgumentException::class, 'Valor inválido para Provider.');

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

it('os principais do install são os sete IDs conferidos no winget', function () {
    expect(array_keys(WinAction::INSTALL_SUGGESTIONS))->toBe([
        'Git.Git', 'Microsoft.VisualStudioCode', 'Docker.DockerDesktop', 'Microsoft.WSL',
        'Debian.Debian', '7zip.7zip', 'VB-Audio.Voicemeeter.Potato',
    ]);
});

it('install só com caixas marcadas vira a lista dos marcados', function () {
    expect(WinAction::Install->validate(['AppsMarcados' => 'Git.Git,7zip.7zip']))
        ->toBe(['Apps' => 'Git.Git,7zip.7zip']);
});

it('install SOMA os marcados ao campo, sem repetir o que o campo já tem', function () {
    expect(WinAction::Install->validate([
        'Apps'         => 'Mozilla.Firefox, git.git',
        'AppsMarcados' => 'Git.Git,Microsoft.WSL',
    ]))->toBe(['Apps' => 'Mozilla.Firefox,git.git,Microsoft.WSL']);
});

it('install recusa caixa que não é dos principais', function () {
    WinAction::Install->validate(['AppsMarcados' => 'Mozilla.Firefox']);
})->throws(InvalidArgumentException::class, 'App desconhecido: Mozilla.Firefox.');

it('install sem campo e sem caixa diz as duas saídas', function () {
    WinAction::Install->validate(['Apps' => '']);
})->throws(InvalidArgumentException::class, 'Informe ao menos um app, separados por vírgula, ou marque um dos principais.');

it('install recusa quando a soma passa do teto', function () {
    WinAction::Install->validate([
        'Apps'         => str_repeat('a', WinAction::MAX_PARAM_BYTES - 5),
        'AppsMarcados' => 'Git.Git',
    ]);
})->throws(InvalidArgumentException::class, 'Apps passou do teto de 4096 bytes.');

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

// ---------------------------------------------------------------- rdp

it('rdp exige subação', function () {
    WinAction::Rdp->validate([]);
})->throws(InvalidArgumentException::class);

it('rdp aceita as cinco subações, na grafia da lista', function (string $sub) {
    expect(WinAction::Rdp->validate(['SubAction' => $sub]))->toBe(['SubAction' => $sub]);
})->with(['status', 'on', 'off', 'h264-on', 'h264-off']);

it('rdp devolve a grafia da LISTA, não a que veio no POST', function () {
    expect(WinAction::Rdp->validate(['SubAction' => 'H264-ON']))->toBe(['SubAction' => 'h264-on']);
});

it('rdp recusa subação que não existe', function () {
    WinAction::Rdp->validate(['SubAction' => 'reboot']);
})->throws(InvalidArgumentException::class, 'Valor inválido para SubAction.');

it('rdp status só relata: não afirma nada sobre estado', function () {
    expect(WinAction::Rdp->stateChange(['SubAction' => 'status']))->toBeNull();
});

it('rdp on/off movem o Applied, valendo na hora', function () {
    expect(WinAction::Rdp->stateChange(['SubAction' => 'on'])?->applied)->toBeTrue()
        ->and(WinAction::Rdp->stateChange(['SubAction' => 'on'])?->scope)->toBe(\App\Domain\WinStateScope::Applied)
        ->and(WinAction::Rdp->stateChange(['SubAction' => 'off'])?->applied)->toBeFalse()
        ->and(WinAction::Rdp->stateChange(['SubAction' => 'off'])?->scope)->toBe(\App\Domain\WinStateScope::Applied);
});

it('rdp h264-on/h264-off movem o PendingReboot, não o Applied', function () {
    // O vídeo H.264/UDP só vale depois de reiniciar: vai para outro escopo, e é
    // assim que aplicado e pendente ficam excludentes para o mesmo item.
    expect(WinAction::Rdp->stateChange(['SubAction' => 'h264-on'])?->applied)->toBeTrue()
        ->and(WinAction::Rdp->stateChange(['SubAction' => 'h264-on'])?->scope)->toBe(\App\Domain\WinStateScope::PendingReboot)
        ->and(WinAction::Rdp->stateChange(['SubAction' => 'h264-off'])?->applied)->toBeFalse()
        ->and(WinAction::Rdp->stateChange(['SubAction' => 'h264-off'])?->scope)->toBe(\App\Domain\WinStateScope::PendingReboot);
});

// ---------------------------------------------------------------- sunshine

it('sunshine exige subação', function () {
    WinAction::Sunshine->validate([]);
})->throws(InvalidArgumentException::class);

it('sunshine aceita as seis subações sem credencial, na grafia da lista', function (string $sub) {
    expect(WinAction::Sunshine->validate(['SubAction' => $sub]))->toBe(['SubAction' => $sub]);
})->with(['status', 'install', 'start', 'stop', 'firewall-open', 'firewall-close']);

it('sunshine tem oito subações: as seis de antes, set-creds e pair', function () {
    expect(WinAction::SUNSHINE_SUBACTIONS)->toBe(['status', 'install', 'start', 'stop', 'firewall-open', 'firewall-close', 'set-creds', 'pair']);
});

it('sunshine recusa subação que não existe', function (string $sub) {
    WinAction::Sunshine->validate(['SubAction' => $sub]);
})->with(['pin', 'uninstall', 'setup'])->throws(InvalidArgumentException::class, 'Valor inválido para SubAction.');

it('sunshine devolve a grafia da LISTA, não a que veio no POST', function () {
    expect(WinAction::Sunshine->validate(['SubAction' => 'FIREWALL-OPEN']))->toBe(['SubAction' => 'firewall-open']);
});

it('sunshine: só start e stop movem o estado do serviço, no Applied', function () {
    expect(WinAction::Sunshine->stateChange(['SubAction' => 'start'])?->applied)->toBeTrue()
        ->and(WinAction::Sunshine->stateChange(['SubAction' => 'start'])?->scope)->toBe(\App\Domain\WinStateScope::Applied)
        ->and(WinAction::Sunshine->stateChange(['SubAction' => 'stop'])?->applied)->toBeFalse()
        ->and(WinAction::Sunshine->stateChange(['SubAction' => 'stop'])?->scope)->toBe(\App\Domain\WinStateScope::Applied);
});

it('sunshine: install, firewall, status, set-creds e pair não afirmam nada sobre estado', function (string $sub) {
    expect(WinAction::Sunshine->stateChange(['SubAction' => $sub]))->toBeNull();
})->with(['status', 'install', 'firewall-open', 'firewall-close', 'set-creds', 'pair']);

// ------------------------------------------------- sunshine: credenciais e PIN

/** Um POST válido do pair, com o que se quiser trocar. */
function sunPair(array $troca = []): array
{
    return array_merge(['SubAction' => 'pair', 'User' => 'admin', 'Password' => 'segredo123', 'Pin' => '1234'], $troca);
}

/** Um POST válido do set-creds, com o que se quiser trocar. */
function sunCreds(array $troca = []): array
{
    return array_merge(['SubAction' => 'set-creds', 'User' => 'admin', 'Password' => 'segredo123'], $troca);
}

it('set-creds devolve só subação, usuário e senha', function () {
    expect(WinAction::Sunshine->validate(sunCreds()))
        ->toBe(['SubAction' => 'set-creds', 'User' => 'admin', 'Password' => 'segredo123']);
});

it('set-creds exige User e Password', function (array $falta, string $msg) {
    expect(fn () => WinAction::Sunshine->validate(sunCreds($falta)))->toThrow(InvalidArgumentException::class, $msg);
})->with([
    'sem User'     => [['User' => ''], 'Preencha User.'],
    'User só espaço' => [['User' => '   '], 'Preencha User.'],
    'sem Password' => [['Password' => ''], 'Preencha Password.'],
]);

it('set-creds RECUSA Pin e DeviceName preenchidos, em vez de ignorar', function (string $campo) {
    expect(fn () => WinAction::Sunshine->validate(sunCreds([$campo => $campo === 'Pin' ? '1234' : 'tv'])))
        ->toThrow(InvalidArgumentException::class, 'Só gravar credenciais não usa ' . $campo);
})->with(['Pin', 'DeviceName']);

it('set-creds não leva a caixa SetCreds adiante', function () {
    expect(WinAction::Sunshine->validate(sunCreds(['SetCreds' => '1'])))->not->toHaveKey('SetCreds');
});

it('a senha NÃO sofre trim: espaço nas pontas é senha', function () {
    expect(WinAction::Sunshine->validate(sunCreds(['Password' => ' abc12345 ']))['Password'])->toBe(' abc12345 ');
});

it('o usuário sofre trim', function () {
    expect(WinAction::Sunshine->validate(sunCreds(['User' => '  admin  ']))['User'])->toBe('admin');
});

it('senha com menos de 8 bytes é recusada; acento conta 2', function () {
    expect(fn () => WinAction::Sunshine->validate(sunCreds(['Password' => '1234567'])))
        ->toThrow(InvalidArgumentException::class, 'Password precisa de pelo menos 8 bytes.');

    // Quatro letras acentuadas = 8 bytes: passa.
    expect(WinAction::Sunshine->validate(sunCreds(['Password' => 'ãéíõ']))['Password'])->toBe('ãéíõ');
});

it('os tetos são em BYTES', function (string $campo, int $teto, string $sub) {
    $base  = $sub === 'pair' ? sunPair() : sunCreds();
    $exato = str_repeat('a', $teto);
    $acima = str_repeat('a', $teto - 1) . 'é';

    expect(WinAction::Sunshine->validate(array_merge($base, [$campo => $exato]))[$campo])->toBe($exato);
    expect(fn () => WinAction::Sunshine->validate(array_merge($base, [$campo => $acima])))
        ->toThrow(InvalidArgumentException::class, $campo . ' passou do teto de ' . $teto . ' bytes.');
})->with([
    'User 64'        => ['User', WinAction::SUNSHINE_USER_MAX, 'set-creds'],
    'Password 256'   => ['Password', WinAction::SUNSHINE_PASSWORD_MAX, 'set-creds'],
    'DeviceName 128' => ['DeviceName', WinAction::SUNSHINE_DEVICE_MAX, 'pair'],
]);

it('User com dois-pontos é recusado: o Basic parte no primeiro', function () {
    expect(fn () => WinAction::Sunshine->validate(sunCreds(['User' => 'ad:min'])))
        ->toThrow(InvalidArgumentException::class, "User não pode ter ':'.");
});

it('a senha PODE ter dois-pontos, aspas e barra', function () {
    $senha = 'a:b"c\\d\'e f';

    expect(WinAction::Sunshine->validate(sunCreds(['Password' => $senha]))['Password'])->toBe($senha);
});

it('caractere de controle e NUL são recusados nos quatro campos', function (string $campo, string $ruim) {
    $base = $campo === 'Pin' || $campo === 'DeviceName' ? sunPair() : sunCreds();

    expect(fn () => WinAction::Sunshine->validate(array_merge($base, [$campo => $ruim])))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'User NUL'          => ['User', "ad\0min"],
    'User TAB'          => ['User', "ad\tmin"],
    'Password NUL'      => ['Password', "segredo\0123"],
    'Password LF'       => ['Password', "segredo\n123"],
    'Password DEL'      => ['Password', "segredo\x7F123"],
    'Pin NUL'           => ['Pin', "12\0" . '4'],
    'DeviceName ESC'    => ['DeviceName', "tv\x1Bsala"],
]);

it('a mensagem de erro cita o campo, nunca o valor', function () {
    try {
        WinAction::Sunshine->validate(sunCreds(['Password' => "Xyzzy\x01Plugh99"]));
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->toContain('Password')
            ->and($e->getMessage())->not->toContain('Xyzzy')
            ->and($e->getMessage())->not->toContain('Plugh');

        return;
    }

    throw new RuntimeException('deveria ter recusado');
});

it('UTF-8 inválido é recusado aqui, e não no json_encode do pedido', function () {
    expect(fn () => WinAction::Sunshine->validate(sunCreds(['Password' => "segredo\xC3\x28123"])))
        ->toThrow(InvalidArgumentException::class, 'Password tem bytes que não são texto UTF-8.');
});

it('pair devolve subação, credenciais, PIN e o nome padrão', function () {
    expect(WinAction::Sunshine->validate(sunPair()))->toBe([
        'SubAction'  => 'pair',
        'User'       => 'admin',
        'Password'   => 'segredo123',
        'Pin'        => '1234',
        'DeviceName' => 'notebook',
    ]);
});

it('pair leva o nome digitado, aparado', function () {
    expect(WinAction::Sunshine->validate(sunPair(['DeviceName' => '  TV da sala ']))['DeviceName'])->toBe('TV da sala');
});

it('pair exige o PIN', function () {
    expect(fn () => WinAction::Sunshine->validate(sunPair(['Pin' => ''])))
        ->toThrow(InvalidArgumentException::class, 'Preencha Pin.');
});

it('pair exige PIN de exatamente 4 dígitos ASCII', function (string $pin) {
    expect(fn () => WinAction::Sunshine->validate(sunPair(['Pin' => $pin])))
        ->toThrow(InvalidArgumentException::class, 'Pin deve ter 4 dígitos');
})->with(['123', '12345', '12a4', '١٢٣٤', '１２３４', '12 4']);

it('pair aceita PIN com zero à esquerda, como texto', function () {
    expect(WinAction::Sunshine->validate(sunPair(['Pin' => '0007']))['Pin'])->toBe('0007');
});

it('pair só liga SetCreds com "1"', function () {
    expect(WinAction::Sunshine->validate(sunPair(['SetCreds' => '1']))['SetCreds'])->toBeTrue()
        ->and(WinAction::Sunshine->validate(sunPair(['SetCreds' => 'on'])))->not->toHaveKey('SetCreds')
        ->and(WinAction::Sunshine->validate(sunPair()))->not->toHaveKey('SetCreds');
});

it('pair exige User e Password também', function () {
    expect(fn () => WinAction::Sunshine->validate(sunPair(['User' => ''])))->toThrow(InvalidArgumentException::class, 'Preencha User.')
        ->and(fn () => WinAction::Sunshine->validate(sunPair(['Password' => ''])))->toThrow(InvalidArgumentException::class, 'Preencha Password.');
});

it('as seis subações antigas RECUSAM os campos de credencial', function (string $sub, string $campo) {
    expect(fn () => WinAction::Sunshine->validate(['SubAction' => $sub, $campo => '1234']))
        ->toThrow(InvalidArgumentException::class, $campo . ' não vale para a subação ' . $sub . '.');
})->with(['status', 'install', 'start', 'stop', 'firewall-open', 'firewall-close'])
  ->with(['User', 'Password', 'Pin', 'DeviceName', 'SetCreds']);

it('Password e Pin são os segredos', function () {
    expect(WinAction::SECRET_PARAMS)->toBe(['Password', 'Pin']);
});

// ---------------------------------------------------------------- hyperv

it('hyperv não tem parâmetro: listar devolve vazio, como memory e processes', function () {
    expect(WinAction::Hyperv->validate([]))->toBe([]);
});

it('hyperv é só leitura: não afirma nada sobre estado', function () {
    expect(WinAction::Hyperv->stateChange([]))->toBeNull();
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

// ---------------------------------------------------------------- sensíveis

it('a ação sensível é reconhecida pelo SubAction validado', function (WinAction $acao, array $params, bool $esperado) {
    expect($acao->isSensitive($params))->toBe($esperado);
})->with([
    'install, qualquer app'    => [WinAction::Install, ['Apps' => 'Mozilla.Firefox'], true],
    'install sem nada'         => [WinAction::Install, [], true],
    'rdp on'                   => [WinAction::Rdp, ['SubAction' => 'on'], true],
    'rdp off'                  => [WinAction::Rdp, ['SubAction' => 'off'], false],
    'rdp status'               => [WinAction::Rdp, ['SubAction' => 'status'], false],
    'rdp h264-on'              => [WinAction::Rdp, ['SubAction' => 'h264-on'], false],
    'sunshine install'         => [WinAction::Sunshine, ['SubAction' => 'install'], true],
    'sunshine firewall-open'   => [WinAction::Sunshine, ['SubAction' => 'firewall-open'], true],
    'sunshine firewall-close'  => [WinAction::Sunshine, ['SubAction' => 'firewall-close'], false],
    'sunshine start'           => [WinAction::Sunshine, ['SubAction' => 'start'], false],
    'sunshine status'          => [WinAction::Sunshine, ['SubAction' => 'status'], false],
    'sunshine set-creds'       => [WinAction::Sunshine, ['SubAction' => 'set-creds', 'User' => 'a', 'Password' => 'segredo123'], true],
    'sunshine pair'            => [WinAction::Sunshine, ['SubAction' => 'pair', 'Pin' => '1234'], true],
    'exporter install'         => [WinAction::Exporter, ['SubAction' => 'install'], true],
    'exporter firewall'        => [WinAction::Exporter, ['SubAction' => 'firewall'], true],
    'exporter metrics'         => [WinAction::Exporter, ['SubAction' => 'metrics'], false],
    'gpu install'              => [WinAction::Gpu, ['SubAction' => 'install'], true],
    'gpu uninstall'            => [WinAction::Gpu, ['SubAction' => 'uninstall'], false],
    'network fica de fora'     => [WinAction::Network, ['Interface' => 'Ethernet', 'Duration' => 30], false],
    'optimize -Undo fica fora' => [WinAction::Optimize, ['Undo' => true], false],
    'dns Custom fica de fora'  => [WinAction::Dns, ['Provider' => 'Custom', 'PrimaryDNS' => '1.1.1.1'], false],
    'subação ausente'          => [WinAction::Rdp, [], false],
    'subação de outro tipo'    => [WinAction::Rdp, ['SubAction' => true], false],
]);

it('as duas listas de sensíveis são a mesma, a do PHP e a $SENSIVEIS do worker', function () {
    // Mesmo motivo da paridade da allowlist: a lista do PHP só escolhe o
    // caminho, e a do worker é a tranca. Uma ação que só o PHP achasse
    // sensível abriria prompt à toa; uma que só o worker achasse seria
    // recusada pelo worker longo sem a tela oferecer o caminho do UAC.
    $worker = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Win/worker.ps1');

    expect(preg_match('/\$SENSIVEIS\s*=\s*@\{(.*?)\r?\n\}/s', $worker, $bloco))->toBe(1);
    expect(preg_match_all("/^    '([a-z-]+)'\s*=\s*(.+?)\s*$/m", $bloco[1], $linhas, PREG_SET_ORDER))->toBeGreaterThan(0);

    $noWorker = [];
    foreach ($linhas as [, $acao, $valor]) {
        if ($valor === "'*'") {
            $noWorker[$acao] = '*';
            continue;
        }

        preg_match_all("/'([a-z0-9-]+)'/", $valor, $subs);
        $noWorker[$acao] = $subs[1];
    }

    ksort($noWorker);
    $noPhp = WinAction::SENSITIVE;
    ksort($noPhp);

    expect($noWorker)->toBe($noPhp);
});

it('toda subação sensível existe na lista de subações da ação', function () {
    $listas = [
        'rdp'      => WinAction::RDP_SUBACTIONS,
        'sunshine' => WinAction::SUNSHINE_SUBACTIONS,
        'exporter' => WinAction::EXPORTER_SUBACTIONS,
        'gpu'      => WinAction::GPU_SUBACTIONS,
    ];

    foreach (WinAction::SENSITIVE as $acao => $regra) {
        expect(WinAction::tryFrom($acao))->not->toBeNull();

        if ($regra === '*') {
            continue;
        }

        expect($listas)->toHaveKey($acao);

        foreach ($regra as $sub) {
            expect($listas[$acao])->toContain($sub);
        }
    }
});

it('a allowlist do sunshine no worker tem os mesmos campos, subações e tetos que a WinAction', function () {
    // A paridade de ações não enxerga campo: um campo novo só do lado PHP seria
    // recusado pelo worker depois do UAC aceito, e um teto diferente aceitaria
    // aqui o que lá é recusado.
    $worker = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Win/worker.ps1');

    expect(preg_match("/^    'sunshine'\\s*=\\s*@\\{\\r?\\n(.*?)\\r?\\n    \\}/ms", $worker, $bloco))->toBe(1);
    preg_match_all("/^\\s+'([A-Za-z]+)'\\s*=\\s*@\\{(.*)\\}\\s*$/m", $bloco[1], $linhas, PREG_SET_ORDER);

    $campos = [];
    foreach ($linhas as [, $nome, $regra]) {
        $campos[$nome] = $regra;
    }

    expect(array_keys($campos))->toBe(['SubAction', ...WinAction::SUNSHINE_CRED_FIELDS]);

    expect(preg_match('/valores\s*=\s*@\(([^)]*)\)/', $campos['SubAction'], $valores))->toBe(1);
    preg_match_all("/'([a-z-]+)'/", $valores[1], $subs);
    expect($subs[1])->toBe(WinAction::SUNSHINE_SUBACTIONS);

    $teto = static function (string $regra, string $chave): ?int {
        return preg_match('/\b' . $chave . '\s*=\s*(\d+)/', $regra, $m) === 1 ? (int) $m[1] : null;
    };

    expect($teto($campos['User'], 'max'))->toBe(WinAction::SUNSHINE_USER_MAX)
        ->and($teto($campos['Password'], 'max'))->toBe(WinAction::SUNSHINE_PASSWORD_MAX)
        ->and($teto($campos['Password'], 'min'))->toBe(WinAction::SUNSHINE_PASSWORD_MIN)
        ->and($teto($campos['Pin'], 'max'))->toBe(4)
        ->and($teto($campos['DeviceName'], 'max'))->toBe(WinAction::SUNSHINE_DEVICE_MAX)
        ->and($campos['SetCreds'])->toContain("tipo = 'flag'");
});

it('os segredos do worker ($SEGREDOS) são os mesmos da WinAction', function () {
    $worker = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Win/worker.ps1');

    expect(preg_match("/\\\$SEGREDOS\\s*=\\s*@\\{(.*?)\\r?\\n\\}/s", $worker, $bloco))->toBe(1);
    expect(preg_match("/'sunshine'\\s*=\\s*@\\(([^)]*)\\)/", $bloco[1], $linha))->toBe(1);
    preg_match_all("/'([A-Za-z]+)'/", $linha[1], $nomes);

    expect($nomes[1])->toBe(WinAction::SECRET_PARAMS);
});

// ------------------------------------------------- histórico: segredo sai como ***

/** O rótulo do histórico, pelo método privado do Pages. */
function rotulo(WinAction $acao, array $params): string
{
    return (string) (new ReflectionMethod(App\Http\Pages::class, 'describe'))->invoke(null, $acao, $params);
}

it('o histórico mostra o usuário e esconde senha e PIN', function () {
    $params = WinAction::Sunshine->validate(sunPair(['Password' => 'Xyzzy segredo', 'Pin' => '4821', 'SetCreds' => '1']));

    expect(rotulo(WinAction::Sunshine, $params))
        ->toBe('sunshine -SubAction pair -User admin -Password *** -Pin *** -DeviceName notebook -SetCreds');
});

it('o histórico do set-creds não leva a senha', function () {
    $r = rotulo(WinAction::Sunshine, WinAction::Sunshine->validate(sunCreds(['Password' => 'Plugh12345'])));

    expect($r)->toBe('sunshine -SubAction set-creds -User admin -Password ***')
        ->and($r)->not->toContain('Plugh');
});

it('a órfã recolhida com *** do worker continua ***', function () {
    // O win-done já chega mascarado pelo $SEGREDOS do worker; o describe não
    // pode "desmascarar" nem duplicar.
    expect(rotulo(WinAction::Sunshine, ['SubAction' => 'pair', 'User' => 'admin', 'Password' => '***', 'Pin' => '***']))
        ->toBe('sunshine -SubAction pair -User admin -Password *** -Pin ***');
});

it('as outras ações continuam com o rótulo de antes', function () {
    expect(rotulo(WinAction::Dns, ['Provider' => 'Custom', 'PrimaryDNS' => '1.1.1.1']))->toBe('dns -Provider Custom -PrimaryDNS 1.1.1.1')
        ->and(rotulo(WinAction::Optimize, ['Kill' => 'a b', 'Undo' => true]))->toBe('optimize -Kill "a b" -Undo');
});
