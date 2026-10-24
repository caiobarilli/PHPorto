<?php

declare(strict_types=1);

namespace App\Http;

final class Front
{
    public const DEFAULT_PORT = 4001;

    public const HOSTS = ['127.0.0.1', 'localhost'];

    public const MISDIRECTED = 421;

    /**
     * @param array<array-key, mixed> $server
     */
    public static function port(array $server): int
    {
        $porta = $server['SERVER_PORT'] ?? null;

        if (is_int($porta) || (is_string($porta) && ctype_digit($porta))) {
            $porta = (int) $porta;

            if ($porta >= 1 && $porta <= 65535) {
                return $porta;
            }
        }

        return self::DEFAULT_PORT;
    }

    /**
     * @param array<array-key, mixed> $server
     * @return list<string>
     */
    public static function allowedHosts(array $server): array
    {
        $porta = self::port($server);

        return array_map(static fn (string $nome): string => $nome . ':' . $porta, self::HOSTS);
    }

    /**
     * @param array<array-key, mixed> $server
     */
    public static function hostAllowed(array $server): bool
    {
        $host = $server['HTTP_HOST'] ?? null;

        return is_string($host) && in_array($host, self::allowedHosts($server), true);
    }

    public static function apiOffBeforeAuth(string $path, bool $apiEnabled): bool
    {
        return !$apiEnabled && str_starts_with($path, '/api/');
    }

    /**
     * @param array<array-key, mixed> $server
     */
    public static function refusal(array $server, string $path, bool $apiEnabled): ?int
    {
        if (!self::hostAllowed($server)) {
            return self::MISDIRECTED;
        }

        return self::apiOffBeforeAuth($path, $apiEnabled) ? 404 : null;
    }

    public static function refuse(int $status): never
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo $status === self::MISDIRECTED ? "421 Host recusado\n" : $status . "\n";
        exit;
    }
}
