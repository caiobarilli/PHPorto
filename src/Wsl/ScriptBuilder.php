<?php

declare(strict_types=1);

namespace App\Wsl;

final class ScriptBuilder
{
    public const HEADER_LINES = 1;

    public const EXIT_ROOT_UNREACHABLE = 78;

    public static function normalize(string $text): string
    {
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        }

        $text = str_replace(["\r\n", "\r"], "\n", $text);

        if ($text !== '' && !str_ends_with($text, "\n")) {
            $text .= "\n";
        }

        return $text;
    }

    public static function header(): string
    {
        return 'exec 2>&1; cd "$PHPORTO_WSL_ROOT" || { echo "[phporto] PHPORTO_WSL_ROOT nao acessivel dentro do WSL: $PHPORTO_WSL_ROOT"; exit '
            . self::EXIT_ROOT_UNREACHABLE . '; }' . "\n";
    }

    public static function command(string $userText): string
    {
        return self::header() . self::normalize($userText);
    }

    public static function attachment(): string
    {
        return self::header()
            . 'case "$PHPORTO_SRC" in "~") PHPORTO_SRC="$HOME";; "~/"*) PHPORTO_SRC="$HOME/${PHPORTO_SRC#\~/}";; esac' . "\n"
            . 'case "$PHPORTO_DST" in "~") PHPORTO_DST="$HOME";; "~/"*) PHPORTO_DST="$HOME/${PHPORTO_DST#\~/}";; esac' . "\n"
            . 'cp -v -- "$PHPORTO_SRC" "$PHPORTO_DST"' . "\n";
    }

    public static function upload(): string
    {
        return self::header()
            . 'case "$PHPORTO_DST" in "~") PHPORTO_DST="$HOME";; "~/"*) PHPORTO_DST="$HOME/${PHPORTO_DST#\~/}";; esac' . "\n"
            . 'if [ -e "$PHPORTO_DST" ] || [ -L "$PHPORTO_DST" ]; then echo "[phporto] o destino ja existe, nada foi copiado: $PHPORTO_DST"; exit '
            . Upload::EXIT_DESTINATION_EXISTS . '; fi' . "\n"
            . 'cp -v -- "$PHPORTO_SRC" "$PHPORTO_DST"' . "\n";
    }

    public static function attachmentRecord(string $src, string $dst): string
    {
        return 'cp -v -- "$PHPORTO_SRC" "$PHPORTO_DST"' . "\n"
            . '  origem  (PHPORTO_SRC): ' . $src . "\n"
            . '  destino (PHPORTO_DST): ' . $dst;
    }
}
