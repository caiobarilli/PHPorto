<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Saída HTTP: HTML, JSON e 404.
 *
 * O 404 é a resposta de superfície desligada — não 403, não página em branco.
 * 403 confirmaria que existe algo ali, e a existência é justamente o que uma
 * flag desligada não deve revelar.
 */
final class Respond
{
    /** Escapa para HTML. Toda saída de texto na página passa por aqui. */
    public static function e(?string $text): string
    {
        return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * A data de um registro, para exibição: dd/mm/aaaa hh:mm:ss no fuso dado.
     *
     * O QUE SE GRAVA CONTINUA SENDO UTC, e o schema não muda: a conversão é
     * de exibição, e acontece o mais tarde possível. Guardar no fuso local
     * pareceria mais simples e quebraria a comparação entre registros
     * gravados antes e depois de uma mudança de horário.
     *
     * Valor ausente ou impossível de interpretar volta como veio — o log é
     * prova, e inventar data seria pior que mostrar o valor estranho.
     */
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

    /**
     * Renderiza um template de views/ para string.
     *
     * O template recebe uma única variável, $view, tipada — é o que permite
     * manter views/ dentro da análise estática em vez de virar o depósito
     * onde o código sem tipo se acumula.
     */
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

        $view = new LayoutView($title, $content);
        echo self::render('layout.php', $view);
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

    public static function redirect(string $path): never
    {
        header('Location: ' . $path, true, 303);
        exit;
    }
}
