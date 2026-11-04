<?php

declare(strict_types=1);

namespace App\Http;

final class Https
{
    /** @param array<array-key, mixed> $server */
    public static function active(array $server): bool
    {
        $https = $server['HTTPS'] ?? '';

        return is_string($https) && $https !== '' && strtolower($https) !== 'off';
    }
}
