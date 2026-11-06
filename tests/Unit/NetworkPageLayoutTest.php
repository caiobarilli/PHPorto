<?php

declare(strict_types=1);

it('todas as páginas recebem o estado para o aviso no layout', function (string $file) {
    $html = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Http/Pages/' . $file);

    expect(substr_count($html, 'Respond::html('))->toBeGreaterThan(0);
    expect(preg_match_all('/Respond::html\((.*?)\);/s', $html, $matches))->toBeGreaterThan(0);

    foreach ($matches[1] as $call) {
        expect($call)->toContain('$this->network');
    }
})->with([
    'HomePage.php',
    'ConfigPage.php',
    'WslPage.php',
    'WinPage.php',
    'HypervPage.php',
    'PhpPage.php',
    'OllamaPage.php',
]);

it('o índice fornece o estado único às páginas', function () {
    $index = (string) file_get_contents(dirname(__DIR__, 2) . '/public/index.php');

    expect(substr_count($index, "'network'       => \$network"))->toBe(1)
        ->and(strpos($index, "'network'       => \$network"))->toBeLessThan(strpos($index, 'match ($path)'));
});

it('o fluxo da rede evita o contador global e não abre o banco fora da allow', function () {
    $index = (string) file_get_contents(dirname(__DIR__, 2) . '/public/index.php');

    expect(strpos($index, 'new LoginAudit($network, $makeService)'))->toBeLessThan(strpos($index, 'Login::guard('))
        ->and($index)->toContain('new LoginAttempts($filesDir)');
});
