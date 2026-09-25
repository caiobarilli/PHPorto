<?php

declare(strict_types=1);

use App\Win\WinConfig;

beforeEach(function () {
    $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phporto-cfg-' . bin2hex(random_bytes(4));
    mkdir($this->dir);
});

afterEach(function () {
    foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
        unlink($f);
    }
    rmdir($this->dir);
});

it('debloat.json traz os 22 pacotes, sem repetição', function () {
    $pacotes = WinConfig::debloat();

    expect($pacotes)->toHaveCount(22)
        ->and(array_unique($pacotes))->toBe($pacotes)
        ->and($pacotes)->toContain('Microsoft.BingNews', 'Clipchamp.Clipchamp', 'MicrosoftTeams');
});

/**
 * A PARIDADE ENTRE A TELA E A AÇÃO.
 *
 * A tela lê debloat.json pelo WinConfig; a ação tem de ler o MESMO arquivo, e
 * não carregar uma cópia da lista. Lê o Invoke-Debloat.ps1 como texto, como o
 * teste de paridade das allowlists faz com o worker.
 */
it('o Invoke-Debloat lê a lista do debloat.json, e não tem lista própria', function () {
    $acao = file_get_contents(dirname(__DIR__, 2) . '/src/Win/actions/Invoke-Debloat.ps1');

    expect($acao)->toBeString()
        ->and($acao)->toContain('$sync.configs.debloat');

    foreach (WinConfig::debloat() as $pacote) {
        expect($acao)->not->toContain("'" . $pacote . "'");
    }
});

it('o bootstrap carrega o debloat.json junto com os outros configs', function () {
    $bootstrap = file_get_contents(dirname(__DIR__, 2) . '/src/Win/bootstrap.ps1');

    expect($bootstrap)->toBeString()
        ->and($bootstrap)->toContain("foreach (\$nome in 'debloat', 'dns', 'preset', 'tweaks')");
});

it('recusa debloat.json ausente', function () {
    WinConfig::debloat($this->dir);
})->throws(RuntimeException::class, 'config ausente');

it('recusa debloat.json que não é JSON', function () {
    file_put_contents($this->dir . DIRECTORY_SEPARATOR . 'debloat.json', '[');
    WinConfig::debloat($this->dir);
})->throws(RuntimeException::class, 'não é JSON válido');

it('recusa debloat.json que é objeto em vez de lista', function () {
    file_put_contents($this->dir . DIRECTORY_SEPARATOR . 'debloat.json', '{"a": "b"}');
    WinConfig::debloat($this->dir);
})->throws(RuntimeException::class, 'não é uma lista');

it('recusa debloat.json com item que não é nome de pacote', function () {
    file_put_contents($this->dir . DIRECTORY_SEPARATOR . 'debloat.json', '["Microsoft.BingNews", 3]');
    WinConfig::debloat($this->dir);
})->throws(RuntimeException::class, 'não é nome de pacote');

it('tolera BOM no começo do arquivo', function () {
    file_put_contents($this->dir . DIRECTORY_SEPARATOR . 'debloat.json', "\xEF\xBB\xBF[\"Microsoft.BingNews\"]");

    expect(WinConfig::debloat($this->dir))->toBe(['Microsoft.BingNews']);
});

// ---------------------------------------------------------------- tweaks

it('a tela oferece 62 tweaks: os 66 do arquivo menos os de Type Button ou Combobox', function () {
    $bruto = json_decode(WinConfig::escapeControlChars((string) file_get_contents(dirname(__DIR__, 2) . '/src/Win/config/tweaks.json')), true);
    expect($bruto)->toBeArray()->toHaveCount(66);

    $foraPeloType = array_keys(array_filter($bruto, static fn (array $t): bool => in_array($t['Type'] ?? '', ['Button', 'Combobox'], true)));
    sort($foraPeloType);

    expect($foraPeloType)->toBe(['WPFAddUltPerf', 'WPFOOSUbutton', 'WPFRemoveUltPerf', 'WPFchangedns'])
        ->and(WinConfig::tweakKeys())->toHaveCount(62)
        ->and(array_intersect(WinConfig::tweakKeys(), $foraPeloType))->toBe([]);
});

it('os grupos seguem o category, na ordem em que aparecem, sem o prefixo z__', function () {
    $categorias = array_values(array_unique(array_column(WinConfig::tweaks(), 'category')));

    expect($categorias)->toBe(['Essential Tweaks', 'Advanced Tweaks - CAUTION', 'Customize Preferences']);
});

