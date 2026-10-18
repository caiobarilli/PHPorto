<?php

declare(strict_types=1);

namespace App\Config;

/**
 * @phpstan-type AppConfig array{
 *     db_provider: string,
 *     cors_origin: string,
 *     mongo: array{uri: string, database: string, collection: string},
 *     mysql: array{host: string, port: string, database: string, user: string, password: string, table: string},
 *     sqlite: array{path: string, table: string},
 *     wsl: array{root: string, distro: string, timeout: int},
 *     winutil: array{timeout: int},
 *     tz: string,
 *     dashboard_enabled: bool,
 *     api_enabled: bool,
 *     auth_token: string
 * }
 */
final class Config
{
    public const MIN_TIMEOUT = 30;

    public const MIN_WINUTIL_TIMEOUT = 60;

    /**
     * @return AppConfig
     */
    public static function load(): array
    {
        return [
            'db_provider' => self::env('DB_PROVIDER', 'sqlite'),
            'cors_origin' => self::env('CORS_ORIGIN', 'http://127.0.0.1:4001'),
            'mongo' => [
                'uri'        => self::env('MONGODB_URI', ''),
                'database'   => self::env('MONGO_DB_NAME', ''),
                'collection' => self::env('MONGO_COLLECTION', 'executions'),
            ],
            'mysql' => [
                'host'     => self::env('MYSQL_HOST', 'localhost'),
                'port'     => self::env('MYSQL_PORT', '3306'),
                'database' => self::env('MYSQL_DATABASE', ''),
                'user'     => self::env('MYSQL_USER', ''),
                'password' => self::env('MYSQL_PASSWORD', ''),
                'table'    => self::env('MYSQL_TABLE', 'executions'),
            ],
            'sqlite' => [
                'path'  => self::sqlitePath(),
                'table' => self::env('SQLITE_TABLE', 'executions'),
            ],
            'wsl' => [
                'root'    => self::env('PHPORTO_WSL_ROOT', ''),
                'distro'  => self::env('PHPORTO_DISTRO', 'Debian'),
                'timeout' => self::timeout(),
            ],
            'winutil' => [
                'timeout' => self::winutilTimeout(),
            ],
            'tz'                => self::timezone(),
            'dashboard_enabled' => self::bool('PHPORTO_DASHBOARD_ENABLED', true),
            'api_enabled'       => Flags::get(
                'api_enabled',
                self::bool('PHPORTO_API_ENABLED', false)
            ),
            'auth_token'        => self::env('PHPORTO_AUTH_TOKEN', ''),
        ];
    }

    private static function timeout(): int
    {
        $raw = (int) self::env('PHPORTO_TIMEOUT', '120');

        return max(self::MIN_TIMEOUT, $raw);
    }

    private static function winutilTimeout(): int
    {
        $raw = (int) self::env('PHPORTO_WINUTIL_TIMEOUT', '600');

        return max(self::MIN_WINUTIL_TIMEOUT, $raw);
    }

    private static function timezone(): string
    {
        $tz = self::env('PHPORTO_TZ', 'America/Sao_Paulo');

        return in_array($tz, timezone_identifiers_list(), true) ? $tz : 'America/Sao_Paulo';
    }

    private static function sqlitePath(): string
    {
        return self::resolvePath(self::env('SQLITE_PATH', 'storage/database.sqlite'), dirname(__DIR__, 2));
    }

    public static function resolvePath(string $path, string $root): string
    {
        $isAbsolute = $path !== ''
            && ($path[0] === '/' || $path[0] === '\\' || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1);

        return $isAbsolute ? $path : $root . '/' . $path;
    }

    public static function apiEnabledFromEnv(): bool
    {
        return self::bool('PHPORTO_API_ENABLED', false);
    }

    private static function bool(string $key, bool $default): bool
    {
        $value = strtolower(self::env($key, ''));

        if ($value === '') {
            return $default;
        }

        if (in_array($value, ['false', '0', 'no', 'off'], true)) {
            return false;
        }

        if (in_array($value, ['true', '1', 'yes', 'on'], true)) {
            return true;
        }

        return $default;
    }

    private static function env(string $key, string $default = ''): string
    {
        $value = $_ENV[$key] ?? getenv($key);

        if (!is_string($value) || $value === '') {
            return $default;
        }

        return $value;
    }
}
