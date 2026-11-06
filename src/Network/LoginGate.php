<?php

declare(strict_types=1);

namespace App\Network;

use App\Http\Front;

final readonly class LoginGate
{
    /**
     * @param array<array-key, mixed> $server
     */
    public static function appliesTo(array $server, ?State $network): bool
    {
        return !Front::isLoopback($server) && $network?->accepts($server) === true;
    }

    /**
     * @param array<array-key, mixed> $server
     */
    public static function ip(array $server): ?string
    {
        $ip = $server['REMOTE_ADDR'] ?? null;

        return is_string($ip) ? $ip : null;
    }
}
