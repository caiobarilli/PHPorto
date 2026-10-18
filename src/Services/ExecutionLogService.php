<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Execution;
use App\Domain\ExecutionKind;
use App\Domain\WinState;
use App\Domain\WinStateScope;
use App\Providers\DatabaseProviderInterface;
use InvalidArgumentException;

final class ExecutionLogService
{
    public const MAX_LIMIT = 500;

    public function __construct(
        private readonly DatabaseProviderInterface $provider
    ) {
    }

    /**
     * @throws InvalidArgumentException
     * @throws \App\Exceptions\StorageException
     */
    public function record(Execution $execution): Execution
    {
        if (trim($execution->command) === '') {
            throw new InvalidArgumentException('Comando vazio.');
        }

        if ($execution->durationMs < 0) {
            throw new InvalidArgumentException('Duração negativa.');
        }

        return $this->provider->insert($execution);
    }

    /**
     * @param list<ExecutionKind>|null $kinds
     * @return list<Execution>
     * @throws InvalidArgumentException
     */
    public function recent(int $limit = 100, ?array $kinds = null): array
    {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidArgumentException('Limite fora da faixa (1-' . self::MAX_LIMIT . ').');
        }

        return $this->provider->recent($limit, $kinds);
    }

    /**
     * @param list<ExecutionKind>|null $kinds
     */
    public function clear(?array $kinds = null): int
    {
        return $this->provider->clear($kinds);
    }

    /**
     * @throws InvalidArgumentException
     * @throws \App\Exceptions\StorageException
     */
    public function putWinState(WinState $state): WinState
    {
        if (trim($state->action) === '') {
            throw new InvalidArgumentException('Ação vazia no estado da /win.');
        }

        return $this->provider->putWinState($state);
    }

    /**
     * @return array<string, WinState>
     * @throws \App\Exceptions\StorageException
     */
    public function winStates(WinStateScope $scope): array
    {
        return $this->provider->winStates($scope);
    }

    /** @throws \App\Exceptions\StorageException */
    public function forgetWinState(WinStateScope $scope, string $action): int
    {
        return $this->provider->forgetWinState($scope, $action);
    }
}
