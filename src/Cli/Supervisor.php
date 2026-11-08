<?php

declare(strict_types=1);

namespace App\Cli;

use Closure;

final class Supervisor
{
    public const POLL_US = 200_000;

    public const DELAYS_S = [1, 2, 4, 8, 16];

    public const STABLE_AFTER_S = 60;

    /**
     * @param array<string, list<string>> $children
     * @param Closure(): list<string> $tick
     * @param Closure(string, string, ?int): void $event
     * @param (Closure(string): ?list<string>)|null $respawn
     * @param list<int> $delays
     */
    public static function run(
        array $children,
        string $cwd,
        Closure $tick,
        Closure $event,
        ?Closure $respawn = null,
        array $delays = self::DELAYS_S,
        int $stableAfterS = self::STABLE_AFTER_S,
    ): int {
        $primary = array_key_first($children);

        if ($primary === null) {
            return Status::FAILURE;
        }

        $running = [];
        $startedAt = [];
        $attempts = [];
        $pending = [];

        foreach ($children as $name => $command) {
            $process = self::open($command, $cwd);

            if ($process === null) {
                if ($name === $primary) {
                    self::stopAll($running);

                    return Status::FAILURE;
                }

                $event($name, 'failed', null);

                continue;
            }

            $running[$name] = $process;
            $startedAt[$name] = microtime(true);
            $event($name, 'start', null);
        }

        $stop = false;

        if (function_exists('sapi_windows_set_ctrl_handler')) {
            sapi_windows_set_ctrl_handler(static function (int $signal) use (&$stop): void {
                $stop = true;
            });
        }

        $code = Status::OK;

        while (!$stop) {
            foreach ($running as $name => $process) {
                $info = proc_get_status($process);

                if ($info['running']) {
                    continue;
                }

                $exit = $info['exitcode'] < 0 ? null : $info['exitcode'];
                proc_close($process);
                unset($running[$name]);
                $event($name, 'exit', $exit);

                if ($respawn === null || ($name === $primary && $exit === 0)) {
                    if ($name === $primary) {
                        $code = $exit === null || $exit === 0 ? Status::OK : Status::FAILURE;
                        $stop = true;
                    }

                    continue;
                }

                if (microtime(true) - ($startedAt[$name] ?? 0.0) >= $stableAfterS) {
                    $attempts[$name] = 0;
                }

                $attempts[$name] = ($attempts[$name] ?? 0) + 1;

                if ($attempts[$name] > count($delays)) {
                    $event($name, 'gaveup', $attempts[$name] - 1);

                    if ($name === $primary) {
                        $code = Status::FAILURE;
                        $stop = true;
                    }

                    continue;
                }

                $pending[$name] = microtime(true) + $delays[$attempts[$name] - 1];
                $event($name, 'restart', $attempts[$name]);
            }

            if ($stop) {
                break;
            }

            foreach ($pending as $name => $due) {
                if (microtime(true) < $due || $respawn === null) {
                    continue;
                }

                unset($pending[$name]);
                $command = $respawn($name);
                $process = $command === null ? null : self::open($command, $cwd);

                if ($process === null) {
                    $event($name, 'gaveup', $attempts[$name] ?? 0);

                    if ($name === $primary) {
                        $code = Status::FAILURE;
                        $stop = true;
                    }

                    continue;
                }

                $running[$name] = $process;
                $startedAt[$name] = microtime(true);
                $event($name, 'start', null);
            }

            if ($stop || (!isset($running[$primary]) && !isset($pending[$primary]))) {
                break;
            }

            foreach ($tick() as $name) {
                if ($name === $primary) {
                    continue;
                }

                unset($pending[$name]);

                if (isset($running[$name])) {
                    proc_terminate($running[$name]);
                    proc_close($running[$name]);
                    unset($running[$name]);
                    $event($name, 'stopped', null);
                }
            }

            usleep(self::POLL_US);
        }

        self::stopAll($running);

        return $code;
    }

    /**
     * @param list<string> $command
     * @return resource|null
     */
    private static function open(array $command, string $cwd)
    {
        $process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $cwd);

        return is_resource($process) ? $process : null;
    }

    /**
     * @param array<string, resource> $running
     */
    private static function stopAll(array $running): void
    {
        foreach ($running as $process) {
            proc_terminate($process);
            proc_close($process);
        }
    }
}
