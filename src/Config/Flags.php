<?php

declare(strict_types=1);

namespace App\Config;

final class Flags
{
    public const WRITABLE = ['api_enabled'];

    public static function path(): string
    {
        return dirname(__DIR__, 2) . '/storage/flags.json';
    }

    /**
     * @return array<string, bool>
     */
    public static function all(): array
    {
        $raw = @file_get_contents(self::path());

        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $data = json_decode($raw, true);

        if (!is_array($data)) {
            return [];
        }

        $out = [];

        foreach (self::WRITABLE as $key) {
            if (isset($data[$key]) && is_bool($data[$key])) {
                $out[$key] = $data[$key];
            }
        }

        return $out;
    }

    public static function get(string $key, bool $default): bool
    {
        return self::all()[$key] ?? $default;
    }

    public static function set(string $key, bool $value): bool
    {
        if (!in_array($key, self::WRITABLE, true)) {
            return false;
        }

        $data       = self::all();
        $data[$key] = $value;

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $dir  = dirname(self::path());

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }

        return @file_put_contents(self::path(), $json . "\n", LOCK_EX) !== false;
    }

    public static function reset(): bool
    {
        $path = self::path();

        if (!is_file($path)) {
            return true;
        }

        return @unlink($path);
    }
}
