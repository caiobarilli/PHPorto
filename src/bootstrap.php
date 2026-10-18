<?php

declare(strict_types=1);

use App\Config\Config;
use App\Providers\DatabaseProviderInterface;
use App\Providers\MongoProvider;
use App\Providers\MySQLProvider;
use App\Providers\SQLiteProvider;
use App\Services\ExecutionLogService;
use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$config = Config::load();

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
    'makeService' => static fn (): ExecutionLogService => new ExecutionLogService($makeProvider()),
];
