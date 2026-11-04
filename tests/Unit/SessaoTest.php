<?php

declare(strict_types=1);

use App\Http\Csrf;
use App\Http\Https;

it('a sessão do CSRF liga strict mode e só aceita ID por cookie', function () {
    expect(Csrf::sessionIni())->toBe([
        'session.use_strict_mode'  => '1',
        'session.use_only_cookies' => '1',
        'session.use_trans_sid'    => '0',
    ]);
});

it('o cookie do CSRF liga Secure quando há HTTPS', function (mixed $https, bool $esperado) {
    expect(Csrf::cookieParams(['HTTPS' => $https])['secure'])->toBe($esperado)
        ->and(Https::active(['HTTPS' => $https]))->toBe($esperado);
})->with([
    'on'        => ['on', true],
    '1'         => ['1', true],
    'off'       => ['off', false],
    'OFF'       => ['OFF', false],
    'vazio'     => ['', false],
    'não texto' => [1, false],
]);

it('sem HTTPS o cookie do CSRF segue HttpOnly e Strict, sem Secure', function () {
    expect(Csrf::cookieParams([]))->toBe([
        'httponly' => true,
        'samesite' => 'Strict',
        'path'     => '/',
        'secure'   => false,
    ]);
});

it('regenerar sem sessão ativa não faz nada', function () {
    expect(session_status())->not->toBe(PHP_SESSION_ACTIVE);
    Csrf::regenerate();
    expect(session_status())->not->toBe(PHP_SESSION_ACTIVE);
});

it('o login troca o ID da sessão antes de emitir o cookie', function () {
    $fonte = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Http/Login.php');

    expect($fonte)->toMatch('/issueCookie\(\);\s+Csrf::regenerate\(\);/');
});
