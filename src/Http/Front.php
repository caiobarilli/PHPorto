<?php

declare(strict_types=1);

namespace App\Http;

use App\Network\State;

final class Front
{
    public const DEFAULT_PORT = 4001;

    public const HOSTS = ['127.0.0.1', 'localhost'];

    public const MISDIRECTED = 421;

    public const FORBIDDEN = 403;

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
    public static function hostAllowed(array $server, ?State $network = null): bool
    {
        $host = $server['HTTP_HOST'] ?? null;

        if (!is_string($host)) {
            return false;
        }

        if (!self::isLoopback($server)) {
            return $network?->accepts($server) === true && self::port($server) === $network->port && hash_equals($network->host(), $host);
        }

        return in_array($host, self::allowedHosts($server), true);
    }

    /**
     * @param array<array-key, mixed> $server
     */
    public static function remoteAllowed(array $server, ?State $network = null): bool
    {
        if (self::isLoopback($server)) {
            return true;
        }

        return $network?->accepts($server) === true;
    }

    /**
     * @param array<array-key, mixed> $server
     */
    public static function isLoopback(array $server): bool
    {
        $ip = $server['REMOTE_ADDR'] ?? null;

        if (!is_string($ip)) {
            return false;
        }

        $bin = @inet_pton($ip);

        if ($bin === false) {
            return false;
        }

        if (strlen($bin) === 16 && str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff")) {
            $bin = substr($bin, 12);
        }

        if (strlen($bin) === 4) {
            return $bin[0] === "\x7f";
        }

        return $bin === str_repeat("\0", 15) . "\x01";
    }

    public static function apiOffBeforeAuth(string $path, bool $apiEnabled): bool
    {
        return !$apiEnabled && str_starts_with($path, '/api/');
    }

    /**
     * @param array<array-key, mixed> $server
     */
    public static function refusal(array $server, string $path, bool $apiEnabled, ?State $network = null): ?int
    {
        if (!self::remoteAllowed($server, $network)) {
            return self::FORBIDDEN;
        }

        if (!self::hostAllowed($server, $network)) {
            return self::MISDIRECTED;
        }

        return self::apiOffBeforeAuth($path, $apiEnabled) ? 404 : null;
    }

    public static function refuse(int $status): never
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo match ($status) {
            self::MISDIRECTED => "421 Host recusado\n",
            self::FORBIDDEN   => "403 Acesso recusado\n",
            default           => $status . "\n",
        };
        exit;
    }
}
