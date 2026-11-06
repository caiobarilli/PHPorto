<?php

declare(strict_types=1);

use App\Http\AuthDecision;
use App\Http\AuthOutcome;
use App\Network\LoginAttempts;
use App\Network\LoginGate;

function networkLoginDir(): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phporto-login-limit-' . bin2hex(random_bytes(5));
    mkdir($dir);

    return $dir;
}

function networkLoginCleanup(string $dir): void
{
    foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($dir);
}

it('permite cinco erros, bloqueia a sexta tentativa e abre após quinze minutos', function () {
    $dir = networkLoginDir();
    $now = 1_000_000;
    $attempts = new LoginAttempts($dir, static function () use (&$now): int {
        return $now;
    });

    foreach (range(1, 5) as $i) {
        expect($attempts->retryAfter('192.168.1.50'))->toBe(0);
        $result = $attempts->after('192.168.1.50', new AuthDecision(AuthOutcome::Wrong), true);
        expect($result->outcome)->toBe(AuthOutcome::Wrong);
        $now++;
    }

    $blocked = $attempts->before('192.168.1.50');
    expect($blocked?->outcome)->toBe(AuthOutcome::Locked)
        ->and($blocked?->retryAfter)->toBe(LoginAttempts::WINDOW_SECONDS - 5);
    $result = $attempts->after('192.168.1.50', new AuthDecision(AuthOutcome::Allowed), true);
    expect($result->outcome)->toBe(AuthOutcome::Locked);

    $now += 895;
    expect($attempts->retryAfter('192.168.1.50'))->toBe(0)
        ->and($attempts->before('192.168.1.50'))->toBeNull();
    networkLoginCleanup($dir);
});

it('cada IP tem contador próprio e um acerto limpa as falhas', function () {
    $dir = networkLoginDir();
    $attempts = new LoginAttempts($dir, static fn (): int => 1_000_000);

    foreach (range(1, 4) as $_) {
        $attempts->after('192.168.1.50', new AuthDecision(AuthOutcome::Wrong), true);
        expect($attempts->retryAfter('192.168.1.50'))->toBe(0)
            ->and($attempts->retryAfter('192.168.1.51'))->toBe(0);
    }
    $attempts->after('192.168.1.50', new AuthDecision(AuthOutcome::Allowed), true);
    expect($attempts->retryAfter('192.168.1.50'))->toBe(0);
    networkLoginCleanup($dir);
});

it('guarda o hash do IP e recomeça com contador inválido', function () {
    $dir = networkLoginDir();
    $attempts = new LoginAttempts($dir, static fn (): int => 1_000_000);
    $attempts->after('192.168.1.50', new AuthDecision(AuthOutcome::Wrong), true);
    $raw = (string) file_get_contents($dir . DIRECTORY_SEPARATOR . LoginAttempts::FILE);
    expect($raw)->not->toContain('192.168.1.50')
        ->and($raw)->toContain(hash('sha256', '192.168.1.50'))
        ->and(json_decode($raw, true)['version'] ?? null)->toBe(1);
    file_put_contents($dir . DIRECTORY_SEPARATOR . LoginAttempts::FILE, '{"version":1,"ips":"quebrado"}');
    expect($attempts->retryAfter('192.168.1.50'))->toBe(0);
    networkLoginCleanup($dir);
});

it('o gate respeita o enabled, a allow e o listener configurados', function () {
    $network = loginNetworkFixture();
    $server = loginServerFixture();
    expect(LoginGate::appliesTo($server, $network))->toBeTrue()
        ->and(LoginGate::ip($server))->toBe('192.168.1.50')
        ->and(LoginGate::appliesTo(loginServerFixture(), loginNetworkFixture(false)))->toBeFalse()
        ->and(LoginGate::appliesTo(loginServerFixture(['REMOTE_ADDR' => '10.0.0.1']), $network))->toBeFalse()
        ->and(LoginGate::appliesTo(loginServerFixture(['SERVER_ADDR' => '192.168.1.21']), $network))->toBeFalse()
        ->and(LoginGate::appliesTo(loginServerFixture(['REMOTE_ADDR' => '127.0.0.1']), $network))->toBeFalse()
        ->and(LoginGate::appliesTo($server, null))->toBeFalse();
});

function loginNetworkFixture(bool $enabled = true): \App\Network\State
{
    return new \App\Network\State($enabled, '192.168.1.20', 4001, ['192.168.1.0/24'], false, 'agora');
}

function loginServerFixture(array $overrides = []): array
{
    return array_replace([
        'REMOTE_ADDR' => '192.168.1.50',
        'SERVER_ADDR' => '192.168.1.20',
        'SERVER_PORT' => '4001',
    ], $overrides);
}
