<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Execution;
use App\Domain\ExecutionKind;
use App\Exceptions\StorageException;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\Driver\Exception\Exception as MongoDriverException;

/**
 * Implementação MongoDB do registro de execuções.
 *
 * Todo detalhe do driver (Client, Collection, UTCDateTime) fica confinado a
 * esta classe. Para fora, só a DatabaseProviderInterface e a StorageException.
 */
final class MongoProvider implements DatabaseProviderInterface
{
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
            throw new StorageException(
                'Driver do MongoDB ausente. Rode "composer require mongodb/mongodb" e '
                . 'habilite a extensão ext-mongodb. Necessário apenas para DB_PROVIDER=mongo.'
            );
        }

        if ($config['uri'] === '' || $config['database'] === '') {
            throw new StorageException('Configuração do MongoDB incompleta (URI/database).');
        }

        try {
            $client           = new Client($config['uri']);
            $this->collection = $client->selectCollection($config['database'], $config['collection']);
            $this->ensureIndex();
        } catch (MongoDriverException $e) {
            throw new StorageException('Falha ao inicializar o MongoDB: ' . $e->getMessage(), 0, $e);
        }
    }

    public function insert(Execution $execution): Execution
    {
        // Aqui a data é gerada por este código, não pelo servidor, então não há
        // leitura de volta: o valor devolvido é exatamente o que foi gravado.
        $createdAt = new UTCDateTime();

        try {
            $this->collection->insertOne([
                'command'     => $execution->command,
                'output'      => $execution->output,
                'exit_code'   => $execution->exitCode,
                'duration_ms' => $execution->durationMs,
                'kind'        => $execution->kind->value,
                'timed_out'   => $execution->timedOut,
                'created_at'  => $createdAt,
            ]);
        } catch (MongoDriverException $e) {
            throw new StorageException('Falha ao gravar a execução: ' . $e->getMessage(), 0, $e);
        }

        return new Execution(
            command: $execution->command,
            output: $execution->output,
            exitCode: $execution->exitCode,
            durationMs: $execution->durationMs,
            kind: $execution->kind,
            timedOut: $execution->timedOut,
            createdAt: $this->formatDate($createdAt),
        );
    }

    public function recent(int $limit = 100, ?ExecutionKind $kind = null): array
    {
        try {
            $cursor = $this->collection->find(
                // Filtro vazio é "todos": aqui o Mongo é mais direto que o SQL,
                // porque a ausência de cláusula é o próprio documento vazio.
                $kind === null ? [] : ['kind' => $kind->value],
                [
                    // _id do Mongo é monotônico por processo e serve de desempate
                    // quando duas execuções caem no mesmo milissegundo.
                    'sort'    => ['created_at' => -1, '_id' => -1],
                    'limit'   => $limit,
                    'typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array'],
                ]
            );

            $rows = [];
            foreach ($cursor as $doc) {
                if (is_array($doc)) {
                    $rows[] = $this->hydrate($doc);
                }
            }

            return $rows;
        } catch (MongoDriverException $e) {
            throw new StorageException('Falha ao listar as execuções: ' . $e->getMessage(), 0, $e);
        }
    }

    public function clear(?ExecutionKind $kind = null): int
    {
        try {
            return $this->collection
                ->deleteMany($kind === null ? [] : ['kind' => $kind->value])
                ->getDeletedCount();
        } catch (MongoDriverException $e) {
            throw new StorageException('Falha ao limpar as execuções: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @param array<string, mixed> $doc
     */
    private function hydrate(array $doc): Execution
    {
        $command  = $doc['command'] ?? '';
        $output   = $doc['output'] ?? '';
        $exitRaw  = $doc['exit_code'] ?? null;
        $duration = $doc['duration_ms'] ?? 0;

        return new Execution(
            command: is_string($command) ? $command : '',
            output: is_string($output) ? $output : '',
            exitCode: is_numeric($exitRaw) ? (int) $exitRaw : null,
            durationMs: is_numeric($duration) ? (int) $duration : 0,
            kind: ExecutionKind::fromStorage($doc['kind'] ?? null),
            timedOut: (bool) ($doc['timed_out'] ?? false),
            createdAt: $this->formatDate($doc['created_at'] ?? null),
        );
    }

    /**
     * Índice de ordenação. NÃO é único: log de execução aceita repetição —
     * ver a nota na DatabaseProviderInterface.
     */
    private function ensureIndex(): void
    {
        $this->collection->createIndex(['created_at' => -1, '_id' => -1]);
    }

    /** Converte o created_at (UTCDateTime) em ISO 8601, mantendo a saída agnóstica. */
    private function formatDate(mixed $value): ?string
    {
        if ($value instanceof UTCDateTime) {
            return $value->toDateTime()->format(DATE_ATOM);
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
