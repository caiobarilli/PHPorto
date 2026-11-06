<?php

declare(strict_types=1);

namespace App\Network;

final class Ipv4
{
    public static function isPrivate(string $ip): bool
    {
        return self::range($ip) !== null;
    }

    public static function isPrivateRule(string $rule): bool
    {
        return self::range($rule) !== null;
    }

    public static function matches(string $ip, string $rule): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        $range = self::range($rule);

        if ($range === null) {
            return false;
        }

        $number = self::number($ip);

        return $number !== null && $number >= $range[0] && $number <= $range[1];
    }

    /**
     * @param list<string> $rules
     */
    public static function allows(string $ip, array $rules): bool
    {
        foreach ($rules as $rule) {
            if (self::matches($ip, $rule)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{int, int}|null */
    private static function range(string $rule): ?array
    {
        $parts = explode('/', $rule);

        if (count($parts) > 2 || filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }

        $prefix = 32;

        if (count($parts) === 2) {
            if ($parts[1] === '' || !ctype_digit($parts[1])) {
                return null;
            }

            $prefix = (int) $parts[1];

            if ($prefix < 1 || $prefix > 32) {
                return null;
            }
        }

        $number = self::number($parts[0]);

        if ($number === null) {
            return null;
        }

        $mask = $prefix === 32 ? 0xFFFFFFFF : ((1 << $prefix) - 1) << (32 - $prefix);
        $first = $number & $mask;
        $size = 1 << (32 - $prefix);

        if (!self::isPrivateAddress($first) || !self::isPrivateAddress($first + $size - 1)) {
            return null;
        }

        return [$first, $first + $size - 1];
    }

    private static function isPrivateAddress(int $number): bool
    {
        return ($number >= 0x0A000000 && $number <= 0x0AFFFFFF)
            || ($number >= 0xAC100000 && $number <= 0xAC1FFFFF)
            || ($number >= 0xC0A80000 && $number <= 0xC0A8FFFF);
    }

    private static function number(string $ip): ?int
    {
        $binary = inet_pton($ip);

        if (!is_string($binary) || strlen($binary) !== 4) {
            return null;
        }

        $parts = unpack('Nnumber', $binary);

        if (!is_array($parts) || !is_int($parts['number'])) {
            return null;
        }

        return $parts['number'];
    }
}
