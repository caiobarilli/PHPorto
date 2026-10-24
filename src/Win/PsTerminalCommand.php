<?php

declare(strict_types=1);

namespace App\Win;

use App\Domain\Execution;
use App\Domain\ExecutionKind;
use InvalidArgumentException;

final class PsTerminalCommand
{
    public const MAX_COMMAND_BYTES = 4096;

    public const TIMEOUT_SECONDS = 60;

    public const MAX_OUTPUT_BYTES = 262144;

    public const WIDTH = 200;

    public const EXECUTABLE = 'powershell.exe';

    public const RECORD_PREFIX = 'PS> ';

    public const ACTION = 'terminal';

    /**
     * @throws InvalidArgumentException
     */
    public static function validate(mixed $raw): string
    {
        if (!is_string($raw)) {
            throw new InvalidArgumentException('Digite um comando de PowerShell.');
        }

        if (str_contains($raw, "\0")) {
            throw new InvalidArgumentException('Comando recusado: tem byte nulo.');
        }

        if (!mb_check_encoding($raw, 'UTF-8')) {
            throw new InvalidArgumentException('Comando recusado: não é UTF-8 válido.');
        }

        $comando = trim($raw);

        if ($comando === '') {
            throw new InvalidArgumentException('Digite um comando de PowerShell.');
        }

        if (strlen($comando) > self::MAX_COMMAND_BYTES) {
            throw new InvalidArgumentException(sprintf(
                'Comando com %d bytes; o teto é %d.',
                strlen($comando),
                self::MAX_COMMAND_BYTES,
            ));
        }

        return $comando;
    }

    public static function script(string $command): string
    {
        $dado = base64_encode($command);
        $w    = self::WIDTH;

        return implode("\n", [
            "\$ProgressPreference = 'SilentlyContinue'",
            "\$ErrorActionPreference = 'Continue'",
            '$phportoUtf8 = New-Object System.Text.UTF8Encoding $false',
            'try { [Console]::OutputEncoding = $phportoUtf8 } catch { }',
            '$OutputEncoding = $phportoUtf8',
            "\$phportoCodigo = [System.Text.Encoding]::UTF8.GetString([System.Convert]::FromBase64String('{$dado}'))",
            'try {',
            '    $phportoBloco = [scriptblock]::Create($phportoCodigo)',
            '} catch {',
            '    $phportoFalha = $_.Exception',
            '    if ($null -ne $phportoFalha.InnerException) { $phportoFalha = $phportoFalha.InnerException }',
            '    Write-Output $phportoFalha.Message',
            '    exit 1',
            '}',
            '$phportoErros = $Error.Count',
            '$global:LASTEXITCODE = 0',
            ". \$phportoBloco *>&1 | Out-String -Stream -Width {$w}",
            'if ($global:LASTEXITCODE -is [int] -and $global:LASTEXITCODE -ne 0) { exit $global:LASTEXITCODE }',
            'if ($Error.Count -gt $phportoErros) { exit 1 }',
            'exit 0',
        ]) . "\n";
    }

    public static function encode(string $script): string
    {
        return base64_encode(mb_convert_encoding($script, 'UTF-16LE', 'UTF-8'));
    }

    /**
     * @return list<string>
     */
    public static function arguments(string $command, string $executable = self::EXECUTABLE): array
    {
        return [$executable, '-NoProfile', '-NonInteractive', '-EncodedCommand', self::encode(self::script($command))];
    }

    public static function record(string $command): string
    {
        return self::RECORD_PREFIX . $command;
    }

    public static function cleanStderr(string $stderr): string
    {
        if (!str_starts_with(ltrim($stderr), '#< CLIXML')) {
            return $stderr;
        }

        preg_match_all('#<S S="Error">(.*?)</S>#s', $stderr, $m);

        $texto = implode('', array_map(
            static fn (string $s): string => str_replace(['_x000D__x000A_', '_x000A_'], "\n", html_entity_decode($s, ENT_QUOTES | ENT_XML1, 'UTF-8')),
            $m[1],
        ));

        return trim($texto) === '' ? '' : $texto;
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function execute(PsTerminal $terminal, mixed $raw): Execution
    {
        $comando = self::validate($raw);
        $run     = $terminal->run($comando);

        return new Execution(
            command: self::record($comando),
            output: $run->output,
            exitCode: $run->exitCode,
            durationMs: $run->durationMs,
            kind: ExecutionKind::Windows,
            timedOut: $run->timedOut,
        );
    }
}
