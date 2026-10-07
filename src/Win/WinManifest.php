<?php

declare(strict_types=1);

namespace App\Win;

use RuntimeException;

/**
 * O SHA-256 de cada arquivo que o PowerShell elevado carrega.
 *
 * POR QUE EXISTE: src/Win é gravável por qualquer processo do usuário, e o
 * worker roda em integridade Alta. Sem conferência, quem trocasse um .ps1 de
 * lib/ ou um JSON de config/ com o worker de pé ganhava a próxima ação como
 * Administrador — o tweaks.json, por exemplo, carrega PowerShell.
 *
 * O MAPA É TIRADO AO LIGAR, uma vez só, e não a cada ação: a ligação é o
 * momento em que a pessoa decidiu confiar no que está em src/Win. Daí em diante
 * cada arquivo é conferido contra este mapa logo antes de entrar (ver
 * Read-PhportoConferido no worker.ps1), e o que mudou é recusado.
 *
 * O MAPA VAI POR ARQUIVO, E O HASH DELE PELA LINHA DE COMANDO. O mapa em
 * base64 passa de 4 KB, e o -Verb RunAs passa pelo ShellExecuteEx, que pode
 * cortar a linha em ~2048 caracteres sem avisar. O hash tem 64; o worker
 * só aceita o arquivo cujo SHA-256 for esse, e daí o mapa vive na memória dele.
 *
 * O QUE NÃO COBRE: arquivo trocado ANTES de ligar entra no mapa como se fosse
 * o certo, do mesmo jeito que um worker.ps1 reescrito antes de ligar. Ver
 * docs/seguranca.md.
 */
final class WinManifest
{
    /**
     * O que o lado elevado lê e executa, relativo a src/Win.
     *
     * É a lista do bootstrap.ps1 (config, lib/ antes de actions/), mais o
     * próprio bootstrap e o audit.ps1 que o Invoke-Audit roda. Arquivo que o
     * bootstrap encontrar e não estiver aqui é recusado por ele, então errar
     * para menos falha fechado, com mensagem.
     */
    public const FONTES = ['bootstrap.ps1', 'audit/audit.ps1', 'actions/*.ps1', 'lib/*.ps1', 'config/*.json'];

    /** @return array<string, string> caminho relativo com "/" => sha256 em hexadecimal */
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

        // O hash é dos bytes gravados: é ele que o worker confere ao subir.
        return hash('sha256', $json);
    }
}
