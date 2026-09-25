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
