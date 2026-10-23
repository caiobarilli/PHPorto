<?php

declare(strict_types=1);

namespace App\Wsl;

final class Distro
{
    private const LIST_TIMEOUT_S = 10;

    private const ARGS_REGISTERED = '-l -q';

    private const ARGS_RUNNING = '-l --running -q';

    public function __construct(
        private readonly string $distro,
    ) {
    }

    public function name(): string
    {
        return $this->distro;
    }

    public function status(): DistroStatus
    {
        $list = $this->registered();

        if ($list === null) {
            return DistroStatus::WslMissing;
        }

        if ($this->distro === '') {
            return DistroStatus::DistroMissing;
        }

        return self::contains($list, $this->distro) ? DistroStatus::Ok : DistroStatus::DistroMissing;
    }

    public function isAvailable(): bool
    {
        return $this->status() === DistroStatus::Ok;
    }

    public function isRunning(): ?bool
    {
        $list = $this->listing(self::ARGS_RUNNING);

        return $list === null ? null : ($this->distro !== '' && self::contains($list, $this->distro));
    }

    /**
     * @param list<string> $names
     */
    public static function contains(array $names, string $distro): bool
    {
        foreach ($names as $name) {
            if (strcasecmp($name, $distro) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>|null
     */
    public function registered(): ?array
    {
        return $this->listing(self::ARGS_REGISTERED);
    }

    /**
     * @return list<string>|null
     */
    public function running(): ?array
    {
        return $this->listing(self::ARGS_RUNNING);
    }

    /**
     * @return list<string>|null
     */
    private function listing(string $args): ?array
    {
        $descriptors = [
            0 => ['file', DIRECTORY_SEPARATOR !== '/' ? 'NUL' : '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = getenv();
        $env['WSL_UTF8'] = '1';

        $pipes = [];
        $proc = @proc_open('wsl.exe ' . $args, $descriptors, $pipes, null, $env, ['bypass_shell' => true]);

        if (!is_resource($proc)) {
            return null;
        }

        $stdout  = '';
        $started = microtime(true);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        while (true) {
            $chunk = fread($pipes[1], 8192);
            if (is_string($chunk)) {
                $stdout .= $chunk;
            }

            $status = proc_get_status($proc);
            if ($status['running'] === false) {
                $rest = stream_get_contents($pipes[1]);
                if (is_string($rest)) {
                    $stdout .= $rest;
                }
                break;
            }

            if ((microtime(true) - $started) >= self::LIST_TIMEOUT_S) {
                @proc_terminate($proc, 9);
                break;
            }

            usleep(10000);
        }

        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $exit = proc_close($proc);

        if ($exit !== 0 && trim($stdout) === '') {
            return null;
        }

        return self::parse($stdout);
    }

    /**
     * @return list<string>
     */
    public static function parse(string $raw): array
    {
        $raw   = Runner::toUtf8($raw);
        $raw   = str_replace("\0", '', $raw);
        $names = [];

        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $names[] = $line;
            }
        }

        return $names;
    }
}
