<?php

declare(strict_types=1);

namespace App\Win;

use App\Domain\OutputCap;
use ValueError;

final class PsTerminalProcess implements PsTerminal
{
    public const DISK_CAP_BYTES = 16777216;

    private const POLL_US = 80000;

    private const KILL_GRACE_US = 200000;

    public function __construct(
        private readonly string $filesDir,
        private readonly int $timeoutSeconds = PsTerminalCommand::TIMEOUT_SECONDS,
        private readonly int $maxOutputBytes = PsTerminalCommand::MAX_OUTPUT_BYTES,
        private readonly string $executable = PsTerminalCommand::EXECUTABLE,
        private readonly ?string $workDir = null,
    ) {
    }

    public function run(string $command): PsResult
    {
        return $this->runArgv(PsTerminalCommand::arguments($command, $this->executable));
    }

    /**
     * @param list<string> $argv
     */
    public function runArgv(array $argv): PsResult
    {
        $base    = $this->filesDir . DIRECTORY_SEPARATOR . 'ps-terminal-' . bin2hex(random_bytes(6));
        $outFile = $base . '.out';
        $errFile = $base . '.err';

        if (@file_put_contents($outFile, '') === false || @file_put_contents($errFile, '') === false) {
            @unlink($outFile);

            return new PsResult("[phporto] sem como gravar a saída em {$this->filesDir}\n", null, false, 0);
        }

        $descriptors = [
            0 => ['file', self::nullDevice(), 'r'],
            1 => ['file', $outFile, 'a'],
            2 => ['file', $errFile, 'a'],
        ];

        $started = microtime(true);
        $pipes   = [];
        set_error_handler(static fn (): bool => true);

        try {
            $proc = proc_open($argv, $descriptors, $pipes, $this->cwd());
        } catch (ValueError) {
            $proc = false;
        } finally {
            restore_error_handler();
        }

        if (!is_resource($proc)) {
            @unlink($outFile);
            @unlink($errFile);

            return new PsResult('[phporto] falha ao iniciar ' . ($argv[0] ?? '?') . "\n", null, false, 0);
        }

        $exitCode = null;
        $timedOut = false;
        $estourou = false;

        while (true) {
            $status = proc_get_status($proc);

            if ($status['running'] === false) {
                $exitCode = $status['exitcode'] < 0 ? null : $status['exitcode'];
                break;
            }

            if ((microtime(true) - $started) >= $this->timeoutSeconds) {
                $timedOut = true;
                self::kill($proc, $status['pid']);
                break;
            }

            clearstatcache(true, $outFile);
            if ((int) @filesize($outFile) > self::DISK_CAP_BYTES) {
                $estourou = true;
                self::kill($proc, $status['pid']);
                break;
            }

            usleep(self::POLL_US);
        }

        proc_close($proc);
        $durationMs = (int) round((microtime(true) - $started) * 1000);
        clearstatcache();

        [$output, $truncated] = $this->readBounded($outFile, $this->maxOutputBytes);

        clearstatcache(true, $errFile);
        if ((int) @filesize($errFile) > 0) {
            [$erro, $erroCortado] = $this->readBounded($errFile, $this->maxOutputBytes);
            $erro                 = PsTerminalCommand::cleanStderr($erro);

            if (trim($erro) !== '') {
                $sobra = $this->maxOutputBytes - strlen($output);

                if (strlen($erro) > $sobra) {
                    $erro        = mb_strcut($erro, 0, max(0, $sobra), 'UTF-8');
                    $erroCortado = true;
                }

                $output = OutputCap::withNewline($output) . $erro;
                $truncated = $truncated || $erroCortado;
            }
        }

        if ($estourou) {
            $output = OutputCap::withNewline($output)
                . sprintf('[phporto] a saída passou de %d bytes em disco; a árvore de processos foi encerrada.', self::DISK_CAP_BYTES) . "\n";
        }

        if ($timedOut) {
            $output = OutputCap::withNewline($output)
                . sprintf('[phporto] TIMEOUT: %ds estourados; a árvore de processos foi encerrada.', $this->timeoutSeconds) . "\n";
        }

        @unlink($outFile);
        @unlink($errFile);

        return new PsResult($output, $estourou ? null : $exitCode, $timedOut, $durationMs, $truncated || $estourou);
    }

    /**
     * @return array{0: string, 1: bool}
     */
    private function readBounded(string $path, int $limit): array
    {
        [$bytes, $truncated, $tamanho] = OutputCap::read($path, $limit);

        $texto = self::toUtf8($bytes);

        if ($truncated && $tamanho > 0) {
            $texto = OutputCap::withNotice($texto, $limit, $tamanho);
        }

        return [$texto, $truncated];
    }

    public static function toUtf8(string $bytes): string
    {
        $texto = PsScriptBuilder::stripBom($bytes);

        if (!mb_check_encoding($texto, 'UTF-8')) {
            $texto = mb_scrub($texto, 'UTF-8');
        }

        return str_replace("\r\n", "\n", $texto);
    }

    private function cwd(): string
    {
        if ($this->workDir !== null && is_dir($this->workDir)) {
            return $this->workDir;
        }

        $home = getenv('USERPROFILE');

        return is_string($home) && $home !== '' && is_dir($home) ? $home : $this->filesDir;
    }

    private static function nullDevice(): string
    {
        return DIRECTORY_SEPARATOR !== '/' ? 'NUL' : '/dev/null';
    }

    /**
     * @param resource $proc
     */
    private static function kill($proc, int $pid): void
    {
        if ($pid > 0 && DIRECTORY_SEPARATOR !== '/') {
            $nulo  = self::nullDevice();
            $pipes = [];
            $tk    = @proc_open(
                ['taskkill', '/F', '/T', '/PID', (string) $pid],
                [0 => ['file', $nulo, 'r'], 1 => ['file', $nulo, 'w'], 2 => ['file', $nulo, 'w']],
                $pipes,
            );

            if (is_resource($tk)) {
                proc_close($tk);
            }
        }

        @proc_terminate($proc, 9);
        usleep(self::KILL_GRACE_US);
    }
}
