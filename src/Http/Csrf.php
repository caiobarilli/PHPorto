<?php

declare(strict_types=1);

namespace App\Http;

final class Csrf
{
    private const FIELD = 'phporto_csrf';
    private const KEY   = 'phporto_csrf_token';

    public static function fieldName(): string
    {
        return self::FIELD;
    }

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Strict',
            'path'     => '/',
        ]);
        session_name('phporto_session');
        @session_start();
    }

    public static function token(): string
    {
        self::start();

        $current = $_SESSION[self::KEY] ?? null;

        if (is_string($current) && $current !== '') {
            return $current;
        }

        $token                = bin2hex(random_bytes(32));
        $_SESSION[self::KEY]  = $token;

        return $token;
    }

    public static function consume(): bool
    {
        $site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? null;

        if (!self::sameSite(is_string($site) ? $site : null)) {
            return false;
        }

        self::start();

        $expected = $_SESSION[self::KEY] ?? null;
        $sent     = $_POST[self::FIELD] ?? null;

        if (!is_string($expected) || $expected === '' || !is_string($sent) || $sent === '') {
            return false;
        }

        if (!hash_equals($expected, $sent)) {
            return false;
        }

        unset($_SESSION[self::KEY]);

        return true;
    }

    public static function sameSite(?string $header): bool
    {
        return $header === null || in_array(strtolower(trim($header)), ['same-origin', 'none'], true);
    }
}
