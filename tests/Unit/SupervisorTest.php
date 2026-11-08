<?php

declare(strict_types=1);

use App\Cli\Status;
use App\Cli\Supervisor;

/**
 * @param array<string, list<string>> $children
 * @param list<int> $delays
 * @return array{code: int, events: list<string>}
 */
function supRun(array $children, ?Closure $respawn, array $delays = [0, 0], ?Closure $tick = null, int $stable = 60): array
{
    $events = [];
    $code = Supervisor::run(
        $children,
        sys_get_temp_dir(),
        $tick ?? static fn (): array => [],
        static function (string $n, string $k, ?int $c) use (&$events): void {
            $events[] = $n . ':' . $k . ($c === null ? '' : ':' . $c);
        },
        $respawn,
        $delays,
        $stable,
    );

    return ['code' => $code, 'events' => $events];
}

function supPhp(string $codigo): array
{
    return [PHP_BINARY, '-r', $codigo];
}

it('um filho secundário que cai volta, e o loopback segue de pé', function () {
    $r = supRun(
        ['loopback' => supPhp('usleep(1500000);'), 'rede' => supPhp('exit(3);')],
        static fn (string $name): array => supPhp('sleep(10);'),
    );

    expect($r['code'])->toBe(Status::OK)
        ->and($r['events'])->toContain('rede:exit:3', 'rede:restart:1')
        ->and(count(array_keys($r['events'], 'rede:start', true)))->toBe(2)
        ->and($r['events'])->not->toContain('rede:gaveup:2');
});

it('o teto de tentativas faz o secundário desistir, sem derrubar o loopback', function () {
    $r = supRun(
        ['loopback' => supPhp('usleep(2500000);'), 'rede' => supPhp('exit(3);')],
        static fn (string $name): array => supPhp('exit(3);'),
        [0, 0],
    );

    expect($r['code'])->toBe(Status::OK)
        ->and($r['events'])->toContain('rede:restart:1', 'rede:restart:2', 'rede:gaveup:2')
        ->and($r['events'])->not->toContain('rede:restart:3');
});

it('o principal que cai sem parar esgota o teto e a saída é falha', function () {
    $r = supRun(
        ['loopback' => supPhp('exit(2);')],
        static fn (string $name): array => supPhp('exit(2);'),
        [0, 0, 0],
    );

    expect($r['code'])->toBe(Status::FAILURE)
        ->and($r['events'])->toContain('loopback:restart:3', 'loopback:gaveup:3');
});

it('quando quem decide não devolve comando, o secundário desiste na hora', function () {
    $r = supRun(
        ['loopback' => supPhp('usleep(1000000);'), 'rede' => supPhp('exit(3);')],
        static fn (string $name): ?array => null,
    );

    expect($r['code'])->toBe(Status::OK)
        ->and($r['events'])->toContain('rede:exit:3', 'rede:gaveup:1')
        ->and(count(array_keys($r['events'], 'rede:start', true)))->toBe(1);
});

it('desligar pelo tick cancela a volta do secundário', function () {
    $r = supRun(
        ['loopback' => supPhp('usleep(1500000);'), 'rede' => supPhp('exit(3);')],
        static fn (string $name): array => supPhp('usleep(5000000);'),
        [3],
        static fn (): array => ['rede'],
    );

    expect($r['code'])->toBe(Status::OK)
        ->and(count(array_keys($r['events'], 'rede:start', true)))->toBe(1)
        ->and($r['events'])->not->toContain('rede:gaveup:1');
});

it('um filho que vive o bastante zera as tentativas', function () {
    $r = supRun(
        ['loopback' => supPhp('usleep(2000000);'), 'rede' => supPhp('exit(3);')],
        static fn (string $name): array => supPhp('exit(3);'),
        [0, 0],
        null,
        0,
    );

    expect($r['events'])->not->toContain('rede:gaveup:2')
        ->and(count(array_keys($r['events'], 'rede:start', true)))->toBeGreaterThan(3);
});

it('sem quem decida a volta, o comportamento antigo vale: o secundário só cai', function () {
    $r = supRun(
        ['loopback' => supPhp('usleep(800000);'), 'rede' => supPhp('exit(3);')],
        null,
    );

    expect($r['code'])->toBe(Status::OK)
        ->and($r['events'])->toContain('rede:exit:3')
        ->and($r['events'])->not->toContain('rede:restart:1');
});
