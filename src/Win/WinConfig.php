<?php

declare(strict_types=1);

namespace App\Win;

use RuntimeException;

/**
 * Leitura dos JSON de src/Win/config, os mesmos que as ações do PowerShell leem.
 */
final class WinConfig
{
    /**
     * Os pacotes APPX que a ação debloat remove, na ordem do arquivo.
     *
     * Recebe, opcionalmente, outra pasta de config (para teste). Devolve a
     * lista de nomes de pacote.
     *
     * @return list<string>
     *
     * @throws RuntimeException se o arquivo faltar ou não for uma lista de nomes
     */
    public static function debloat(?string $dir = null): array
    {
        $dados = self::json($dir, 'debloat');

        if (!is_array($dados) || !array_is_list($dados) || $dados === []) {
            throw new RuntimeException('debloat.json não é uma lista de pacotes.');
        }

        $pacotes = [];

        foreach ($dados as $pacote) {
            if (!is_string($pacote) || trim($pacote) === '') {
                throw new RuntimeException('debloat.json tem um item que não é nome de pacote.');
            }

            $pacotes[] = $pacote;
        }

        return $pacotes;
    }

    /**
     * Lê e decodifica um JSON de config pelo nome.
     *
     * Recebe a pasta (ou null para src/Win/config) e o nome sem extensão.
     * Devolve o valor decodificado.
     *
     * @throws RuntimeException se o arquivo faltar ou não for JSON válido
     */
    private static function json(?string $dir, string $nome): mixed
    {
        $arquivo = ($dir ?? __DIR__ . DIRECTORY_SEPARATOR . 'config') . DIRECTORY_SEPARATOR . $nome . '.json';

        if (!is_file($arquivo)) {
            throw new RuntimeException("config ausente em src/Win/config: {$nome}.json");
        }

        $texto = (string) file_get_contents($arquivo);

        if (str_starts_with($texto, "\xEF\xBB\xBF")) {
            $texto = substr($texto, 3);
        }

        $dados = json_decode($texto, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("{$nome}.json não é JSON válido: " . json_last_error_msg());
        }

        return $dados;
    }
}
