<?php

declare(strict_types=1);

namespace App\Win;

use RuntimeException;

final class PsScriptBuilder
{
    public const BOM = "\xEF\xBB\xBF";

    /**
     * @throws RuntimeException
     */
    public static function write(string $path, string $body): void
    {
        $dir = dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new RuntimeException("Não foi possível criar a pasta do script: {$dir}");
        }

        if (@file_put_contents($path, self::normalize($body)) === false) {
            throw new RuntimeException("Não foi possível gravar o script: {$path}");
        }
    }

    public static function normalize(string $body): string
    {
        $body = self::stripBom($body);

        $body = str_replace(["\r\n", "\r"], "\n", $body);

        if ($body !== '' && !str_ends_with($body, "\n")) {
            $body .= "\n";
        }

        return self::BOM . str_replace("\n", "\r\n", $body);
    }

    public static function stripBom(string $text): string
    {
        return str_starts_with($text, self::BOM) ? substr($text, 3) : $text;
    }

    /**
     * @throws RuntimeException
     */
    public static function literal(string $value): string
    {
        if (str_contains($value, "\0")) {
            throw new RuntimeException('Valor com byte nulo recusado.');
        }

        return "'" . strtr($value, [
            "'"        => "''",
            "\u{2018}" => "\u{2018}\u{2018}",
            "\u{2019}" => "\u{2019}\u{2019}",
            "\u{201A}" => "\u{201A}\u{201A}",
            "\u{201B}" => "\u{201B}\u{201B}",
        ]) . "'";
    }
}
