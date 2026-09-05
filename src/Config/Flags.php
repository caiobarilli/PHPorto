<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Chaves que a tela pode ligar e desligar, gravadas fora do .env.
 *
 * POR QUE ISTO NÃO ESCREVE NO .env:
 *
 * O .env guarda credencial de banco. Dar ao processo web permissão de escrita
 * nele significa que qualquer falha de escrita — um disco cheio no meio de um
 * fwrite, um bug de serialização — pode corromper a senha do MySQL junto com a
 * flag que o usuário quis mudar. A tela promete "somente leitura, a
 * configuração vive no .env"; esta classe mantém a promessa e grava o que é
 * alternável em um arquivo separado, que só contém booleanos.
 *
 * PRECEDÊNCIA: flags.json vence o .env, que vence o default do código. O que
 * a pessoa clicou na tela é a intenção mais recente e mais específica.
 *
 * SE O ARQUIVO SUMIR OU CORROMPER, tudo volta ao .env — e o .env do projeto
 * traz a API desligada. A falha cai para o lado seguro por construção, não por
 * tratamento de erro: um JSON ilegível vira array vazio, e array vazio
 * significa "sem opinião, pergunte ao .env".
 */
final class Flags
{
    /**
     * Só estas chaves podem ser gravadas.
     *
     * A allowlist não é burocracia: sem ela, um POST forjado gravaria qualquer
     * par chave/valor no arquivo que a configuração lê. O CSRF já barra o POST
     * de fora, mas uma segunda tranca no ponto de escrita custa três linhas.
     */
    public const WRITABLE = ['api_enabled'];

    public static function path(): string
    {
        return dirname(__DIR__, 2) . '/storage/flags.json';
    }

    /**
     * @return array<string, bool>
     */
    public static function all(): array
    {
        $raw = @file_get_contents(self::path());

        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $data = json_decode($raw, true);

        if (!is_array($data)) {
            return [];
        }

        $out = [];

        foreach (self::WRITABLE as $key) {
            if (isset($data[$key]) && is_bool($data[$key])) {
                $out[$key] = $data[$key];
            }
        }

        return $out;
    }

    public static function get(string $key, bool $default): bool
    {
        return self::all()[$key] ?? $default;
    }

    /**
     * Grava uma chave. Retorna false quando a chave não é alternável ou quando
     * o disco recusou a escrita — quem chama decide o que dizer na tela.
     */
    public static function set(string $key, bool $value): bool
    {
        if (!in_array($key, self::WRITABLE, true)) {
            return false;
        }

        $data       = self::all();
        $data[$key] = $value;

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $dir  = dirname(self::path());

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }

        return @file_put_contents(self::path(), $json . "\n", LOCK_EX) !== false;
    }

    /**
     * Apaga o arquivo inteiro: a configuração volta a ser o .env.
     *
     * Ausente já é o estado de fábrica, então sumir com o arquivo é a operação
     * correta — não existe "escrever os valores padrão de volta".
     */
    public static function reset(): bool
    {
        $path = self::path();

        if (!is_file($path)) {
            return true;
        }

        return @unlink($path);
    }
}
