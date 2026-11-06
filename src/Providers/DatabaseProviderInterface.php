<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Execution;
use App\Domain\ExecutionKind;
use App\Domain\OllamaExecution;
use App\Domain\OllamaExecutionStatus;
use App\Domain\WinState;
use App\Domain\WinStateScope;
use App\Exceptions\StorageException;

interface DatabaseProviderInterface
{
    public const STATE_SUFFIX = '_win_state';

    /**
     * @throws StorageException
     */
    public function insert(Execution $execution): Execution;

    /**
     * @param list<ExecutionKind>|null $kinds
     * @return list<Execution>
     * @throws StorageException
     */
    public function recent(int $limit = 100, ?array $kinds = null): array;

    /**
     * @param list<ExecutionKind>|null $kinds
     * @throws StorageException
     */
    public function clear(?array $kinds = null): int;

    /**
     * @throws StorageException
     */
    public function putWinState(WinState $state): WinState;

    /**
     * @return array<string, WinState>
     * @throws StorageException
     */
    public function winStates(WinStateScope $scope): array;

    /**
     * @throws StorageException
     */
    public function forgetWinState(WinStateScope $scope, string $action): int;

    /**
     * @throws StorageException
     */
    public function insertOllama(OllamaExecution $execution): OllamaExecution;

    /**
     * @throws StorageException
     */
    public function finalizeOllama(int $id, OllamaExecutionStatus $status, string $output, int $durationMs): ?OllamaExecution;

    /**
     * @throws StorageException
     */
    public function openOllamaPull(?string $model = null): ?OllamaExecution;

    /**
     * @return list<OllamaExecution>
     * @throws StorageException
     */
    public function recentOllama(int $limit, int $offset = 0): array;

    /**
     * @throws StorageException
     */
    public function countOllama(): int;

    /**
     * @throws StorageException
     */
    public function clearOllama(): int;

    /**
     * @throws StorageException
     */
    public function trimOllama(int $keep): int;
}
