<?php

declare(strict_types=1);

namespace App\Win;

use App\Domain\OutputCap;
use RuntimeException;

final class PsRunner
{
    private const POLL_US = 80000;

    private const KILL_GRACE_US = 200000;

    public function __construct(
        private readonly string $filesDir,
    ) {
    }

    public function run(string $scriptPath, int $timeoutSeconds): PsResult
    {
        if (!is_file($scriptPath)) {
            throw new RuntimeException("Script não encontrado: {$scriptPath}");
        }

        $outFile = $this->filesDir . DIRECTORY_SEPARATOR . 'ps-stdout.tmp';
        $errFile = $this->filesDir . DIRECTORY_SEPARATOR . 'ps-stderr.tmp';

        file_put_contents($outFile, '');
        file_put_contents($errFile, '');

        $cmdLine = 'powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -File '
            . self::arg($scriptPath);

        $descriptors = [
            0 => ['file', self::nullDevice(), 'r'],
            1 => ['file', $outFile, 'a'],
            2 => ['file', $errFile, 'a'],
        ];

        $started = microtime(true);
        $pipes   = [];
        $proc    = @proc_open($cmdLine, $descriptors, $pipes, $this->filesDir, null, ['bypass_shell' => true]);

        if (!is_resource($proc)) {
            return new PsResult("[phporto] falha ao iniciar powershell.exe\n", null, false, 0);
        }

        $exitCode = null;
        $timedOut = false;

        while (true) {
            $status = proc_get_status($proc);

            if ($status['running'] === false) {
                $exitCode = $status['exitcode'] < 0 ? null : $status['exitcode'];
                break;
            }

            if ((microtime(true) - $started) >= $timeoutSeconds) {
                $timedOut = true;
                self::kill($proc, $status['pid']);
                break;
            }

            usleep(self::POLL_US);
        }

        proc_close($proc);
        $durationMs = (int) round((microtime(true) - $started) * 1000);

        [$output, $truncated] = self::readBounded($outFile);

        [$stray, $strayTruncated] = self::readBounded($errFile, max(0, OutputCap::MAX_OUTPUT_BYTES - strlen($output)));
        if (trim($stray) !== '') {
            $output .= $stray;
        }

        if ($timedOut) {
            $output = OutputCap::withNewline($output)
                . sprintf('[phporto] TIMEOUT: %ds estourados, processo morto.', $timeoutSeconds) . "\n";
        }

        @unlink($outFile);
        @unlink($errFile);

        return new PsResult($output, $exitCode, $timedOut, $durationMs, $truncated || $strayTruncated);
    }

    /**
     * @return array{0: string, 1: bool}
     */
    public static function readBounded(string $path, ?int $limit = null): array
    {
        $limit = $limit ?? OutputCap::MAX_OUTPUT_BYTES;

        [$bytes, $truncated, $tamanho] = OutputCap::read($path, $limit);

        $buffer = self::toUtf8(PsScriptBuilder::stripBom($bytes));

        if ($truncated && $tamanho > 0) {
            $buffer = OutputCap::withNotice($buffer, $limit, $tamanho);
        }

        return [$buffer, $truncated];
    }

    public static function toUtf8(string $s): string
    {
        if ($s === '') {
            return '';
        }

        if (!mb_check_encoding($s, 'UTF-8')) {
            $conv = @iconv('Windows-1252', 'UTF-8//IGNORE', $s);
            $s    = $conv === false ? mb_convert_encoding($s, 'UTF-8', 'UTF-8') : $conv;
        }

        return str_replace("\r\n", "\n", $s);
    }

    private static function arg(string $value): string
    {
        return preg_match('/\s/', $value) === 1 ? '"' . $value . '"' : $value;
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
        @proc_terminate($proc, 9);
        usleep(self::KILL_GRACE_US);

        $status = proc_get_status($proc);

        if ($status['running'] === true && $pid > 0 && DIRECTORY_SEPARATOR !== '/') {
            @exec('taskkill /F /T /PID ' . $pid);
        }
    }
}
