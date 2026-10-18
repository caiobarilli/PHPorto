<?php

declare(strict_types=1);

namespace App\Http;

final class Login
{
    public const FIELD = 'phporto_login';

    public static function guard(Auth $auth, string $method): void
    {
        if ($method === 'POST' && array_key_exists(self::FIELD, $_POST)) {
            self::submit($auth);
        }

        $cookie  = $_COOKIE[Auth::COOKIE] ?? null;
        $decisao = $auth->checkCookie(is_string($cookie) ? $cookie : null);

        match ($decisao->outcome) {
            AuthOutcome::Allowed       => null,
            AuthOutcome::NotConfigured => Respond::authRefused($decisao),
            AuthOutcome::Expired       => self::page($decisao, 'A sessão expirou. Entre de novo.', true),
            AuthOutcome::Wrong         => self::page($decisao, 'A sessão deste navegador não vale mais. Entre de novo.', true),
            AuthOutcome::Locked        => self::page($decisao, ''),
            AuthOutcome::Missing       => self::page($decisao, ''),
        };
    }

    public static function safeReturn(mixed $uri): string
    {
        if (!is_string($uri) || preg_match('#^/(?![/\\\\])[^\\\\\x00-\x20\x7f]*$#D', $uri) !== 1) {
            return '/';
        }

        return $uri;
    }

    private static function submit(Auth $auth): never
    {
        if (!$auth->isConfigured()) {
            Respond::authRefused(new AuthDecision(AuthOutcome::NotConfigured));
        }

        if (!Csrf::consume()) {
            self::page($auth->checkCookie(null), 'O formulário venceu. Cole o token de novo.');
        }

        $senha   = $_POST[self::FIELD] ?? null;
        $decisao = $auth->check(is_string($senha) && $senha !== '' ? $senha : null);

        match ($decisao->outcome) {
            AuthOutcome::Allowed       => self::enter($auth),
            AuthOutcome::NotConfigured => Respond::authRefused($decisao),
            AuthOutcome::Missing       => self::page($decisao, 'Cole o token no campo.'),
            AuthOutcome::Locked        => self::page($decisao, ''),
            default                    => self::page($decisao, 'Não deu para entrar. Confira o token.'),
        };
    }

    private static function enter(Auth $auth): never
    {
        $cookie = $auth->issueCookie();

        setcookie(Auth::COOKIE, $cookie['value'], self::cookieOptions($cookie['expires']));
        Respond::redirect(self::safeReturn($_SERVER['REQUEST_URI'] ?? null));
    }

    private static function page(AuthDecision $decisao, string $aviso, bool $apagarCookie = false): never
    {
        if ($apagarCookie) {
            setcookie(Auth::COOKIE, '', self::cookieOptions(1));
        }

        $bloqueado = $decisao->outcome === AuthOutcome::Locked;

        http_response_code($bloqueado ? 429 : 401);
        header('X-Frame-Options: DENY');

        if ($bloqueado) {
            header('Retry-After: ' . $decisao->retryAfter);
        }

        $view = new LoginView(
            action: self::safeReturn($_SERVER['REQUEST_URI'] ?? null),
            aviso: $aviso,
            bloqueio: $bloqueado ? trim(Respond::lockedMessage($decisao->retryAfter)) : '',
            csrfToken: $bloqueado ? '' : Csrf::token(),
            csrfField: Csrf::fieldName(),
            field: self::FIELD,
        );

        Respond::html('PHPorto - entrar', Respond::render('login.php', $view));
    }

    /** @return array{expires: int, path: string, secure: bool, httponly: bool, samesite: 'Strict'} */
    private static function cookieOptions(int $expires): array
    {
        $https = $_SERVER['HTTPS'] ?? '';

        return [
            'expires'  => $expires,
            'path'     => '/',
            'secure'   => is_string($https) && $https !== '' && strtolower($https) !== 'off',
            'httponly' => true,
            'samesite' => 'Strict',
        ];
    }
}
