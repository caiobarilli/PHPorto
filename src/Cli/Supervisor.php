<?php

declare(strict_types=1);

namespace App\Cli;

use Closure;

final class Supervisor
{
    public const POLL_US = 200_000;

    /**
     * @param array<string, list<string>> $children
     * @param Closure(): list<string> $tick
     * @param Closure(string, string, ?int): void $event
     */
    public static function run(array $children, string $cwd, Closure $tick, Closure $event): int
    {
        $primary = array_key_first($children);

        if ($primary === null) {
            return Status::FAILURE;
        }

        $running = [];

        foreach ($children as $name => $command) {
            $process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $cwd);

            if (!is_resource($process)) {
                if ($name === $primary) {
                    self::stopAll($running);

                    return Status::FAILURE;
                }

                $event($name, 'failed', null);

                continue;
            }

            $running[$name] = $process;
            $event($name, 'start', null);
        }

        $stop = false;

        if (function_exists('sapi_windows_set_ctrl_handler')) {
            sapi_windows_set_ctrl_handler(static function (int $signal) use (&$stop): void {
                $stop = true;
            });
        }

        $code = Status::OK;

        while (true) {
            foreach ($running as $name => $process) {
                $info = proc_get_status($process);

                if ($info['running']) {
                    continue;
                }

                $exit = $info['exitcode'] < 0 ? null : $info['exitcode'];
                proc_close($process);
                unset($running[$name]);
                $event($name, 'exit', $exit);

                if ($name === $primary) {
                    $code = $exit === null || $exit === 0 ? Status::OK : Status::FAILURE;
                    $stop = true;
                }
            }

            if ($stop || !isset($running[$primary])) {
                break;
            }

            foreach ($tick() as $name) {
                if ($name !== $primary && isset($running[$name])) {
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
