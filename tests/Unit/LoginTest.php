<?php

declare(strict_types=1);

use App\Http\Login;

it('o retorno depois do login aceita caminho da própria origem', function (string $uri) {
    expect(Login::safeReturn($uri))->toBe($uri);
})->with(['/', '/config', '/wsl?aba=anexos', '/hyperv']);

it('O RETORNO RECUSA REDIRECIONAR PARA FORA, e cai na home', function (mixed $uri) {
    expect(Login::safeReturn($uri))->toBe('/');
})->with([
    'protocolo relativo' => ['//exemplo.invalid/x'],
    'barra invertida'    => ['/\\exemplo.invalid'],
    'url absoluta'       => ['https://exemplo.invalid/'],
    'sem barra'          => ['config'],
    'quebra de linha'    => ["/config\r\nSet-Cookie: x=1"],
    'espaço'             => ['/con fig'],
    'vazio'              => [''],
    'nulo'               => [null],
    'não texto'          => [['/']],
]);

it('a página de entrada não tem data-sem-reload e usa campo de senha', function () {
    $fonte = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Views/login.php');

    expect($fonte)->toContain('type="password"')
        ->and($fonte)->toContain('autocomplete="current-password"')
        ->and($fonte)->not->toContain('data-sem-reload=');
});
