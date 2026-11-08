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

    public static function normalizeRule(string $rule): string
    {
        if (preg_match('#\A([0-9.]+)/([0-9]{1,3}(?:\.[0-9]{1,3}){3})\z#', $rule, $m) !== 1) {
            return $rule;
        }

        $mask = self::number($m[2]);

        if ($mask === null) {
            return $rule;
        }

        $prefix = 32;

        while ($prefix > 0 && ($mask & (1 << (32 - $prefix))) === 0) {
            $prefix--;
        }

        $expected = $prefix === 0 ? 0 : (0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF;

        return $mask === $expected ? $m[1] . '/' . $prefix : $rule;
    }

    /** @return array{int, int}|null */
    public static function bounds(string $rule): ?array
    {
        return self::range(self::normalizeRule($rule));
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

        $size = 2 ** (32 - $prefix);
        $first = intdiv($number, $size) * $size;

        if (!self::isPrivateAddress($first) || !self::isPrivateAddress($first + $size - 1)) {
            return null;
        }

        return [$first, $first + $size - 1];
    }

    private static function isPrivateAddress(int $number): bool
    {
        return ($number >= 167772160 && $number <= 184549375)
            || ($number >= 2886729728 && $number <= 2887778303)
            || ($number >= 3232235520 && $number <= 3232301055);
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
