<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Execution;
use App\Domain\ExecutionKind;
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
}
