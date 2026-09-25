<?php

declare(strict_types=1);

namespace App\Wsl;

use InvalidArgumentException;

/**
 * Os tetos do que a pessoa manda para o WSL, recusados no servidor.
 */
final class InputLimit
{
    /** Teto do texto de um comando, em bytes. */
    public const MAX_COMMAND_BYTES = 65536;

    /** Teto de cada caminho do card de anexos, em bytes. */
    public const MAX_PATH_BYTES = 4096;

    /**
     * Recusa o comando que passa do teto.
     *
     * Recebe o texto como veio do formulário ou da API.
     *
     * @throws InvalidArgumentException com a frase que vai para quem mandou
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
     * Recusa o caminho de anexo que passa do teto.
     *
     * Recebe o nome do campo, para a mensagem, e o caminho.
     *
     * @throws InvalidArgumentException com a frase que vai para quem mandou
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
