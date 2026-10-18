<?php

declare(strict_types=1);

namespace App\Wsl;

use InvalidArgumentException;

final class InputLimit
{
    public const MAX_COMMAND_BYTES = 65536;

    public const MAX_PATH_BYTES = 4096;

    /**
     * @throws InvalidArgumentException
     */
    public static function command(string $text): void
    {
        if (strlen($text) > self::MAX_COMMAND_BYTES) {
            throw new InvalidArgumentException(sprintf(
                'O comando passou do teto de %d bytes (tem %d).',
                self::MAX_COMMAND_BYTES,
                strlen($text)
            ));
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function path(string $field, string $path): void
    {
        if (strlen($path) > self::MAX_PATH_BYTES) {
            throw new InvalidArgumentException(sprintf(
                '%s passou do teto de %d bytes (tem %d).',
                $field,
                self::MAX_PATH_BYTES,
                strlen($path)
            ));
        }
    }
}
