<?php

declare(strict_types=1);

use App\Exceptions\DuplicateEntryException;
use App\Providers\SQLiteProvider;

/**
 * Testes de integração do SQLiteProvider.
 *
 * Diferente do MySQLProviderTest, NÃO há skip condicional: SQLite não depende
 * de serviço externo. Cada teste usa um arquivo .sqlite temporário isolado,
 * apagado no afterEach — sem deixar resíduo.
 */

beforeEach(function () {
    // tempnam cria o arquivo vazio; o SQLite reaproveita o mesmo path.
    $this->dbPath = tempnam(sys_get_temp_dir(), 'entry_test_') ?: '';
});

afterEach(function () {
    if (is_string($this->dbPath) && $this->dbPath !== '' && file_exists($this->dbPath)) {
        @unlink($this->dbPath);
    }
});

/** @return array{path: string, table: string} */
function sqliteTestConfig(string $path): array
{
    return ['path' => $path, 'table' => 'entries'];
}

it('cria a tabela e insere um registro', function () {
    $provider = new SQLiteProvider(sqliteTestConfig($this->dbPath));

    $provider->insertEntry('primeiro');

    expect($provider->entryExists('primeiro'))->toBeTrue();
});

it('reporta entryExists=false para valor ausente', function () {
    $provider = new SQLiteProvider(sqliteTestConfig($this->dbPath));

    expect($provider->entryExists('nao-existe'))->toBeFalse();
});

it('lança DuplicateEntryException ao inserir valor repetido', function () {
    $provider = new SQLiteProvider(sqliteTestConfig($this->dbPath));
    $provider->insertEntry('duplicado');

    $provider->insertEntry('duplicado');
})->throws(DuplicateEntryException::class);

it('findAll devolve os registros com created_at em ISO 8601', function () {
    $provider = new SQLiteProvider(sqliteTestConfig($this->dbPath));
    $provider->insertEntry('um');
    $provider->insertEntry('dois');

    $rows    = $provider->findAll();
    $entries = array_column($rows, 'entry');

    expect($rows)->toHaveCount(2)
        ->and($entries)->toContain('um')
        ->and($entries)->toContain('dois')
        ->and($rows[0]['created_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/');
});

it('rejeita nome de tabela inválido (sanitizeIdentifier)', function () {
    new SQLiteProvider(['path' => $this->dbPath, 'table' => 'invalid; DROP TABLE x']);
})->throws(RuntimeException::class);
