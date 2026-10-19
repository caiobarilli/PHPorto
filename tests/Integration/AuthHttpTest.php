<?php

declare(strict_types=1);

use App\Http\Auth;
use App\Http\Login;
use Tests\Support\PhpServer;

const AUTH_HTTP_TOKEN = 'token-do-teste-http';

beforeAll(function () {
    $GLOBALS['auth_com']      = PhpServer::start(['PHPORTO_AUTH_TOKEN' => AUTH_HTTP_TOKEN]);
    $GLOBALS['auth_sem']      = PhpServer::start(['PHPORTO_AUTH_TOKEN' => '']);
    $GLOBALS['auth_bloqueio'] = PhpServer::start(['PHPORTO_AUTH_TOKEN' => AUTH_HTTP_TOKEN]);
    $GLOBALS['auth_cookie']   = PhpServer::start(['PHPORTO_AUTH_TOKEN' => AUTH_HTTP_TOKEN]);
    $GLOBALS['auth_falso']    = PhpServer::start(['PHPORTO_AUTH_TOKEN' => AUTH_HTTP_TOKEN]);
});

afterAll(function () {
    $GLOBALS['auth_com']->stop();
    $GLOBALS['auth_sem']->stop();
    $GLOBALS['auth_bloqueio']->stop();
    $GLOBALS['auth_cookie']->stop();
    $GLOBALS['auth_falso']->stop();
});

/**
 * @param list<string> $cabecalhos
 * @return array<string, string>
 */
function cookiesDe(array $cabecalhos): array
{
    $cookies = [];

    foreach ($cabecalhos as $linha) {
        if (preg_match('/^Set-Cookie:\s*([^=;]+)=([^;]*)/i', $linha, $m) === 1) {
            $cookies[$m[1]] = $m[2];
        }
    }

    return $cookies;
}

/** @param list<string> $cabecalhos */
function setCookieDe(array $cabecalhos, string $nome): string
{
    foreach ($cabecalhos as $linha) {
        if (stripos($linha, 'Set-Cookie: ' . $nome . '=') === 0) {
            return $linha;
        }
    }

    return '';
}

/** @param list<string> $cabecalhos */
function semDesafio(array $cabecalhos): bool
{
    return preg_grep('/^WWW-Authenticate:/i', $cabecalhos) === [];
}

/** @return array{0: string, 1: string} */
function abrirFormulario(PhpServer $servidor, string $caminho): array
{
    [$status, $cabecalhos, $corpo] = $servidor->request('GET', $caminho, null);

    expect($status)->toBe(401);
    preg_match('/name="phporto_csrf" value="([0-9a-f]+)"/', $corpo, $m);

    return [cookiesDe($cabecalhos)['phporto_session'] ?? '', $m[1] ?? ''];
}

/** @return array{0: int, 1: list<string>, 2: string} */
function entrar(PhpServer $servidor, string $caminho, string $token, array $extras = []): array
{
    [$sessao, $csrf] = abrirFormulario($servidor, $caminho);

    return $servidor->request(
        'POST',
        $caminho,
        null,
        ['phporto_csrf' => $csrf, Login::FIELD => $token],
        ['Cookie: phporto_session=' . $sessao, ...$extras],
    );
}

it('SEM COOKIE as telas respondem 401 com o formulário, e SEM WWW-Authenticate', function (string $caminho) {
    [$status, $cabecalhos, $corpo] = $GLOBALS['auth_com']->request('GET', $caminho, null);

    expect($status)->toBe(401)
        ->and(semDesafio($cabecalhos))->toBeTrue()
        ->and($cabecalhos)->toContain('Content-Type: text/html; charset=utf-8')
        ->and($corpo)->toContain('type="password"')
        ->and($corpo)->toContain('name="' . Login::FIELD . '"')
        ->and($corpo)->toContain('name="phporto_csrf"')
        ->and($corpo)->toContain('Enviar')
        ->and($corpo)->toContain('action="' . $caminho . '"');
})->with(['/', '/wsl', '/win', '/config', '/hyperv']);

it('o Basic com o token certo NÃO abre as telas: elas só aceitam o cookie', function () {
    [$status] = $GLOBALS['auth_com']->request('GET', '/config', AUTH_HTTP_TOKEN);

    expect($status)->toBe(401);
});

it('SEM CREDENCIAL a API é 401 em texto, sem WWW-Authenticate', function () {
    [$status, $cabecalhos, $corpo] = $GLOBALS['auth_com']->request('GET', '/api/executions', null);

    expect($status)->toBe(401)
        ->and(semDesafio($cabecalhos))->toBeTrue()
        ->and($corpo)->toContain('curl -u :TOKEN')
        ->and($corpo)->not->toContain('<form');
});

it('a API continua aceitando o token no Basic, com qualquer usuário', function () {
    [$status] = $GLOBALS['auth_com']->request('GET', '/api/executions', AUTH_HTTP_TOKEN);

    expect($status)->not->toBe(401)
        ->and($status)->not->toBe(503)
        ->and($status)->not->toBe(429);
});

it('TOKEN CERTO no formulário: 303 para a tela pedida e cookie HttpOnly, SameSite=Strict, sem Secure', function (string $caminho) {
    [$status, $cabecalhos] = entrar($GLOBALS['auth_cookie'], $caminho, AUTH_HTTP_TOKEN);
    $linha = setCookieDe($cabecalhos, Auth::COOKIE);

    expect($status)->toBe(303)
        ->and($cabecalhos)->toContain('Location: ' . $caminho)
        ->and($linha)->not->toBe('')
        ->and($linha)->toContain('HttpOnly')
        ->and($linha)->toContain('SameSite=Strict')
        ->and($linha)->toContain('path=/')
        ->and(stripos($linha, 'secure'))->toBeFalse()
        ->and($linha)->not->toContain(AUTH_HTTP_TOKEN);
})->with(['/', '/config', '/wsl']);

