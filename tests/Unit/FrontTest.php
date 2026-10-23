<?php

declare(strict_types=1);

use App\Http\Auth;
use App\Http\Front;

it('aceita só 127.0.0.1 e localhost na porta servida', function (string $host) {
    expect(Front::hostAllowed(['HTTP_HOST' => $host, 'SERVER_PORT' => '4001']))->toBeTrue()
        ->and(Front::refusal(['HTTP_HOST' => $host, 'SERVER_PORT' => '4001'], '/win', true))->toBeNull();
})->with([['127.0.0.1:4001'], ['localhost:4001']]);

it('HOST DE FORA é recusado com 421', function (mixed $host) {
    $server = ['HTTP_HOST' => $host, 'SERVER_PORT' => '4001'];

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
    expect(Front::refusal(['SERVER_PORT' => '4001'], '/', true))->toBe(421);
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
    $ok = ['HTTP_HOST' => '127.0.0.1:4001', 'SERVER_PORT' => '4001'];

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
        expect(Front::refusal(['HTTP_HOST' => 'evil:4001', 'SERVER_PORT' => '4001'], '/api/executions', true))->toBe(421);
    }

    $fonte = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Http/Front.php');

    expect($fonte)->not->toMatch('/\bAuth\b|Auth::|COUNTER_FILE|Login::|file_put_contents/');
});
