<?php

declare(strict_types=1);

namespace App\Autostart;

use Closure;

final class Level
{
    public const MEDIUM = 'médio';

    public const HIGH = 'alto';

    public const SYSTEM = 'sistema';

    public const LOW = 'baixo';

    /**
     * @param (Closure(): ?string)|null $whoami
     */
    public static function current(?Closure $whoami = null): ?string
    {
        if ($whoami === null && DIRECTORY_SEPARATOR !== '\\') {
            return null;
        }

        $output = ($whoami ?? static fn (): ?string => Identity::whoami(['/groups', '/fo', 'csv', '/nh']))();

        return $output === null ? null : self::parse($output);
    }

    public static function parse(string $output): ?string
    {
        if (preg_match('/"S-1-16-([0-9]{1,6})"/', $output, $m) !== 1) {
            return null;
        }

        $rid = (int) $m[1];

        return match (true) {
            $rid >= 16384 => self::SYSTEM,
            $rid >= 12288 => self::HIGH,
            $rid >= 8192 => self::MEDIUM,
            default => self::LOW,
        };
    }

    public static function elevated(?string $level): bool
    {
        return $level === self::HIGH || $level === self::SYSTEM;
    }
}
