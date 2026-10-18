<?php

declare(strict_types=1);

namespace App\Wsl;

use App\Domain\OutputCap;
use RuntimeException;

final class Runner
{
    private const POLL_US = 80000;

    private const KILL_GRACE_US = 200000;

    /**
     * @param array{root: string, distro: string, timeout: int} $wsl
     */
    public function __construct(
        private readonly array $wsl,
        private readonly string $filesDir,
    ) {
    }

    /**
     * @param array<string, string> $extraEnv
     * @throws RuntimeException
     */
    public function run(string $scriptBody, array $extraEnv = []): RunResult
    {
        if ($this->wsl['root'] === '') {
            throw new RuntimeException(
                'PHPORTO_WSL_ROOT está vazio ou ausente no .env. Nada é executado: sem ele '
                . 'o comando rodaria no home do usuário do WSL, em silêncio.'
            );
        }

        $this->ensureDirectory($this->filesDir);

        $script  = $this->filesDir . DIRECTORY_SEPARATOR . 'cmd.sh';
        $outFile = $this->filesDir . DIRECTORY_SEPARATOR . 'stdout.tmp';
        $errFile = $this->filesDir . DIRECTORY_SEPARATOR . 'stderr.tmp';

        file_put_contents($script, ScriptBuilder::normalize($scriptBody));
        file_put_contents($outFile, '');
        file_put_contents($errFile, '');

        $cmdLine = 'wsl.exe -d ' . self::arg($this->wsl['distro'])
            . ' -- bash -l ' . self::arg(self::toWslPath($script));

        $descriptors = [
            0 => ['file', self::nullDevice(), 'r'],
            1 => ['file', $outFile, 'a'],
            2 => ['file', $errFile, 'a'],
        ];

        $vars = ['PHPORTO_WSL_ROOT' => $this->wsl['root']] + $extraEnv;

        $env             = getenv();
        $env['WSL_UTF8'] = '1';
        foreach ($vars as $k => $v) {
            $env[$k] = $v;
        }

        $inherited     = trim($env['WSLENV'] ?? '');
        $names         = implode(':', array_keys($vars));
        $env['WSLENV'] = $inherited === '' ? $names : $inherited . ':' . $names;

        $timeout = $this->wsl['timeout'];
        $started = microtime(true);

        $pipes = [];
        $proc  = @proc_open($cmdLine, $descriptors, $pipes, $this->filesDir, $env, ['bypass_shell' => true]);

        if (!is_resource($proc)) {
            return new RunResult("[phporto] falha ao iniciar wsl.exe\n", null, false, 0);
        }

        $exitCode = null;
        $timedOut = false;

        while (true) {
            $status = proc_get_status($proc);

            if ($status['running'] === false) {
                $exitCode = $status['exitcode'] < 0 ? null : $status['exitcode'];
                break;
            }

            if ((microtime(true) - $started) >= $timeout) {
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
                . sprintf('[phporto] TIMEOUT: %ds estourados, processo morto.', $timeout) . "\n";
        }

        @unlink($outFile);
        @unlink($errFile);

        return new RunResult($output, $exitCode, $timedOut, $durationMs, $truncated || $strayTruncated);
    }

    /**
     * @return array{0: string, 1: bool}
     */
    public static function readBounded(string $path, ?int $limit = null): array
    {
        $limit = $limit ?? OutputCap::MAX_OUTPUT_BYTES;

        [$bytes, $truncated, $tamanho] = OutputCap::read($path, $limit);

        $buffer = self::toUtf8($bytes);

        if ($truncated && $tamanho > 0) {
            $buffer = OutputCap::withNotice($buffer, $limit, $tamanho);
        }

        return [$buffer, $truncated];
    }

    public static function toWslPath(string $winPath): string
    {
        $p = str_replace('\\', '/', $winPath);

        if (preg_match('~^([A-Za-z]):(/.*)?$~', $p, $m) === 1) {
            $p = '/mnt/' . strtolower($m[1]) . ($m[2] ?? '');
        }

        return $p;
    }

    public static function toUtf8(string $s): string
    {
        if ($s === '') {
            return '';
        }

        $sample = substr($s, 0, 400);
        if (substr_count($sample, "\0") > strlen($sample) / 4) {
            $conv = @iconv('UTF-16LE', 'UTF-8//IGNORE', $s);
            if ($conv !== false) {
                $s = $conv;
            }
        }

        if (!mb_check_encoding($s, 'UTF-8')) {
            $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        }

        return str_replace("\r\n", "\n", $s);
    }

    private static function arg(string $value): string
    {
        return preg_match('/\s/', $value) === 1 ? '"' . $value . '"' : $value;
    }

    private static function nullDevice(): string
    {
        return self::isWindows() ? 'NUL' : '/dev/null';
    }

    private static function isWindows(): bool
    {
        return DIRECTORY_SEPARATOR !== '/';
    }

    /**
     * @param resource $proc
     */
    private static function kill($proc, int $pid): void
    {
        @proc_terminate($proc, 9);
        usleep(self::KILL_GRACE_US);

        $status = proc_get_status($proc);

        if ($status['running'] === true && $pid > 0 && self::isWindows()) {
            @exec('taskkill /F /T /PID ' . $pid);
        }
    }

    private function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new RuntimeException("Não foi possível criar a pasta de trabalho: {$dir}");
        }
    }
}
