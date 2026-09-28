<?php

declare(strict_types=1);

use App\Http\Auth;
use Tests\Support\PhpServer;

/*
 * O portão de token pelo php -S de verdade, na frente de todas as rotas.
 *
 * Só manda requisição sem credencial ou com o token certo: nenhuma das duas
 * escreve no contador de storage/, que é o desta máquina.
 */

const AUTH_HTTP_TOKEN = 'token-do-teste-http';

beforeAll(function () {
    $GLOBALS['auth_com'] = PhpServer::start(['PHPORTO_AUTH_TOKEN' => AUTH_HTTP_TOKEN]);
    $GLOBALS['auth_sem'] = PhpServer::start(['PHPORTO_AUTH_TOKEN' => '']);
});

afterAll(function () {
    $GLOBALS['auth_com']->stop();
    $GLOBALS['auth_sem']->stop();
});

it('SEM CREDENCIAL é 401 com o pedido de Basic, nas telas e na API', function (string $caminho) {
    [$status, $cabecalhos] = $GLOBALS['auth_com']->request('GET', $caminho, null);

    expect($status)->toBe(401)
        ->and($cabecalhos)->toContain('WWW-Authenticate: Basic realm="' . Auth::REALM . '"');
})->with(['/', '/wsl', '/win', '/config', '/api/executions']);

it('token certo passa o portão, nas telas e na API', function (string $caminho) {
    [$status] = $GLOBALS['auth_com']->request('GET', $caminho, AUTH_HTTP_TOKEN);

    expect($status)->not->toBe(401)
        ->and($status)->not->toBe(503)
        ->and($status)->not->toBe(429);
})->with(['/', '/config', '/api/executions']);

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
