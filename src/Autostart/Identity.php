<?php

declare(strict_types=1);

namespace App\Autostart;

use Closure;
use Throwable;

final readonly class Identity
{
    public const SID_PATTERN = '\AS-1-5-21-[0-9]{1,10}-[0-9]{1,10}-[0-9]{1,10}-[0-9]{1,10}\z';

    public function __construct(public string $name, public string $sid)
    {
    }

    /**
     * @param (Closure(): ?string)|null $whoami
     */
    public static function current(?Closure $whoami = null): ?self
    {
        $output = ($whoami ?? self::runWhoami(...))();

        return $output === null ? null : self::parse($output);
    }

    public static function parse(string $output): ?self
    {
        $row = str_getcsv(trim($output), ',', '"', '');

        if (count($row) !== 2) {
            return null;
        }

        $name = trim((string) $row[0]);
        $sid = trim((string) $row[1]);

        if ($name === '' || !self::validSid($sid)) {
            return null;
        }

        return new self($name, $sid);
    }

    public static function validSid(string $sid): bool
    {
        return preg_match('/' . self::SID_PATTERN . '/', $sid) === 1;
    }

    private static function runWhoami(): ?string
    {
        try {
            $nulo = DIRECTORY_SEPARATOR === chr(92) ? 'NUL' : '/dev/null';
            $proc = proc_open(
                ['whoami', '/user', '/fo', 'csv', '/nh'],
                [0 => ['file', $nulo, 'r'], 1 => ['pipe', 'w'], 2 => ['file', $nulo, 'w']],
                $pipes,
            );

            if (!is_resource($proc)) {
                return null;
            }

            $out = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $code = proc_close($proc);

            return $code === 0 && is_string($out) ? $out : null;
        } catch (Throwable) {
            return null;
        }
    }
}
