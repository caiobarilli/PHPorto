<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Execution;
use App\Domain\ExecutionKind;
use App\Domain\WinState;
use App\Domain\WinStateScope;
use App\Exceptions\StorageException;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\Driver\Exception\Exception as MongoDriverException;

final class MongoProvider implements DatabaseProviderInterface
{
    private Collection $collection;

    private Collection $stateCollection;

    /**
     * @param array{uri: string, database: string, collection: string} $config
     */
    public function __construct(array $config)
    {
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
            $this->stateCollection = $client->selectCollection(
                $config['database'],
                $config['collection'] . self::STATE_SUFFIX
            );
            $this->ensureIndex();
        } catch (MongoDriverException $e) {
            throw new StorageException('Falha ao inicializar o MongoDB: ' . $e->getMessage(), 0, $e);
        }
    }

    public function insert(Execution $execution): Execution
    {
        $createdAt = new UTCDateTime();

        if ($execution->createdAt !== null) {
            try {
                $createdAt = new UTCDateTime(
                    new \DateTimeImmutable($execution->createdAt, new \DateTimeZone('UTC'))
                );
            } catch (\Exception) {
                $createdAt = new UTCDateTime();
            }
        }

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

    public function recent(int $limit = 100, ?array $kinds = null): array
    {
        try {
            $cursor = $this->collection->find(
                self::kindFilter($kinds),
                [
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

    public function clear(?array $kinds = null): int
    {
        try {
            return $this->collection
                ->deleteMany(self::kindFilter($kinds))
                ->getDeletedCount();
        } catch (MongoDriverException $e) {
            throw new StorageException('Falha ao limpar as execuções: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @param list<ExecutionKind>|null $kinds
     * @return array<string, mixed>
     */
    private static function kindFilter(?array $kinds): array
    {
        if ($kinds === null) {
            return [];
        }

        return ['kind' => ['$in' => array_values(array_unique(array_map(static fn (ExecutionKind $k): string => $k->value, $kinds)))]];
    }

    public function putWinState(WinState $state): WinState
    {
        $updatedAt = new UTCDateTime();

        try {
            $this->stateCollection->replaceOne(
                ['_id' => $state->key()],
                [
                    'scope'  => $state->scope->value,
                    'action' => $state->action,
                    'payload'    => $state->encodedPayload(),
                    'updated_at' => $updatedAt,
                ],
                ['upsert' => true]
            );
        } catch (MongoDriverException $e) {
            throw new StorageException('Falha ao gravar o estado da /win: ' . $e->getMessage(), 0, $e);
        }

        return new WinState(
            scope: $state->scope,
            action: $state->action,
            payload: $state->payload,
            updatedAt: $this->formatDate($updatedAt),
        );
    }

    public function winStates(WinStateScope $scope): array
    {
        try {
            $cursor = $this->stateCollection->find(
                ['scope' => $scope->value],
                ['typeMap' => ['root' => 'array', 'document' => 'array']]
            );

            $estados = [];

            foreach ($cursor as $doc) {
                if (!is_array($doc)) {
                    continue;
                }

                $assoc = [];
                foreach ($doc as $key => $value) {
                    $assoc[(string) $key] = $value;
                }

                $estado = $this->hydrateState($assoc);

                if ($estado !== null) {
                    $estados[$estado->action] = $estado;
                }
            }

            return $estados;
        } catch (MongoDriverException $e) {
            throw new StorageException('Falha ao ler o estado da /win: ' . $e->getMessage(), 0, $e);
        }
    }

    public function forgetWinState(WinStateScope $scope, string $action): int
    {
        try {
            return $this->stateCollection
                ->deleteOne(['_id' => (new WinState($scope, $action))->key()])
                ->getDeletedCount();
        } catch (MongoDriverException $e) {
            throw new StorageException('Falha ao esquecer o estado da /win: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @param array<string, mixed> $doc
     */
    private function hydrateState(array $doc): ?WinState
    {
        $scope   = WinStateScope::fromStorage($doc['scope'] ?? null);
        $action  = $doc['action'] ?? null;
        $payload = WinState::decodePayload($doc['payload'] ?? null);

        if ($scope === null || !is_string($action) || $action === '' || $payload === null) {
            return null;
        }

        return new WinState(
            scope: $scope,
            action: $action,
            payload: $payload,
            updatedAt: $this->formatDate($doc['updated_at'] ?? null),
        );
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

    private function ensureIndex(): void
    {
        $this->collection->createIndex(['created_at' => -1, '_id' => -1]);
    }

    private function formatDate(mixed $value): ?string
    {
        if ($value instanceof UTCDateTime) {
            return $value->toDateTime()->format(DATE_ATOM);
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
