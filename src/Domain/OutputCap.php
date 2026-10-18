<?php

declare(strict_types=1);

namespace App\Domain;

final class OutputCap
{
    public const MAX_OUTPUT_BYTES = 1048576;

    private const CHUNK_BYTES = 65536;

    /**
     * @return array{0: string, 1: bool, 2: int}
     */
    public static function read(string $path, int $limit = self::MAX_OUTPUT_BYTES): array
    {
        if ($limit <= 0) {
            return ['', true, 0];
        }

        if (!is_file($path)) {
            return ['', false, 0];
        }

        $tamanho = filesize($path);

        if (!is_int($tamanho)) {
            return ['', false, 0];
        }

        $fh = @fopen($path, 'rb');

        if ($fh === false) {
            return ['', false, 0];
        }

        $buffer = '';

        while (true) {
            $falta = $limit - strlen($buffer);

            if ($falta < 1) {
                break;
            }

            $chunk = fread($fh, min(self::CHUNK_BYTES, $falta));

            if (!is_string($chunk) || $chunk === '') {
                break;
            }

            $buffer .= $chunk;
        }

        fclose($fh);

        return [$buffer, $tamanho > $limit, $tamanho];
    }

    public static function withNotice(string $text, int $limit, int $size): string
    {
        return self::withNewline($text) . sprintf(
            '[phporto] SAÍDA CORTADA no teto de %d bytes (o comando gerou %d).',
            $limit,
            $size
        ) . "\n";
    }

    public static function withNewline(string $text): string
    {
        return $text === '' || str_ends_with($text, "\n") ? $text : $text . "\n";
    }
}
