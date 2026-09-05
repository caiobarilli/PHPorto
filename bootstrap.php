<?php

declare(strict_types=1);

/**
 * Bootstrap da aplicação.
 *
 * Responsável por: autoload do Composer, carregar o .env, montar a config
 * e instanciar o provider de banco a partir de DB_PROVIDER (factory). Tudo
 * acima dele (index.php) recebe um EntryService pronto.
 */

use App\Config\Config;
use App\Providers\DatabaseProviderInterface;
use App\Providers\MongoProvider;
use App\Providers\MySQLProvider;
use App\Providers\SQLiteProvider;
use App\Services\EntryService;
use Dotenv\Dotenv;

require __DIR__.'/vendor/autoload.php';

// Carrega o .env (não falha se ausente — produção pode usar env reais do SO).
Dotenv::createImmutable(__DIR__)->safeLoad();

$config = Config::load();

/**
 * Factory de provider baseada em DB_PROVIDER ('sqlite', 'mysql' ou 'mongo').
 * Para adicionar um novo banco: criar um provider implementando a
 * DatabaseProviderInterface e somar um case aqui — nada acima muda.
 *
 * Captura o $config já tipado (Config::load) por `use`, mantendo o shape.
 */
$makeProvider = static function () use ($config): DatabaseProviderInterface {
    return match ($config['db_provider']) {
        'sqlite' => new SQLiteProvider($config['sqlite']),
        'mongo' => new MongoProvider($config['mongo']),
        'mysql' => new MySQLProvider($config['mysql']),
        default => throw new RuntimeException(
            "DB_PROVIDER desconhecido: {$config['db_provider']}"
        ),
    };
};

return [
    'config' => $config,
    // Lazy: só conecta no banco quando chamado (no POST), para que CORS e
    // preflight OPTIONS respondam mesmo se o banco estiver indisponível.
    'makeService' => static fn (): EntryService => new EntryService($makeProvider()),
];
