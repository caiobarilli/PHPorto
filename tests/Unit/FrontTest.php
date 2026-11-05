<?php

declare(strict_types=1);

use App\Http\Auth;
use App\Http\Front;

it('aceita só 127.0.0.1 e localhost na porta servida', function (string $host) {
    expect(Front::hostAllowed(['HTTP_HOST' => $host, 'SERVER_PORT' => '4001', 'REMOTE_ADDR' => '127.0.0.1']))->toBeTrue()
        ->and(Front::refusal(['HTTP_HOST' => $host, 'SERVER_PORT' => '4001', 'REMOTE_ADDR' => '127.0.0.1'], '/win', true))->toBeNull();
})->with([['127.0.0.1:4001'], ['localhost:4001']]);

it('HOST DE FORA é recusado com 421', function (mixed $host) {
    $server = ['HTTP_HOST' => $host, 'SERVER_PORT' => '4001', 'REMOTE_ADDR' => '127.0.0.1'];

    expect(Front::hostAllowed($server))->toBeFalse()
        ->and(Front::refusal($server, '/', true))->toBe(421)
        ->and(Front::refusal($server, '/api/executions', true))->toBe(421);
})->with([
    'outro nome'       => ['evil:4001'],
    'rebinding'        => ['phporto.example:4001'],
    'sem porta'        => ['127.0.0.1'],
    'localhost puro'   => ['localhost'],
    'outra porta'      => ['127.0.0.1:4002'],
    'maiúsculas'       => ['LOCALHOST:4001'],
    'ponto no fim'     => ['localhost.:4001'],
    'ipv6'             => ['[::1]:4001'],
    'com espaço'       => [' 127.0.0.1:4001'],
    'usuário embutido' => ['x@127.0.0.1:4001'],
    'vazio'            => [''],
    'ausente'          => [null],
    'lista'            => [['127.0.0.1:4001']],
]);

it('sem Host nenhum também é recusado', function () {
    expect(Front::refusal(['SERVER_PORT' => '4001', 'REMOTE_ADDR' => '127.0.0.1'], '/', true))->toBe(421);
});

it('a porta vem do servidor, e cai em 4001 quando não vem', function () {
    expect(Front::port(['SERVER_PORT' => '5123']))->toBe(5123)
        ->and(Front::port(['SERVER_PORT' => 5123]))->toBe(5123)
        ->and(Front::port([]))->toBe(4001)
        ->and(Front::port(['SERVER_PORT' => 'abc']))->toBe(4001)
        ->and(Front::port(['SERVER_PORT' => '0']))->toBe(4001)
        ->and(Front::port(['SERVER_PORT' => '70000']))->toBe(4001)
        ->and(Front::allowedHosts(['SERVER_PORT' => '5123']))->toBe(['127.0.0.1:5123', 'localhost:5123'])
        ->and(Front::hostAllowed(['HTTP_HOST' => '127.0.0.1:4001', 'SERVER_PORT' => '5123']))->toBeFalse();
});

it('API desligada responde 404 antes do Basic, e não chega ao contador', function () {
    $ok = ['HTTP_HOST' => '127.0.0.1:4001', 'SERVER_PORT' => '4001', 'REMOTE_ADDR' => '127.0.0.1'];

    expect(Front::refusal($ok, '/api/executions', false))->toBe(404)
        ->and(Front::refusal($ok, '/api/executions', true))->toBeNull()
        ->and(Front::refusal($ok, '/win', false))->toBeNull()
        ->and(Front::apiOffBeforeAuth('/apiX', false))->toBeFalse();
});

it('O PORTÃO DA FRENTE RODA ANTES DA AUTENTICAÇÃO no index.php', function () {
    $index  = (string) file_get_contents(dirname(__DIR__, 2) . '/public/index.php');
    $frente = strpos($index, 'Front::refusal($_SERVER');
    $auth   = strpos($index, 'new Auth(');
    $guard  = strpos($index, 'Login::guard(');

    expect($frente)->not->toBeFalse()
        ->and($auth)->not->toBeFalse()
        ->and($frente < $auth && $frente < $guard)->toBeTrue();
});

it('HOST DE FORA NÃO MEXE NO CONTADOR: a recusa não conhece o Auth', function () {
    foreach (range(1, Auth::MAX_FAILURES * 2) as $_) {
        expect(Front::refusal(['HTTP_HOST' => 'evil:4001', 'SERVER_PORT' => '4001', 'REMOTE_ADDR' => '127.0.0.1'], '/api/executions', true))->toBe(421);
    }

    $fonte = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Http/Front.php');

    expect($fonte)->not->toMatch('/\bAuth\b|Auth::|COUNTER_FILE|Login::|file_put_contents/');
});

it('conexão do loopback passa', function (string $ip) {
    $server = ['HTTP_HOST' => 'localhost:4001', 'SERVER_PORT' => '4001', 'REMOTE_ADDR' => $ip];

    expect(Front::remoteAllowed($server))->toBeTrue()
        ->and(Front::refusal($server, '/', true))->toBeNull()
        ->and(Front::refusal($server, '/api/executions', true))->toBeNull();
})->with([['127.0.0.1'], ['127.5.6.7'], ['::1'], ['::ffff:127.0.0.1'], ['0:0:0:0:0:0:0:1']]);

it('CONEXÃO DE FORA recebe 403, mesmo com Host: localhost', function (mixed $ip) {
    $server = ['HTTP_HOST' => 'localhost:4001', 'SERVER_PORT' => '4001', 'REMOTE_ADDR' => $ip];

    expect(Front::remoteAllowed($server))->toBeFalse()
        ->and(Front::refusal($server, '/', true))->toBe(403)
        ->and(Front::refusal($server, '/win', true))->toBe(403);
})->with([
    'rede local'      => ['192.168.0.10'],
    'rede 10'         => ['10.0.0.5'],
    'ipv6 público'    => ['2001:db8::1'],
    'ipv4 mapeado'    => ['::ffff:192.168.0.10'],
    'ipv4 compatível' => ['::127.0.0.1'],
    'tudo zero'       => ['0.0.0.0'],
    'lixo'            => ['localhost'],
    'vazio'           => [''],
    'lista'           => [['127.0.0.1']],
    'ausente'         => [null],
]);

it('A /api/ DE FORA recebe 403 antes do token, mesmo com o token certo', function () {
    $server = [
        'HTTP_HOST'     => 'localhost:4001',
        'SERVER_PORT'   => '4001',
        'REMOTE_ADDR'   => '192.168.0.10',
        'PHP_AUTH_USER' => 'x',
        'PHP_AUTH_PW'   => 'token-certo',
    ];

    expect(Front::refusal($server, '/api/executions', true))->toBe(403)
        ->and(Front::refusal($server, '/api/executions', false))->toBe(403);
});

it('o 403 vem antes do 421: de fora, com Host errado, recebe 403', function () {
    expect(Front::refusal(['HTTP_HOST' => 'evil:4001', 'SERVER_PORT' => '4001', 'REMOTE_ADDR' => '10.0.0.5'], '/', true))->toBe(403);
});
