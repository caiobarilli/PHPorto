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

function sessaoIsolada(string $codigo): string
{
    $dir    = sys_get_temp_dir() . '/phporto-sessao-' . bin2hex(random_bytes(4));
    $script = $dir . '.php';
    mkdir($dir);
    file_put_contents($script, "<?php\nrequire '" . dirname(__DIR__, 2) . "/vendor/autoload.php';\nini_set('session.save_path', '" . $dir . "');\n" . $codigo);
    $saida = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1');
    array_map('unlink', glob($dir . '/*') ?: []);
    rmdir($dir);
    unlink($script);

    return trim($saida);
}

it('COM STRICT MODE, UM ID DE SESSÃO INVENTADO PELO CLIENTE NÃO É ACEITO', function () {
    $saida = sessaoIsolada("\$_COOKIE['phporto_session'] = 'inventadopeloatacante1234567890ab';\nApp\\Http\\Csrf::start();\necho session_status() === PHP_SESSION_ACTIVE ? session_id() : 'inativa';\n");

    expect($saida)->not->toBe('inativa')
        ->and($saida)->not->toBe('')
        ->and($saida)->not->toBe('inventadopeloatacante1234567890ab');
});

it('O COOKIE DA SESSÃO DO CSRF SAI COM SECURE EM HTTPS, como o de login', function () {
    $saida = sessaoIsolada("\$_SERVER['HTTPS'] = 'on';\nApp\\Http\\Csrf::start();\necho session_get_cookie_params()['secure'] ? 'secure' : 'sem';\n");

    expect($saida)->toBe('secure');
});

it('regenerar inicia a sessão antes e troca o ID', function () {
    $saida = sessaoIsolada("App\\Http\\Csrf::start();\n\$antes = session_id();\n\$_SESSION['x'] = 'y';\nsession_write_close();\nApp\\Http\\Csrf::regenerate();\necho \$antes !== session_id() && (\$_SESSION['x'] ?? '') === 'y' ? 'ok' : 'falhou';\n");

    expect($saida)->toBe('ok');
});

it('o login troca o ID da sessão antes de emitir o cookie', function () {
    $fonte = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Http/Login.php');

    expect($fonte)->toMatch('/issueCookie\(\);\s+Csrf::regenerate\(\);/');
});
