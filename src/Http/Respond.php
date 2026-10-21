<?php

declare(strict_types=1);

namespace App\Http;

final class Respond
{
    public static function e(?string $text): string
    {
        return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function dateTime(?string $utcIso, string $tz): string
    {
        if ($utcIso === null || trim($utcIso) === '') {
            return '—';
        }

        try {
            $data = new \DateTimeImmutable($utcIso, new \DateTimeZone('UTC'));

            return $data->setTimezone(new \DateTimeZone($tz))->format('d/m/Y H:i:s');
        } catch (\Exception) {
            return $utcIso;
        }
    }

    public static function render(string $template, object $view): string
    {
        ob_start();
        require dirname(__DIR__) . '/Views/' . $template;

        return (string) ob_get_clean();
    }

    public static function html(string $title, string $content): never
    {
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: no-store');

        $view = new LayoutView($title, $content);
        echo self::render('layout.php', $view);
        exit;
    }

    public static function partial(string $content): never
    {
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: no-store');

        echo $content;
        exit;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo (string) json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );
        exit;
    }

    public static function notFound(): never
    {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "404\n";
        exit;
    }

    public static function authRefused(AuthDecision $decision): never
    {
        http_response_code($decision->outcome->status());
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        self::authHeaders($decision);

        echo match ($decision->outcome) {
            AuthOutcome::NotConfigured => "PHPORTO_AUTH_TOKEN não está configurado no .env, e sem ele nada é servido.\n"
                . "Rode na raiz do projeto:  php token.php\n",
            AuthOutcome::Locked => self::lockedMessage($decision->retryAfter),
            default => "Token ausente ou errado. Mande o token no campo de senha do Basic (curl -u :TOKEN); o usuário é ignorado.\n",
        };

        exit;
    }

    private static function authHeaders(AuthDecision $decision): void
    {
        if ($decision->outcome === AuthOutcome::Locked) {
            header('Retry-After: ' . $decision->retryAfter);
        }
    }

    public static function lockedMessage(int $segundos): string
    {
        return sprintf(
            "Tentativas erradas demais: bloqueado por mais %d min %02d s.\n",
            intdiv($segundos, 60),
            $segundos % 60
        );
    }

    public static function redirect(string $path): never
    {
        header('Location: ' . $path, true, 303);
        exit;
    }
}
