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
 *     winutil: array{timeout: int},
 *     tz: string,
 *     dashboard_enabled: bool,
 *     api_enabled: bool,
 *     auth_token: string
 * }
 */
final class Config
{
    /** Piso do timeout. Ver a nota sobre PHPORTO_TIMEOUT no README: acordar a VM custa ~4,5 s. */
    public const MIN_TIMEOUT = 30;

    /**
     * Piso do timeout das ações do Windows.
     *
     * Piso mais alto que o do WSL porque o que roda do outro lado é outra
     * coisa: um debloat mexe em 22 pacotes APPX e um install chama o winget,
     * que baixa. Com valor baixo, a ação seria morta no meio de uma
     * instalação — e uma instalação interrompida deixa o sistema num estado
     * que a ferramenta não sabe descrever.
     */
    public const MIN_WINUTIL_TIMEOUT = 60;

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
            // A chave mantém o nome winutil porque a variável de ambiente
            // mantém: PHPORTO_WINUTIL_TIMEOUT continua sendo o nome no .env de
            // quem já usa, e renomear só a chave interna deixaria as duas
            // pontas com nomes diferentes para a mesma coisa. Só sobrou o
            // timeout — o caminho para o projeto externo não existe mais.
            'winutil' => [
                'timeout' => self::winutilTimeout(),
            ],
            'tz'                => self::timezone(),
            'dashboard_enabled' => self::bool('PHPORTO_DASHBOARD_ENABLED', true),
            // A tela pode alternar esta. Flags vence o .env, que vence o
            // default — ver a nota de precedência em Flags.
            'api_enabled'       => Flags::get(
                'api_enabled',
                self::bool('PHPORTO_API_ENABLED', false)
            ),
            // Vazio faz a aplicação recusar servir: ver App\Http\Auth.
            'auth_token'        => self::env('PHPORTO_AUTH_TOKEN', ''),
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

    /**
     * Timeout das ações do Windows, com piso.
     *
     * O padrão é generoso (600 s) porque as ações longas do Windows são a
     * regra, não a exceção: audit gera oito blocos, network captura pelo
     * tempo que se pedir mais o relatório, install chama o winget e debloat
     * percorre 22 pacotes. A espera é SÍNCRONA de propósito — é o que garante
     * que o registro sempre chega ao banco.
     *
     * Este número atravessa o limite de execução do PHP: ver
     * TIME_LIMIT_MARGIN_S na Elevation. O php -S corta em 30 s por padrão, e
     * sem levantar isso nenhuma ação longa terminaria.
     */
    private static function winutilTimeout(): int
    {
        $raw = (int) self::env('PHPORTO_WINUTIL_TIMEOUT', '600');

        return max(self::MIN_WINUTIL_TIMEOUT, $raw);
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
        return self::resolvePath(self::env('SQLITE_PATH', 'storage/database.sqlite'), dirname(__DIR__, 2));
    }

    /**
     * Ancora um caminho relativo na raiz dada; absoluto volta como veio.
     *
     * Recebe o caminho e a raiz. É absoluto o que começa com / ou \, e o que
     * começa com letra de drive seguida de / ou \ (C:/x e C:\x).
     */
    public static function resolvePath(string $path, string $root): string
    {
        $isAbsolute = $path !== ''
            && ($path[0] === '/' || $path[0] === '\\' || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1);

        return $isAbsolute ? $path : $root . '/' . $path;
    }

    /**
     * Flag booleana. Só "false", "0", "no" e "off" desligam; qualquer outra
     * coisa mantém o padrão do parâmetro. Assim um valor digitado errado não
     * liga silenciosamente uma superfície que nasce desligada.
     */
    /**
     * O que o .env sozinho diria sobre a API, ignorando o que a tela gravou.
     *
     * A /config mostra as duas respostas lado a lado: sem isso, uma chave
     * ligada pela tela pareceria vir do arquivo, e a pessoa procuraria no
     * .env um valor que não está lá.
     */
    public static function apiEnabledFromEnv(): bool
    {
        return self::bool('PHPORTO_API_ENABLED', false);
    }

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
