<?php

declare(strict_types=1);

namespace App\Php\C;

use InvalidArgumentException;

final class CCode
{
    public const MAX_CODE_BYTES = 65536;

    public const TIMEOUT_SECONDS = 10;

    public const MAX_OUTPUT_BYTES = 262144;

    public const ACTION = 'c-rodar';

    public const FIELD = 'codigo';

    public const SESSION_KEY = 'phporto_c';

    public const EXAMPLE = "#include <stdio.h>\n\nint main(void)\n{\n    printf(\"ola do C\\n\");\n    return 0;\n}\n";

    /**
     * @throws InvalidArgumentException
     */
    public static function validate(mixed $raw): string
    {
        if (!is_string($raw) || trim($raw) === '') {
            throw new InvalidArgumentException('Escreva um programa em C com main().');
        }

        if (str_contains($raw, "\0")) {
            throw new InvalidArgumentException('Código recusado: tem byte nulo.');
        }

        if (!mb_check_encoding($raw, 'UTF-8')) {
            throw new InvalidArgumentException('Código recusado: não é UTF-8 válido.');
        }

        $codigo = str_replace("\r\n", "\n", $raw);

        if (strlen($codigo) > self::MAX_CODE_BYTES) {
            throw new InvalidArgumentException(sprintf(
                'Código com %d bytes; o teto é %d.',
                strlen($codigo),
                self::MAX_CODE_BYTES,
            ));
        }

        return $codigo;
    }

    /**
     * @return list<string>
     */
    public static function arguments(string $php, TccStatus $tcc, string $codeFile): array
    {
        return [$php, '-d', 'display_errors=stderr', self::runner(), (string) $tcc->libtcc, Tcc::libDir($tcc->dir), $codeFile];
    }

    public static function runner(): string
    {
        return __DIR__ . DIRECTORY_SEPARATOR . 'runner.php';
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function execute(CCompiler $compiler, TccStatus $tcc, mixed $raw): CRun
    {
        if (!$tcc->installed()) {
            throw new InvalidArgumentException('Instale o Tiny C Compiler antes de rodar C.');
        }

        $codigo = self::validate($raw);
        $run    = $compiler->run($codigo, $tcc);

        return new CRun($codigo, $run->output, $run->exitCode, $run->timedOut, $run->durationMs, time());
    }
}
