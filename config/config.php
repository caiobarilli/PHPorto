<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Configuração centralizada da aplicação.
 *
 * Lê as variáveis de ambiente (já carregadas pelo phpdotenv no bootstrap)
 * e as expõe como um array tipado. Nenhum outro ponto do código deve ler
 * $_ENV / getenv diretamente — tudo passa por aqui.
 */
/**
 * @phpstan-type AppConfig array{
 *     db_provider: string,
 *     cors_origin: string,
 *     mongo: array{uri: string, database: string, collection: string},
 *     mysql: array{host: string, port: string, database: string, user: string, password: string, table: string},
 *     sqlite: array{path: string, table: string}
 * }
 */
final class Config
{
    /**
     * @return AppConfig
     */
    public static function load(): array
    {
        // Padrão do projeto: SQLite. Não exige infraestrutura externa — o
        // banco é um arquivo local criado on-demand. MySQL/Mongo continuam
        // disponíveis trocando só DB_PROVIDER.
        return [
            'db_provider' => self::env('DB_PROVIDER', 'sqlite'),
            // Default restritivo de propósito: a origem do servidor local
            // documentado no README. '*' como fallback publicaria uma política
            // permissiva para quem clonar sem criar o .env.
            'cors_origin' => self::env('CORS_ORIGIN', 'http://127.0.0.1:4001'),
            'mongo' => [
                'uri'        => self::env('MONGODB_URI', ''),
                'database'   => self::env('MONGO_DB_NAME', ''),
                'collection' => self::env('MONGO_COLLECTION', 'entries'),
            ],
            'mysql' => [
                'host'     => self::env('MYSQL_HOST', 'localhost'),
                'port'     => self::env('MYSQL_PORT', '3306'),
                'database' => self::env('MYSQL_DATABASE', ''),
                'user'     => self::env('MYSQL_USER', ''),
                'password' => self::env('MYSQL_PASSWORD', ''),
                'table'    => self::env('MYSQL_TABLE', 'entries'),
            ],
            'sqlite' => [
                // Caminho resolvido contra a raiz do projeto quando relativo —
                // o arquivo fica sempre no mesmo lugar, independente do CWD do
                // processo (ex.: `php -S` rodando da raiz do projeto).
                'path'  => self::sqlitePath(),
                'table' => self::env('SQLITE_TABLE', 'entries'),
            ],
        ];
    }

    /**
     * Resolve o caminho do arquivo SQLite. Default: storage/database.sqlite.
     * SQLITE_PATH relativo é ancorado na raiz do projeto (dirname deste config/);
     * absoluto (começando com / ou drive Windows) é usado como veio.
     */
    private static function sqlitePath(): string
    {
        $projectRoot = dirname(__DIR__);
        $path        = self::env('SQLITE_PATH', 'storage/database.sqlite');

        $isAbsolute = $path !== '' && ($path[0] === '/' || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1);

        return $isAbsolute ? $path : $projectRoot . '/' . $path;
    }

    private static function env(string $key, string $default = ''): string
    {
        $value = $_ENV[$key] ?? getenv($key);

        if (!is_string($value) || $value === '') {
            return $default;
        }

        return $value;
    }
}
