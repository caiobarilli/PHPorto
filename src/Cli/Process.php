<?php

declare(strict_types=1);

namespace App\Cli;

final class Process
{
    /**
     * @param list<string> $command
     */
    public static function foreground(array $command, string $cwd): int
    {
        $process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $cwd);

        if (!is_resource($process)) {
            return Status::FAILURE;
        }

        $stop = false;
        $handler = static function (int $event) use (&$stop): void {
            $stop = true;
        };

        if (function_exists('sapi_windows_set_ctrl_handler')) {
            sapi_windows_set_ctrl_handler($handler);
        }

        $code = Status::OK;

        while (true) {
            $info = proc_get_status($process);

            if (!$info['running']) {
                $code = $info['exitcode'] === 0 || $info['exitcode'] === -1 ? Status::OK : Status::FAILURE;

                break;
            }

            if ($stop) {
                proc_terminate($process);

                break;
            }

            usleep(200_000);
        }

        proc_close($process);

        return $code;
    }
}
