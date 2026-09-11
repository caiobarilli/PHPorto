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

/**
 * Implementação MongoDB do registro de execuções.
 *
 * Todo detalhe do driver (Client, Collection, UTCDateTime) fica confinado a
 * esta classe. Para fora, só a DatabaseProviderInterface e a StorageException.
 */
final class MongoProvider implements DatabaseProviderInterface
{
    private Collection $collection;

    /** Coleção de estado da /win, derivada da outra. Ver a nota no SQLiteProvider. */
    private Collection $stateCollection;

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
            // Coleção separada, e não um tipo de documento dentro da mesma:
            // uma coleção só obrigaria toda consulta de execução a filtrar
            // "documentos que não são estado", e o clear() de execuções
            // passaria a poder levar o estado embora por descuido de filtro.
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
        // Aqui a data é gerada por este código, não pelo servidor, então não há
        // leitura de volta: o valor devolvido é exatamente o que foi gravado.
        //
        // Quando vem preenchida, é recolhimento de execução órfã, e a hora tem
        // de ser a de TÉRMINO — a listagem ordena por created_at, e o "agora"
        // poria a linha de ontem no topo do histórico de hoje. Ver a nota no
        // SQLiteProvider. Data ilegível cai no agora em vez de derrubar a
        // gravação: perder a hora é menos grave que perder a execução.
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

    public function putWinState(WinState $state): WinState
    {
        // A hora é gerada por este código, não pelo servidor — como no
        // insert() —, então não há leitura de volta: o valor devolvido é
        // exatamente o que foi gravado.
        $updatedAt = new UTCDateTime();

        try {
            // replaceOne com upsert, e a chave é o _id: ele já é único e
            // indexado por construção, então a garantia de uma linha por
            // (escopo, ação) sai de graça, sem depender de um createIndex que
            // numa coleção preexistente pode falhar sem ninguém notar. Ver a
            // nota em WinState::key().
            $this->stateCollection->replaceOne(
                ['_id' => $state->key()],
                [
                    'scope'  => $state->scope->value,
                    'action' => $state->action,
                    // Texto, como nos outros dois: subdocumento recusaria
                    // chave com ponto ou '$'. Ver WinState::encodedPayload().
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