it('o cookie emitido abre as telas na navegação seguinte', function () {
    [, $cabecalhos] = entrar($GLOBALS['auth_cookie'], '/config', AUTH_HTTP_TOKEN);
    $cookie = cookiesDe($cabecalhos)[Auth::COOKIE];

    foreach (['/', '/config'] as $caminho) {
        [$status, , $corpo] = $GLOBALS['auth_cookie']->request('GET', $caminho, null, [], ['Cookie: ' . Auth::COOKIE . '=' . $cookie]);

        expect($status)->toBe(200)
            ->and($corpo)->not->toContain('name="' . Login::FIELD . '"');
    }
});

it('o retorno do login não sai da origem', function () {
    [$status, $cabecalhos] = entrar($GLOBALS['auth_cookie'], '//exemplo.invalid', AUTH_HTTP_TOKEN);

    expect($status)->toBe(303)
        ->and($cabecalhos)->toContain('Location: /');
});

it('formulário sem o token CSRF não entra, nem com o token certo', function () {
    [$status, $cabecalhos, $corpo] = $GLOBALS['auth_cookie']->request('POST', '/config', null, [Login::FIELD => AUTH_HTTP_TOKEN]);

    expect($status)->toBe(401)
        ->and(setCookieDe($cabecalhos, Auth::COOKIE))->toBe('')
        ->and($corpo)->toContain('O formulário venceu');
});

it('POST de outro site não entra, nem com o token certo', function () {
    [$status, $cabecalhos] = entrar($GLOBALS['auth_cookie'], '/config', AUTH_HTTP_TOKEN, ['Sec-Fetch-Site: cross-site']);

    expect($status)->toBe(401)
        ->and(setCookieDe($cabecalhos, Auth::COOKIE))->toBe('');
});

it('TOKEN ERRADO devolve o formulário com aviso neutro, o quinto erro bloqueia com 429, e o bloqueio vale para telas e API', function () {
    foreach (range(1, Auth::MAX_FAILURES - 1) as $_) {
        [$status, $cabecalhos, $corpo] = entrar($GLOBALS['auth_bloqueio'], '/config', 'errado');

        expect($status)->toBe(401)
            ->and(semDesafio($cabecalhos))->toBeTrue()
            ->and($corpo)->toContain('Não deu para entrar. Confira o token.')
            ->and($corpo)->toContain('name="' . Login::FIELD . '"')
            ->and(setCookieDe($cabecalhos, Auth::COOKIE))->toBe('');
    }

    [$status, $cabecalhos, $corpo] = entrar($GLOBALS['auth_bloqueio'], '/config', 'errado');

    expect($status)->toBe(429)
        ->and($cabecalhos)->toContain('Retry-After: ' . Auth::LOCK_SECONDS)
        ->and($corpo)->toContain('bloqueado por mais 15 min 00 s')
        ->and($corpo)->not->toContain('name="' . Login::FIELD . '"');

    [$telas] = $GLOBALS['auth_bloqueio']->request('GET', '/', null);
    [$api]   = $GLOBALS['auth_bloqueio']->request('GET', '/api/executions', AUTH_HTTP_TOKEN);
    [$post]  = $GLOBALS['auth_bloqueio']->request('POST', '/', null, [Login::FIELD => AUTH_HTTP_TOKEN]);

    expect($telas)->toBe(429)
        ->and($api)->toBe(429)
        ->and($post)->toBe(429);
});

it('COOKIE FALSO devolve o formulário, apaga o cookie e conta como erro', function () {
    foreach (range(1, Auth::MAX_FAILURES - 1) as $_) {
        [$status, $cabecalhos, $corpo] = $GLOBALS['auth_falso']->request('GET', '/config', null, [], ['Cookie: ' . Auth::COOKIE . '=1.' . str_repeat('a', 64)]);

        expect($status)->toBe(401)
            ->and($corpo)->toContain('name="' . Login::FIELD . '"')
            ->and($corpo)->toContain('não vale mais')
            ->and(setCookieDe($cabecalhos, Auth::COOKIE))->toContain('expires=');
    }

    [$status] = $GLOBALS['auth_falso']->request('GET', '/config', null, [], ['Cookie: ' . Auth::COOKIE . '=lixo']);

    expect($status)->toBe(429);
});

it('SEM PHPORTO_AUTH_TOKEN a aplicação RECUSA SERVIR, mesmo com credencial', function (string $caminho, ?string $senha) {
    [$status, , $corpo] = $GLOBALS['auth_sem']->request('GET', $caminho, $senha);

    expect($status)->toBe(503)
        ->and($corpo)->toContain('php token.php');
})->with([
    ['/', null],
    ['/', ''],
    ['/wsl', 'qualquer'],
    ['/api/executions', null],
    ['/api/executions', 'qualquer'],
]);

it('SEM PHPORTO_AUTH_TOKEN o formulário também recusa, com 503', function () {
    [$status, , $corpo] = $GLOBALS['auth_sem']->request('POST', '/config', null, [Login::FIELD => 'qualquer']);

    expect($status)->toBe(503)
        ->and($corpo)->toContain('php token.php');
});
