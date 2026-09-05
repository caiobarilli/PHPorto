<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Configuração centralizada da aplicação.
 *
 * Lê as variáveis de ambiente (já carregadas pelo phpdotenv no bootstrap) e as
 * expõe como um array tipado. Nenhum outro ponto do código deve ler $_ENV ou
 * getenv diretamente — tudo passa por aqui.
 *
 * Esta classe NÃO valida regra de negócio nem monta mensagem de tela: ela
 * entrega valores com padrão. Quem decide que PHPORTO_WSL_ROOT vazio impede
 * executar é quem executa.
 */
/**
 * @phpstan-type AppConfig array{
 *     db_provider: string,
 *     cors_origin: string,
 *     mongo: array{uri: string, database: string, collection: string},
 *     mysql: array{host: string, port: string, database: string, user: string, password: string, table: string},
 *     sqlite: array{path: string, table: string},
 *     wsl: array{root: string, distro: string, timeout: int},
 *     tz: string,
 *     dashboard_enabled: bool,
 *     api_enabled: bool
 * }
 */
final class Config
{
    /** Piso do timeout. Ver a nota em .env.example: acordar a VM custa ~4,5 s. */
    public const MIN_TIMEOUT = 30;

    /**
     * @return AppConfig
     */
    public static function load(): array
    {
        return [
            'db_provider' => self::env('DB_PROVIDER', 'sqlite'),
            // Default restritivo de propósito: a origem do servidor local
            // documentado no README. '*' como fallback publicaria uma política
            // permissiva para quem clonar sem criar o .env.
            'cors_origin' => self::env('CORS_ORIGIN', 'http://127.0.0.1:4001'),
            'mongo' => [
                'uri'        => self::env('MONGODB_URI', ''),
                'database'   => self::env('MONGO_DB_NAME', ''),
                'collection' => self::env('MONGO_COLLECTION', 'executions'),
            ],
            'mysql' => [
                'host'     => self::env('MYSQL_HOST', 'localhost'),
                'port'     => self::env('MYSQL_PORT', '3306'),
                'database' => self::env('MYSQL_DATABASE', ''),
                'user'     => self::env('MYSQL_USER', ''),
                'password' => self::env('MYSQL_PASSWORD', ''),
                'table'    => self::env('MYSQL_TABLE', 'executions'),
            ],
            'sqlite' => [
                'path'  => self::sqlitePath(),
                'table' => self::env('SQLITE_TABLE', 'executions'),
            ],
            'wsl' => [
                // Sem padrão de propósito: um default aqui faria o comando rodar
                // no home do usuário do WSL sem ninguém notar.
                'root'    => self::env('PHPORTO_WSL_ROOT', ''),
                'distro'  => self::env('PHPORTO_DISTRO', 'Debian'),
                'timeout' => self::timeout(),
            ],
            'tz'                => self::timezone(),
            'dashboard_enabled' => self::bool('PHPORTO_DASHBOARD_ENABLED', true),
            'api_enabled'       => self::bool('PHPORTO_API_ENABLED', false),
        ];
    }

    /**
     * Timeout em segundos, com piso.
     *
     * O piso não é preciosismo: acordar a VM do WSL custa cerca de 4,5 s
     * (medido), então um timeout de 3 ou 5 segundos faria o PRIMEIRO comando
     * de cada dia falhar sempre — e o erro pareceria do comando, não da
     * configuração.
     */
    private static function timeout(): int
    {
        $raw = (int) self::env('PHPORTO_TIMEOUT', '120');

        return max(self::MIN_TIMEOUT, $raw);
    }

    /** Fuso das datas. Valor inválido cai no padrão em vez de derrubar a página. */
    private static function timezone(): string
    {
        $tz = self::env('PHPORTO_TZ', 'America/Sao_Paulo');

        return in_array($tz, timezone_identifiers_list(), true) ? $tz : 'America/Sao_Paulo';
    }

    /**
     * Resolve o caminho do arquivo SQLite. Default: storage/database.sqlite.
     *
     * SQLITE_PATH relativo é ancorado na RAIZ DO PROJETO, não no diretório
     * servido: com este arquivo em src/Config/, a raiz está dois níveis acima.
     * O storage/ fica de propósito FORA do public/ — o banco contém tudo que
     * foi executado nesta máquina e não existe URL que deva alcançá-lo.
     *
     * Absoluto (começando com / ou drive Windows) é usado como veio.
     */
    private static function sqlitePath(): string
    {
        $projectRoot = dirname(__DIR__, 2);
        $path        = self::env('SQLITE_PATH', 'storage/database.sqlite');

        $isAbsolute = $path !== '' && ($path[0] === '/' || preg_match('#^[A-Za-z]:[\\/]#', $path) === 1);

        return $isAbsolute ? $path : $projectRoot . '/' . $path;
    }

    /**
     * Flag booleana. Só "false", "0", "no" e "off" desligam; qualquer outra
     * coisa mantém o padrão do parâmetro. Assim um valor digitado errado não
     * liga silenciosamente uma superfície que nasce desligada.
     */
    private static function bool(string $key, bool $default): bool
    {
        $value = strtolower(self::env($key, ''));

        if ($value === '') {
            return $default;
        }

        if (in_array($value, ['false', '0', 'no', 'off'], true)) {
            return false;
        }

        if (in_array($value, ['true', '1', 'yes', 'on'], true)) {
            return true;
        }

        return $default;
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
