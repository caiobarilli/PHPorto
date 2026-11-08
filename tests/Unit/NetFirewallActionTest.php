<?php

declare(strict_types=1);

use App\Win\WinAction;

const FW_PHP = 'C:\php\php.exe';

it('net-firewall add valida e normaliza os parâmetros', function () {
    expect(WinAction::NetFirewall->validate([
        'SubAction' => 'add',
        'BindIp' => '192.168.1.20',
        'Port' => '4001',
        'Allow' => '192.168.1.0/24,10.0.0.8',
        'Program' => FW_PHP,
    ]))->toBe([
        'SubAction' => 'add',
        'BindIp' => '192.168.1.20',
        'Port' => 4001,
        'Allow' => '192.168.1.0/24,10.0.0.8',
        'Program' => FW_PHP,
    ]);
});

it('net-firewall remove não leva parâmetro nenhum além da subação', function () {
    expect(WinAction::NetFirewall->validate(['SubAction' => 'remove', 'BindIp' => '8.8.8.8']))->toBe(['SubAction' => 'remove']);
});

it('net-firewall recusa o que não é do formato', function (array $entrada) {
    $base = ['SubAction' => 'add', 'BindIp' => '192.168.1.20', 'Port' => '4001', 'Allow' => '192.168.1.0/24', 'Program' => FW_PHP];

    expect(fn () => WinAction::NetFirewall->validate(array_replace($base, $entrada)))->toThrow(InvalidArgumentException::class);
})->with([
    'subação' => [['SubAction' => 'open']],
    'bind público' => [['BindIp' => '8.8.8.8']],
    'bind loopback' => [['BindIp' => '127.0.0.1']],
    'bind qualquer' => [['BindIp' => '0.0.0.0']],
    'bind com máscara' => [['BindIp' => '192.168.1.0/24']],
    'porta zero' => [['Port' => '0']],
    'porta alta' => [['Port' => '65536']],
    'porta texto' => [['Port' => '40a1']],
    'allow pública' => [['Allow' => '192.168.1.0/24,8.8.8.8']],
    'allow qualquer' => [['Allow' => '0.0.0.0/0']],
    'allow vazia' => [['Allow' => '']],
    'programa sem php.exe' => [['Program' => 'C:\Windows\notepad.exe']],
    'programa relativo' => [['Program' => 'php.exe']],
    'programa de rede' => [['Program' => '\\srv\php\php.exe']],
    'programa com subida' => [['Program' => 'C:\..\php.exe']],
]);

it('net-firewall é sensível nas duas subações', function () {
    expect(WinAction::NetFirewall->isSensitive(['SubAction' => 'add']))->toBeTrue()
        ->and(WinAction::NetFirewall->isSensitive(['SubAction' => 'remove']))->toBeTrue();
});

it('o worker usa os mesmos formatos e a mesma lista de subações da ação net-firewall', function () {
    $worker = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Win/worker.ps1');

    expect(preg_match("/^    'net-firewall'\s*=\s*@\{\r?\n(.*?)\r?\n    \}/ms", $worker, $bloco))->toBe(1)
        ->and($bloco[1])->toContain("valores = @('add', 'remove')")
        ->and($bloco[1])->toContain("padrao = '" . WinAction::NET_FIREWALL_PROGRAM_PATTERN . "'")
        ->and($bloco[1])->toContain('max = ' . WinAction::NET_FIREWALL_PROGRAM_MAX);
});

it('a ação nunca monta regra com perfil ou endereço mais largos', function () {
    $script = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Win/actions/Invoke-NetFirewall.ps1');

    expect($script)->toContain('-Profile Private')
        ->and($script)->toContain('-LocalAddress $BindIp')
        ->and($script)->toContain('-RemoteAddress $remotos')
        ->and($script)->toContain('-Program $Program');
});

it('net-firewall e o estado limitam a allowlist a 16 entradas', function () {
    $dezesseis = implode(',', array_map(static fn (int $i): string => '10.0.0.' . $i, range(1, 16)));
    $dezessete = $dezesseis . ',10.0.0.17';
    $base = ['SubAction' => 'add', 'BindIp' => '192.168.1.20', 'Port' => '4001', 'Program' => FW_PHP];

    expect(WinAction::NetFirewall->validate($base + ['Allow' => $dezesseis])['Allow'])->toBe($dezesseis)
        ->and(fn () => WinAction::NetFirewall->validate($base + ['Allow' => $dezessete]))->toThrow(InvalidArgumentException::class)
        ->and(\App\Network\Manager::validate('192.168.1.20', 4001, explode(',', $dezessete)))->toContain('No máximo 16')
        ->and(\App\Network\Manager::validate('192.168.1.20', 4001, explode(',', $dezesseis)))->toBeNull()
        ->and(\App\Network\State::fromArray([
            'enabled' => true, 'bind_ip' => '192.168.1.20', 'port' => 4001,
            'allow' => explode(',', $dezessete), 'updated_at' => 'x',
        ]))->toBeNull();
});