it('o cuidado vem do próprio category: só o grupo CAUTION é marcado, com as 20', function () {
    $cuidado = array_filter(WinConfig::tweaks(), static fn (array $t): bool => $t['caution']);

    expect($cuidado)->toHaveCount(20)
        ->and(array_unique(array_column($cuidado, 'category')))->toBe(['Advanced Tweaks - CAUTION']);
});

it('todo tweak oferecido tem Content e Description do arquivo', function () {
    foreach (WinConfig::tweaks() as $t) {
        expect($t['content'])->not->toBe('')
            ->and($t['description'])->not->toBe('');
    }
});

it('a nota do Explorer vale exatamente para os seis que chamam Invoke-WinUtilExplorerUpdate', function () {
    $comNota = array_column(array_filter(WinConfig::tweaks(), static fn (array $t): bool => $t['explorer']), 'key');

    expect($comNota)->toBe([
        'WPFTweaksWidget', 'WPFToggleDarkMode', 'WPFToggleShowExt', 'WPFToggleHiddenFiles',
        'WPFToggleStartMenuRecommendations', 'WPFToggleTaskbarAlignment',
    ]);
});

/**
 * O BURACO HERDADO DO WPFTweaksDVR, fixado do lado da tela.
 *
 * Os presets standard e advanced citam uma chave que o tweaks.json não tem.
 * Ao casar a seleção com um preset, a chave ausente é desconsiderada — senão
 * nenhuma seleção de caixas reproduziria os dois presets. Quando o dado for
 * consertado, este teste falha e avisa que a exceção pode sair.
 */
it('WPFTweaksDVR segue citado pelos presets e ausente do tweaks.json, e o casamento o desconsidera', function () {
    $presetBruto = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/src/Win/config/preset.json'), true);

    expect($presetBruto['Standard'])->toContain('WPFTweaksDVR')
        ->and($presetBruto['Advanced'])->toContain('WPFTweaksDVR')
        ->and(WinConfig::tweakKeys())->not->toContain('WPFTweaksDVR');

    $presets = WinConfig::presets();

    expect($presets['standard'])->toHaveCount(count($presetBruto['Standard']) - 1)
        ->and($presets['advanced'])->toHaveCount(count($presetBruto['Advanced']) - 1)
        ->and(WinConfig::matchPreset($presets['standard'], $presets))->toBe('standard')
        ->and(WinConfig::matchPreset($presets['advanced'], $presets))->toBe('advanced');
});

it('todo tweak de preset existe entre os oferecidos', function () {
    foreach (WinConfig::presets() as $chaves) {
        expect(array_diff($chaves, WinConfig::tweakKeys()))->toBe([]);
    }
});

it('matchPreset reconhece o preset pelo conjunto, não pela ordem', function () {
    $presets = ['minimal' => ['A', 'B'], 'standard' => ['A', 'B', 'C']];

    expect(WinConfig::matchPreset(['B', 'A'], $presets))->toBe('minimal')
        ->and(WinConfig::matchPreset(['C', 'A', 'B'], $presets))->toBe('standard')
        ->and(WinConfig::matchPreset(['A'], $presets))->toBe('custom')
        ->and(WinConfig::matchPreset([], $presets))->toBe('');
});

it('escapeControlChars escapa só dentro de string', function () {
    expect(WinConfig::escapeControlChars("{\n \"a\": \"x\ny\"\n}"))->toBe("{\n \"a\": \"x\u000ay\"\n}")
        ->and(WinConfig::escapeControlChars("{\"a\": \"x\\\"\ny\"}"))->toBe("{\"a\": \"x\\\"\\u000ay\"}");
});

it('o tweaks.json cru não é JSON estrito, e é por isso que o escape existe', function () {
    json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/src/Win/config/tweaks.json'));

    expect(json_last_error())->toBe(JSON_ERROR_CTRL_CHAR);
});

// ------------------------------------------------------------------- dns

it('dnsProviders devolve as nove chaves do dns.json, na ordem do arquivo', function () {
    expect(WinConfig::dnsProviders())->toBe([
        'Google', 'Cloudflare', 'Cloudflare_Malware', 'Cloudflare_Malware_Adult', 'Open_DNS',
        'Quad9', 'AdGuard_Ads_Trackers', 'AdGuard_Ads_Trackers_Malware_Adult', 'Custom',
    ]);
});

it('toda opção de DNS da tela tem rótulo e texto, e não sobra texto sem opção', function () {
    $opcoes = [...WinConfig::dnsProviders(), ...\App\Win\WinAction::DNS_EXTRA];

    expect(array_keys(WinConfig::DNS_TEXTS))->toBe($opcoes);

    foreach (WinConfig::DNS_TEXTS as [$rotulo, $texto]) {
        expect($rotulo)->not->toBe('')
            ->and($texto)->not->toBe('');
    }
});
