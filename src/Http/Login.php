<?php

declare(strict_types=1);

namespace App\Http;

use App\Network\LoginFlow;

final class Login
{
    public const FIELD = 'phporto_login';

    public static function guard(Auth $auth, string $method, ?LoginFlow $network = null): void
    {
        $wait = $method === 'POST' && array_key_exists(self::FIELD, $_POST) ? $network?->before($_SERVER) : null;
        if ($wait !== null) {
            self::page($wait, '', network: $network);
        }

        if ($method === 'POST' && array_key_exists(self::FIELD, $_POST)) {
            self::submit($auth, $network);
        }

        $cookie  = $_COOKIE[Auth::COOKIE] ?? null;
        $decisao = $auth->checkCookie(is_string($cookie) ? $cookie : null);

        match ($decisao->outcome) {
            AuthOutcome::Allowed       => null,
            AuthOutcome::NotConfigured => Respond::authRefused($decisao),
            AuthOutcome::Expired       => self::page($decisao, 'A sessão expirou. Entre de novo.', true, $network),
            AuthOutcome::Wrong         => self::page($decisao, 'A sessão deste navegador não vale mais. Entre de novo.', true, $network),
            AuthOutcome::Locked        => self::page($decisao, '', network: $network),
            AuthOutcome::Missing       => self::page($decisao, '', network: $network),
        };
    }

    public static function safeReturn(mixed $uri): string
    {
        if (!is_string($uri) || preg_match('#^/(?![/\\\\])[^\\\\\x00-\x20\x7f]*$#D', $uri) !== 1) {
            return '/';
        }

        return $uri;
    }

    private static function submit(Auth $auth, ?LoginFlow $network): never
    {
        if (!$auth->isConfigured()) {
            Respond::authRefused(new AuthDecision(AuthOutcome::NotConfigured));
        }

        if (!Csrf::consume()) {
            self::page($auth->checkCookie(null), 'O formulário venceu. Cole o token de novo.', network: $network);
        }

        $senha = $_POST[self::FIELD] ?? null;
        $senha = is_string($senha) && $senha !== '' ? $senha : null;
        $decisao = $network?->check($auth, $senha, $_SERVER);
        $decisao = ($decisao ?? new LoginResult($auth->check($senha), $senha !== null))->decision;

        match ($decisao->outcome) {
            AuthOutcome::Allowed       => self::enter($auth),
            AuthOutcome::NotConfigured => Respond::authRefused($decisao),
            AuthOutcome::Missing       => self::page($decisao, 'Cole o token no campo.', network: $network),
            AuthOutcome::Locked        => self::page($decisao, '', network: $network),
            default                    => self::page($decisao, 'Não deu para entrar. Confira o token.', network: $network),
        };
    }

    private static function enter(Auth $auth): never
    {
        $cookie = $auth->issueCookie();

        Csrf::regenerate();

        setcookie(Auth::COOKIE, $cookie['value'], self::cookieOptions($cookie['expires']));
        Respond::redirect(self::safeReturn($_SERVER['REQUEST_URI'] ?? null));
    }

    private static function page(AuthDecision $decisao, string $aviso, bool $apagarCookie = false, ?LoginFlow $network = null): never
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

        Respond::html('PHPorto - entrar', Respond::render('login.php', $view), $network?->state());
    }

    /** @return array{expires: int, path: string, secure: bool, httponly: bool, samesite: 'Strict'} */
    private static function cookieOptions(int $expires): array
    {
        return [
            'expires'  => $expires,
            'path'     => '/',
            'secure'   => Https::active($_SERVER),
            'httponly' => true,
            'samesite' => 'Strict',
        ];
    }
}
