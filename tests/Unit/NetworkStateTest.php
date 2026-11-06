<?php

declare(strict_types=1);

use App\Network\Ipv4;
use App\Network\State;
use App\Network\Store;

function networkFiles(string $name): string
{
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phporto-network-' . $name . '-' . bin2hex(random_bytes(5));
}

function networkState(array $overrides = []): State
{
    $state = State::fromArray(array_replace([
        'enabled' => true,
        'bind_ip' => '192.168.1.20',
        'port' => 4001,
        'allow' => ['192.168.1.0/24', '10.0.0.8'],
        'autostart' => false,
        'updated_at' => '2026-11-06T12:00:00+00:00',
    ], $overrides));
    expect($state)->toBeInstanceOf(State::class);

    return $state;
}

it('aceita CIDRs privados e IPs privados, sem ampliar para outras faixas', function (string $rule, string $ip, bool $expected) {
    expect(Ipv4::isPrivateRule($rule))->toBeTrue()
        ->and(Ipv4::matches($ip, $rule))->toBe($expected);
})->with([
    '10/8 contém seu broadcast' => ['10.0.0.0/8', '10.255.255.255', true],
    '172/12 no início' => ['172.16.0.0/12', '172.16.0.1', true],
    '172/12 no fim' => ['172.16.0.0/12', '172.31.255.255', true],
    '172/12 não inclui 172.32' => ['172.16.0.0/12', '172.32.0.1', false],
    '192.168/16' => ['192.168.0.0/16', '192.168.255.255', true],
    'IP único privado' => ['10.0.0.8', '10.0.0.8', true],
    'IP único diferente' => ['10.0.0.8', '10.0.0.9', false],
    'base fora da rede ainda fica privada' => ['192.168.1.17/24', '192.168.1.200', true],
]);

it('recusa faixa pública, zero barra zero, loopback, IPv6 e regra malformada', function (string $rule) {
    expect(Ipv4::isPrivateRule($rule))->toBeFalse();
})->with([
    'internet inteira' => ['0.0.0.0/0'],
    'público' => ['8.8.8.8'],
    'público com prefixo' => ['8.8.8.0/24'],
    'rede privada com endereço público' => ['10.0.0.1/7'],
    'loopback IP' => ['127.0.0.1'],
    'loopback CIDR' => ['127.0.0.0/8'],
    'IPv6' => ['::1'],
    'malformado' => ['192.168.1.0/abc'],
    'muitas barras' => ['192.168.1.0/24/1'],
    'negativo' => ['192.168.1.0/-1'],
    'prefixo maior' => ['192.168.1.0/33'],
    'texto' => ['qualquer-coisa'],
    'vazio' => [''],
]);

it('lê o estado válido com todos os campos', function () {
    $dir = networkFiles('valid');
    mkdir($dir);
    $path = $dir . DIRECTORY_SEPARATOR . Store::FILE;
    file_put_contents($path, (string) json_encode([
        'enabled' => true,
        'bind_ip' => '192.168.1.20',
        'port' => 4001,
        'allow' => ['192.168.1.0/24'],
        'autostart' => true,
        'updated_at' => '2026-11-06T12:00:00+00:00',
    ]));

    $store = new Store($dir);
    $state = $store->load();
    expect($state->enabled)->toBeTrue()
        ->and($state->bindIp)->toBe('192.168.1.20')
        ->and($state->port)->toBe(4001)
        ->and($state->allow)->toBe(['192.168.1.0/24'])
        ->and($state->autostart)->toBeTrue()
        ->and($state->updatedAt)->toBe('2026-11-06T12:00:00+00:00')
        ->and($store->path())->toBe($path);

    unlink($path);
    rmdir($dir);
});

