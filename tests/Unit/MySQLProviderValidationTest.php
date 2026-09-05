<?php

declare(strict_types=1);

use App\Providers\MySQLProvider;

/**
 * O construtor valida o nome da tabela (sanitizeIdentifier) ANTES de abrir
 * qualquer conexão — então estes casos rodam sem precisar de MySQL no ar.
 */

$baseConfig = [
    'host'     => '127.0.0.1',
    'port'     => '3306',
    'database' => 'qualquer',
    'user'     => 'qualquer',
    'password' => 'x',
    'table'    => 'entries',
];

it('rejeita nome de tabela com caractere inválido', function () use ($baseConfig) {
    new MySQLProvider([...$baseConfig, 'table' => 'entries; DROP TABLE x']);
})->throws(RuntimeException::class, 'Nome de tabela MySQL inválido');

it('rejeita nome de tabela vazio', function () use ($baseConfig) {
    new MySQLProvider([...$baseConfig, 'table' => '']);
})->throws(RuntimeException::class);

it('exige database e user na configuração', function () use ($baseConfig) {
    new MySQLProvider([...$baseConfig, 'database' => '', 'user' => '']);
})->throws(RuntimeException::class, 'Configuração do MySQL incompleta');
