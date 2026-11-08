<?php

declare(strict_types=1);

use App\Http\Front;
use App\Network\State;

function lanServer(array $overrides = []): array
{
    return array_replace([
        'REMOTE_ADDR' => '192.168.1.50',
        'SERVER_ADDR' => '192.168.1.20',
        'SERVER_PORT' => '4001',
        'HTTP_HOST' => '192.168.1.20:4001',
    ], $overrides);
}

function lanState(bool $enabled = true): State
{
    $state = State::fromArray([
        'enabled' => $enabled,
        'bind_ip' => '192.168.1.20',
        'port' => 4001,
        'allow' => ['192.168.1.0/24'],
        'updated_at' => '2026-11-06T12:00:00+00:00',
    ]);
    expect($state)->toBeInstanceOf(State::class);

    return $state;
}

it('a matriz do roteador recusa fora sem rede habilitada ou fora da allow', function (array $server, ?State $state, int $expected) {
    expect(Front::refusal($server, '/', true, $state))->toBe($expected)
        ->and(Front::refusal($server, '/api/executions', true, $state))->toBe($expected);
})->with([
    'estado fechado' => [lanServer(), lanState(false), 403],
    'estado ausente' => [lanServer(), null, 403],
    'fora da allow' => [lanServer(['REMOTE_ADDR' => '10.0.0.20']), lanState(), 403],
    'listener diferente' => [lanServer(['SERVER_ADDR' => '192.168.1.21']), lanState(), 403],
    'listener loopback' => [lanServer(['SERVER_ADDR' => '127.0.0.1']), lanState(), 403],
    'listener ausente' => [lanServer(['SERVER_ADDR' => null]), lanState(), 403],
    'porta do listener diferente' => [lanServer(['SERVER_PORT' => '4002']), lanState(), 421],
    'IPv6 mapeado não é IPv4 de LAN' => [lanServer(['REMOTE_ADDR' => '::ffff:192.168.1.50']), lanState(), 403],
    'público não está na faixa privada' => [lanServer(['REMOTE_ADDR' => '8.8.8.8']), lanState(), 403],
]);

it('a LAN permitida pelo listener configurado passa no roteador e no Host', function () {
    $server = lanServer();

    expect(Front::refusal($server, '/', true, lanState()))->toBeNull()
        ->and(Front::remoteAllowed($server, lanState()))->toBeTrue()
        ->and(Front::hostAllowed($server, lanState()))->toBeTrue();
});

it('o Host da LAN depende também do IP, listener e enable', function (array $server, ?State $state) {
    expect(Front::hostAllowed($server, $state))->toBeFalse()
        ->and(Front::refusal($server, '/', true, $state))->toBe(403);
})->with([
    'enabled false' => [lanServer(), lanState(false)],
    'fora allow' => [lanServer(['REMOTE_ADDR' => '10.0.0.10']), lanState()],
    'listener errado' => [lanServer(['SERVER_ADDR' => '192.168.1.21']), lanState()],
]);

it('um Host incorreto numa conexão LAN válida recebe 421', function (mixed $host) {
    expect(Front::hostAllowed(lanServer(['HTTP_HOST' => $host]), lanState()))->toBeFalse()
        ->and(Front::refusal(lanServer(['HTTP_HOST' => $host]), '/', true, lanState()))->toBe(421);
})->with([
    'localhost' => ['localhost:4001'],
    'sem porta' => ['192.168.1.20'],
    'outra porta' => ['192.168.1.20:4002'],
    'host público' => ['evil.example:4001'],
    'IPv6' => ['[::1]:4001'],
    'com espaço' => [' 192.168.1.20:4001'],
    'ausente' => [null],
]);

it('loopback continua sempre permitido com os Hosts de hoje', function (string $ip, ?State $state) {
    $server = [
        'HTTP_HOST' => 'localhost:4001',
        'SERVER_PORT' => '4001',
        'REMOTE_ADDR' => $ip,
        'SERVER_ADDR' => '192.168.1.20',
    ];

    expect(Front::isLoopback($server))->toBeTrue()
        ->and(Front::remoteAllowed($server, $state))->toBeTrue()
        ->and(Front::refusal($server, '/', true, $state))->toBeNull();
})->with([
    'IPv4 sem estado' => ['127.0.0.1', null],
    'IPv4 rede desligada' => ['127.5.6.7', lanState(false)],
    'IPv6' => ['::1', lanState(false)],
    'IPv4 mapeado' => ['::ffff:127.0.0.1', null],
]);

it('uma rede habilitada não libera Host de LAN para um pedido local', function () {
    $server = lanServer(['REMOTE_ADDR' => '127.0.0.1']);
    expect(Front::hostAllowed($server, lanState()))->toBeFalse()
        ->and(Front::refusal($server, '/', true, lanState()))->toBe(421);
});

it('sem SERVER_ADDR, o SERVER_NAME do php -S vale como endereço de escuta', function () {
    $server = lanServer(['SERVER_ADDR' => null, 'SERVER_NAME' => '192.168.1.20']);
    unset($server['SERVER_ADDR']);

    expect(Front::refusal($server, '/', true, lanState()))->toBeNull()
        ->and(lanState()->accepts($server))->toBeTrue()
        ->and(State::listenerAddress($server))->toBe('192.168.1.20');
});

it('o SERVER_NAME diferente do bind_ip ou que não é IPv4 é recusado', function (array $extra, int $expected) {
    $server = lanServer($extra);
    unset($server['SERVER_ADDR']);

    expect(Front::refusal($server, '/', true, lanState()))->toBe($expected);
})->with([
    'outro IP' => [['SERVER_NAME' => '192.168.1.21'], 403],
    'nome de host' => [['SERVER_NAME' => 'localhost'], 403],
    'vazio' => [['SERVER_NAME' => ''], 403],
    'IPv6' => [['SERVER_NAME' => 'fe80::1'], 403],
    'ausente' => [[], 403],
]);

it('o SERVER_ADDR, quando existe, tem precedência sobre o SERVER_NAME', function () {
    $server = lanServer(['SERVER_ADDR' => '192.168.1.21', 'SERVER_NAME' => '192.168.1.20']);

    expect(lanState()->accepts($server))->toBeFalse();
});
