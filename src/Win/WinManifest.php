<?php

declare(strict_types=1);

namespace App\Win;

use RuntimeException;

final class WinManifest
{
    public const FONTES = ['bootstrap.ps1', 'audit/audit.ps1', 'actions/*.ps1', 'lib/*.ps1', 'config/*.json'];

    /** @return array<string, string> */
    public static function of(string $winDir): array
    {
        $raiz = rtrim($winDir, '\\/');
        $mapa = [];

        foreach (self::FONTES as $fonte) {
            foreach (glob($raiz . '/' . $fonte) ?: [] as $arquivo) {
                if (!is_file($arquivo)) {
                    continue;
                }

                $hash = hash_file('sha256', $arquivo);

                if ($hash === false) {
                    throw new RuntimeException('Não foi possível ler ' . $arquivo . ' para o manifesto.');
                }

                $mapa[str_replace('\\', '/', substr($arquivo, strlen($raiz) + 1))] = $hash;
            }
        }

        ksort($mapa);

        return $mapa;
    }

    public static function write(string $winDir, string $destino): string
    {
        $json = json_encode(self::of($winDir), JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT);
        $dir  = dirname($destino);

        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new RuntimeException('Não foi possível criar a pasta do manifesto: ' . $dir);
        }

        if (!is_string($json) || @file_put_contents($destino, $json, LOCK_EX) === false) {
            throw new RuntimeException('Não foi possível gravar o manifesto em ' . $destino . '.');
        }

        return hash('sha256', $json);
    }
}
