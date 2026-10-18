<?php

declare(strict_types=1);

namespace App\Config;

final class EnvFile
{
    public static function value(string $contents, string $key): ?string
    {
        foreach (preg_split('/\R/', $contents) ?: [] as $linha) {
            if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*=(.*)$/', $linha, $m) === 1) {
                return trim(trim($m[1]), '"\'');
            }
        }

        return null;
    }

    public static function with(string $contents, string $key, string $value): string
    {
        $eol    = str_contains($contents, "\r\n") ? "\r\n" : "\n";
        $padrao = '/^[ \t]*' . preg_quote($key, '/') . '[ \t]*=[^\r\n]*/m';

        if (preg_match($padrao, $contents) === 1) {
            return (string) preg_replace($padrao, $key . '=' . $value, $contents, 1);
        }

        $separador = $contents === '' || str_ends_with($contents, "\n") ? '' : $eol;

        return $contents . $separador . $key . '=' . $value . $eol;
    }
}
