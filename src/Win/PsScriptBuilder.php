<?php

declare(strict_types=1);

namespace App\Win;

use RuntimeException;

/**
 * Escreve e lê os scripts .ps1 que atravessam a fronteira com o PowerShell.
 *
 * A REGRA DE ENCODING AQUI É A INVERSA DA DO cmd.sh, e isso é deliberado.
 *
 *   cmd.sh (WSL)   LF, SEM BOM
 *   .ps1 (Windows) CRLF, COM BOM
 *
 * O bash engasga com \r e com BOM: os sintomas são "$'\r': command not found"
 * e a primeira linha ignorada — estão anotados na skill php-wsl-interop. O
 * PowerShell 5.1 tem o problema oposto: sem BOM ele lê o arquivo como ANSI, e
 * qualquer acento vira lixo na saída. É o único PowerShell instalado nesta
 * máquina, então não há a saída de assumir UTF-8 por padrão como o pwsh faz.
 *
 * As duas regras convivem sem se contradizer porque valem para arquivos
 * diferentes, gerados por lados diferentes. Quem alinhar uma com a outra em
 * nome de consistência quebra o lado oposto — e o .gitattributes carrega a
 * mesma nota para o .ps1 versionado.
 *
 * O TEXTO DO USUÁRIO NUNCA VAI PARA A LINHA DE COMANDO, aqui como no WSL. É a
 * primeira regra da skill, e vale igual: o que o PowerShell recebe na linha de
 * comando é só o caminho de um arquivo que este código escreveu.
 */
final class PsScriptBuilder
{
    /** Assinatura de UTF-8 que o PowerShell 5.1 precisa ver para não ler ANSI. */
    public const BOM = "\xEF\xBB\xBF";

    /**
     * Grava um .ps1 pronto para o PowerShell 5.1: BOM na frente, CRLF no
     * corpo, quebra de linha no fim.
     *
     * @throws RuntimeException se o disco recusar a escrita
     */
    public static function write(string $path, string $body): void
    {
        $dir = dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new RuntimeException("Não foi possível criar a pasta do script: {$dir}");
        }

        if (@file_put_contents($path, self::normalize($body)) === false) {
            throw new RuntimeException("Não foi possível gravar o script: {$path}");
        }
    }

    /**
     * BOM + CRLF + quebra final. Idempotente: normalizar duas vezes não
     * empilha dois BOM nem duplica as quebras.
     */
    public static function normalize(string $body): string
    {
        $body = self::stripBom($body);

        // Passa por LF primeiro para não transformar um CRLF que já existe em
        // CR CR LF — o erro clássico de trocar "\n" por "\r\n" às cegas.
        $body = str_replace(["\r\n", "\r"], "\n", $body);

        if ($body !== '' && !str_ends_with($body, "\n")) {
            $body .= "\n";
        }

        return self::BOM . str_replace("\n", "\r\n", $body);
    }

    /**
     * Tira o BOM do começo, se houver.
     *
     * É o caminho de VOLTA, e ele é necessário: o Set-Content -Encoding UTF8
     * do PowerShell 5.1 grava BOM, então toda saída que o worker escreve
     * chega ao PHP com três bytes na frente. Sem tirar, o BOM aparece colado
     * na primeira palavra da saída na tela — medido na sondagem da elevação,
     * onde a prova voltou como "﻿PID=...".
     */
    public static function stripBom(string $text): string
    {
        return str_starts_with($text, self::BOM) ? substr($text, 3) : $text;
    }

    /**
     * Um valor como literal de string do PowerShell, entre apóstrofos.
     *
     * Apóstrofo simples e não duplo porque o PowerShell NÃO interpola nada
     * dentro de apóstrofo simples: nem $variavel, nem $(subshell), nem crase.
     * O único escape que existe ali é o próprio apóstrofo, dobrado — e é o
     * que esta função faz.
     *
     * Com isto, um caminho ou um valor validado entra num script gerado sem
     * poder virar código. O byte nulo é recusado sem análise: ele trunca
     * string em camadas abaixo desta.
     *
     * @throws RuntimeException se o valor contiver byte nulo
     */
    public static function literal(string $value): string
    {
        if (str_contains($value, "\0")) {
            throw new RuntimeException('Valor com byte nulo recusado.');
        }

        return "'" . str_replace("'", "''", $value) . "'";
    }
}
