<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * O teto de saída de uma execução, comum ao motor do WSL e ao do Windows.
 */
final class OutputCap
{
    /** Teto de saída de UMA execução, em bytes. */
    public const MAX_OUTPUT_BYTES = 1048576;

    /** Tamanho de cada pedaço lido do arquivo de saída. */
    private const CHUNK_BYTES = 65536;

    /**
     * Lê um arquivo até o teto, em pedaços, sem tratar encoding.
     *
     * Recebe o caminho e o teto em bytes. Devolve os bytes lidos, se cortou e
     * o tamanho do arquivo. Arquivo ausente ou ilegível volta vazio e sem
     * corte; teto zero ou negativo volta vazio e com corte.
     *
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

    /**
     * Acrescenta ao texto a linha que avisa o corte.
     *
     * Recebe o texto já cortado, o teto e o tamanho original. Devolve o texto
     * terminado em quebra de linha, seguido do aviso.
     */
    public static function withNotice(string $text, int $limit, int $size): string
    {
        return self::withNewline($text) . sprintf(
            '[phporto] SAÍDA CORTADA no teto de %d bytes (o comando gerou %d).',
            $limit,
            $size
        ) . "\n";
    }

    /** Devolve o texto terminado em quebra de linha, a menos que esteja vazio. */
    public static function withNewline(string $text): string
    {
        return $text === '' || str_ends_with($text, "\n") ? $text : $text . "\n";
    }
}
