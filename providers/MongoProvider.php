<?php

declare(strict_types=1);

namespace App\Providers;

use App\Exceptions\DuplicateEntryException;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\Driver\Exception\BulkWriteException;
use MongoDB\Driver\Exception\Exception as MongoDriverException;
use RuntimeException;

/**
 * Implementação MongoDB do provider de persistência.
 *
 * Todo detalhe do driver (Client, Collection, BulkWriteException, código
 * 11000 de duplicate key) fica confinado a esta classe. Para fora, só a
 * DatabaseProviderInterface e a DuplicateEntryException da aplicação.
 */
final class MongoProvider implements DatabaseProviderInterface
{
    /** Código de erro do Mongo para violação de índice único. */
    private const DUPLICATE_KEY_CODE = 11000;

    private Collection $collection;

    /**
     * @param array{uri: string, database: string, collection: string} $config
     */
    public function __construct(array $config)
    {
        // Driver opcional: mongodb/mongodb está em "suggest", não em "require".
        // Checa a presença antes de usar qualquer classe do driver, para dar
        // uma mensagem clara em vez de um "Class not found" cru do PHP.
        if (!class_exists(Client::class)) {
            throw new RuntimeException(
                'Driver do MongoDB ausente. Rode "composer require mongodb/mongodb" e '
                . 'habilite a extensão ext-mongodb. Necessário apenas para DB_PROVIDER=mongo '
                . '(DB_PROVIDER=mysql não precisa).'
            );
        }

        if ($config['uri'] === '' || $config['database'] === '') {
            throw new RuntimeException('Configuração do MongoDB incompleta (URI/database).');
        }

        try {
            $client = new Client($config['uri']);
            $this->collection = $client->selectCollection(
                $config['database'],
                $config['collection']
            );
            $this->ensureUniqueEntryIndex();
        } catch (DuplicateEntryException $e) {
            throw $e;
        } catch (MongoDriverException $e) {
            throw new RuntimeException('Falha ao inicializar o MongoDB: ' . $e->getMessage(), 0, $e);
        }
    }

    public function insertEntry(string $entry): void
    {
        try {
            $this->collection->insertOne([
                'entry'      => $entry,
                'created_at' => new UTCDateTime(),
            ]);
        } catch (BulkWriteException $e) {
            if ($this->isDuplicateKey($e)) {
                throw new DuplicateEntryException('Este registro já existe.', 0, $e);
            }
            throw new RuntimeException('Falha ao gravar o registro: ' . $e->getMessage(), 0, $e);
        } catch (MongoDriverException $e) {
            throw new RuntimeException('Falha ao gravar o registro: ' . $e->getMessage(), 0, $e);
        }
    }

    public function entryExists(string $entry): bool
    {
        try {
            return $this->collection->countDocuments(['entry' => $entry]) > 0;
        } catch (MongoDriverException $e) {
            throw new RuntimeException('Falha ao consultar o registro: ' . $e->getMessage(), 0, $e);
        }
    }

    public function findAll(): array
    {
        try {
            $cursor = $this->collection->find(
                [],
                [
                    'sort'    => ['created_at' => 1],
                    'typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array'],
                ]
            );

            $rows = [];
            foreach ($cursor as $doc) {
                // O typeMap acima força cada documento a vir como array.
                if (!is_array($doc)) {
                    continue;
                }

                $entry = $doc['entry'] ?? null;
                $rows[] = [
                    'entry'      => is_string($entry) ? $entry : '',
                    'created_at' => $this->formatDate($doc['created_at'] ?? null),
                ];
            }

            return $rows;
        } catch (MongoDriverException $e) {
            throw new RuntimeException('Falha ao listar os registros: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Converte o `created_at` do Mongo (UTCDateTime) em string ISO 8601,
     * mantendo a saída agnóstica ao driver. Tolera valores ausentes ou já
     * gravados como string.
     */
    private function formatDate(mixed $value): ?string
    {
        if ($value instanceof UTCDateTime) {
            return $value->toDateTime()->format(DATE_ATOM);
        }

        if (is_string($value) && $value !== '') {
            return $value;
        }

        return null;
    }

    /**
     * Garante o índice único em `entry`. Idempotente: o Mongo ignora a
     * recriação de um índice idêntico já existente.
     */
    private function ensureUniqueEntryIndex(): void
    {
        $this->collection->createIndex(['entry' => 1], ['unique' => true]);
    }

    /**
     * Detecta duplicate key (11000) inspecionando o BulkWriteException —
     * a checagem fica isolada aqui para não vazar o código do driver.
     */
    private function isDuplicateKey(BulkWriteException $e): bool
    {
        if ($e->getCode() === self::DUPLICATE_KEY_CODE) {
            return true;
        }

        $result = $e->getWriteResult();
        foreach ($result->getWriteErrors() as $writeError) {
            if ($writeError->getCode() === self::DUPLICATE_KEY_CODE) {
                return true;
            }
        }

        return false;
    }
}
