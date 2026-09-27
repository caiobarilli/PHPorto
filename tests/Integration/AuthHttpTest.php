<?php

declare(strict_types=1);

use App\Http\Auth;

/*
 * O portão de token pelo php -S de verdade, na frente de todas as rotas.
 *
 * Só manda requisição sem credencial ou com o token certo: nenhuma das duas
 * escreve no contador de storage/, que é o desta máquina.
 */

const AUTH_HTTP_TOKEN = 'token-do-teste-http';

/**
 * Sobe um php -S com o token dado no ambiente e devolve [processo, porta].
 *
 * @return array{0: resource, 1: int}
 */
function sobeServidor(string $token): array
{
    $sonda = stream_socket_server('tcp://127.0.0.1:0');
    $porta = (int) substr((string) stream_socket_get_name($sonda, false), strrpos((string) stream_socket_get_name($sonda, false), ':') + 1);
    fclose($sonda);

    $raiz = dirname(__DIR__, 2);
    $env  = getenv();
    $env['PHPORTO_AUTH_TOKEN'] = $token;
    $env['SQLITE_PATH']        = str_replace('\\', '/', sys_get_temp_dir()) . '/phporto_auth_http_' . $porta . '.sqlite';

    $proc = proc_open(
        [PHP_BINARY, '-d', 'variables_order=EGPCS', '-S', '127.0.0.1:' . $porta, '-t', 'public', 'public/router.php'],
        [0 => ['file', 'NUL', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']],
        $pipes,
        $raiz,
        $env,
    );

    if (!is_resource($proc)) {
        throw new RuntimeException('php -S não subiu');
    }

    for ($i = 0; $i < 50; $i++) {
        $s = @fsockopen('127.0.0.1', $porta, $errno, $errstr, 0.1);
        if (is_resource($s)) {
            fclose($s);

            return [$proc, $porta];
        }
        usleep(100000);
    }

    throw new RuntimeException('php -S não respondeu na porta ' . $porta);
}

/**
 * GET com a senha dada (null = sem credencial). Devolve [status, cabeçalhos, corpo].
 *
 * @return array{0: int, 1: list<string>, 2: string}
 */
function pede(int $porta, string $caminho, ?string $senha): array
{
    $cabecalhos = $senha === null ? '' : 'Authorization: Basic ' . base64_encode('qualquer:' . $senha) . "\r\n";
    $corpo      = file_get_contents('http://127.0.0.1:' . $porta . $caminho, false, stream_context_create([
        'http' => ['header' => $cabecalhos, 'ignore_errors' => true, 'timeout' => 30],
    ]));
    $resposta = http_get_last_response_headers() ?? [];

    preg_match('#^HTTP/\S+ (\d{3})#', $resposta[0] ?? '', $m);

    return [(int) ($m[1] ?? 0), $resposta, is_string($corpo) ? $corpo : ''];
}

beforeAll(function () {
    $GLOBALS['auth_com']  = sobeServidor(AUTH_HTTP_TOKEN);
    $GLOBALS['auth_sem']  = sobeServidor('');
});

afterAll(function () {
    foreach (['auth_com', 'auth_sem'] as $k) {
        proc_terminate($GLOBALS[$k][0]);
        proc_close($GLOBALS[$k][0]);
        @unlink(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phporto_auth_http_' . $GLOBALS[$k][1] . '.sqlite');
    }
});

it('SEM CREDENCIAL é 401 com o pedido de Basic, nas telas e na API', function (string $caminho) {
    [$status, $cabecalhos] = pede($GLOBALS['auth_com'][1], $caminho, null);

    expect($status)->toBe(401)
        ->and($cabecalhos)->toContain('WWW-Authenticate: Basic realm="' . Auth::REALM . '"');
})->with(['/', '/wsl', '/win', '/config', '/api/executions']);

it('token certo passa o portão, nas telas e na API', function (string $caminho) {
    [$status] = pede($GLOBALS['auth_com'][1], $caminho, AUTH_HTTP_TOKEN);

    expect($status)->not->toBe(401)
        ->and($status)->not->toBe(503)
        ->and($status)->not->toBe(429);
})->with(['/', '/config', '/api/executions']);

it('SEM PHPORTO_AUTH_TOKEN a aplicação RECUSA SERVIR, mesmo com credencial', function (string $caminho, ?string $senha) {
    [$status, , $corpo] = pede($GLOBALS['auth_sem'][1], $caminho, $senha);

    expect($status)->toBe(503)
        ->and($corpo)->toContain('php token.php');
})->with([
    ['/', null],
    ['/', ''],
    ['/wsl', 'qualquer'],
    ['/api/executions', null],
    ['/api/executions', 'qualquer'],
]);
