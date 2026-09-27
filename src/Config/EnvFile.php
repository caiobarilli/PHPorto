<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Lê e troca uma chave no texto de um .env, sem mexer no resto.
 *
 * QUEM USA É SÓ O token.php, rodado à mão na linha de comando. O processo web
 * nunca escreve no .env — ele guarda credencial de banco, e o que a tela
 * alterna vai para storage/flags.json. Esta classe não é permissão para
 * mudar isso.
 */
final class EnvFile
{
    /**
     * O valor de uma chave no texto do .env.
     *
     * Recebe o texto e a chave. Devolve o valor sem aspas nem espaços em
     * volta, string vazia para a chave presente e vazia, ou null se a chave
     * não aparece.
     */
    public static function value(string $contents, string $key): ?string
    {
        foreach (preg_split('/\R/', $contents) ?: [] as $linha) {
            if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*=(.*)$/', $linha, $m) === 1) {
                return trim(trim($m[1]), '"\'');
            }
        }

        return null;
    }

    /**
     * O texto do .env com a chave valendo o valor dado.
     *
     * Recebe o texto, a chave e o valor. Troca a primeira linha da chave, ou
     * acrescenta uma no fim, no mesmo fim de linha que o arquivo já usa.
     * Devolve o texto novo; as outras linhas saem byte a byte.
     */
    public static function with(string $contents, string $key, string $value): string
    {
        $eol    = str_contains($contents, "\r\n") ? "\r\n" : "\n";
        $padrao = '/^[ \t]*' . preg_quote($key, '/') . '[ \t]*=[^\r\n]*/m';

        if (preg_match($padrao, $contents) === 1) {
            return (string) preg_replace($padrao, $key . '=' . $value, $contents, 1);
        }

        $separador = $contents === '' || str_ends_with($contents, "\n") ? '' : $eol;

        return $contents . $separador . $key . '=' . $value . $eol;
    }
}