it('arquivo ausente, ilegível ou malformado fecha a rede', function (?string $raw) {
    $dir = networkFiles('closed');
    mkdir($dir);

    if ($raw !== null) {
        file_put_contents($dir . DIRECTORY_SEPARATOR . Store::FILE, $raw);
    }

    $state = (new Store($dir))->load();
    expect($state->enabled)->toBeFalse()
        ->and($state->allow)->toBe([])
        ->and($state->updatedAt)->toBeNull();

    if (is_file($dir . DIRECTORY_SEPARATOR . Store::FILE)) {
        unlink($dir . DIRECTORY_SEPARATOR . Store::FILE);
    }
    rmdir($dir);
})->with([
    'sem arquivo' => [null],
    'JSON quebrado' => ['{"enabled":true'],
    'array em vez de objeto' => ['[]'],
    'string' => ['null'],
]);

it('qualquer campo inválido fecha a rede inteira', function (array $overrides) {
    $dir = networkFiles('invalid');
    mkdir($dir);
    $data = array_replace([
        'enabled' => true,
        'bind_ip' => '192.168.1.20',
        'port' => 4001,
        'allow' => ['192.168.1.0/24'],
        'autostart' => false,
        'updated_at' => '2026-11-06T12:00:00+00:00',
    ], $overrides);
    file_put_contents($dir . DIRECTORY_SEPARATOR . Store::FILE, (string) json_encode($data));

    $state = (new Store($dir))->load();
    expect($state->enabled)->toBeFalse()
        ->and($state->allow)->toBe([])
        ->and($state->updatedAt)->toBeNull();

    unlink($dir . DIRECTORY_SEPARATOR . Store::FILE);
    rmdir($dir);
})->with([
    'bind ausente' => [['bind_ip' => null]],
    'bind IPv6' => [['bind_ip' => '::1']],
    'bind público' => [['bind_ip' => '8.8.8.8']],
    'porta texto' => [['port' => '4001']],
    'porta fora' => [['port' => 65536]],
    'allow string' => [['allow' => '192.168.1.0/24']],
    'allow vazia' => [['allow' => []]],
    'allow pública' => [['allow' => ['8.8.8.8']]],
    'allow com valor inválido' => [['allow' => ['192.168.1.0/24', 1]]],
    'autostart inteiro' => [['autostart' => 1]],
    'enabled string' => [['enabled' => 'true']],
    'data ausente' => [['updated_at' => null]],
]);

it('só o CLI grava um estado novo e as operações web não ligam a rede', function () {
    $dir = networkFiles('writes');
    mkdir($dir);
    $agora = '2026-11-06T15:00:00+00:00';
    $store = new Store($dir, static function () use (&$agora): string {
        return $agora;
    });

    expect(fn () => $store->setAutostartFromWeb(true))->toThrow(RuntimeException::class)
        ->and($store->disableFromWeb()->enabled)->toBeFalse()
        ->and(is_file($store->path()))->toBeFalse();

    $created = $store->writeForCli(true, '192.168.1.20', 4001, ['192.168.1.0/24'], false);
    expect($created->enabled)->toBeTrue()
        ->and($created->updatedAt)->toBe($agora)
        ->and(is_file($store->path()))->toBeTrue();

    $agora = '2026-11-06T15:01:00+00:00';
    $auto = $store->setAutostartFromWeb(true);
    expect($auto->autostart)->toBeTrue()
        ->and($auto->enabled)->toBeTrue()
        ->and($auto->updatedAt)->toBe($agora);

    $agora = '2026-11-06T15:02:00+00:00';
    $disabled = $store->disableFromWeb();
    expect($disabled->enabled)->toBeFalse()
        ->and($disabled->autostart)->toBeFalse()
        ->and($disabled->bindIp)->toBe('192.168.1.20')
        ->and($disabled->allow)->toBe(['192.168.1.0/24']);

    expect(json_encode(json_decode((string) file_get_contents($store->path()), true)))->not->toBeFalse();
    unlink($store->path());
    if (is_file($store->path() . '.lock')) {
        unlink($store->path() . '.lock');
    }
    rmdir($dir);
});

it('o CLI recusa estado fora das regras sem criar arquivo', function () {
    $dir = networkFiles('cli-invalid');
    mkdir($dir);
    $store = new Store($dir);

    expect(fn () => $store->writeForCli(true, '127.0.0.1', 4001, ['0.0.0.0/0'], false))
        ->toThrow(InvalidArgumentException::class)
        ->and(is_file($store->path()))->toBeFalse();
    rmdir($dir);
});
