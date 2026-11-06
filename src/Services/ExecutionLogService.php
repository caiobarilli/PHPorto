<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Execution;
use App\Domain\ExecutionKind;
use App\Domain\OllamaExecution;
use App\Domain\OllamaExecutionStatus;
use App\Domain\WinState;
use App\Domain\WinStateScope;
use App\Providers\DatabaseProviderInterface;
use InvalidArgumentException;

final class ExecutionLogService
{
    public const MAX_LIMIT = 500;

    public const OLLAMA_RETENTION = 1000;

    public const OLLAMA_PAGE_SIZE = 50;

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

    /**
     * @throws InvalidArgumentException
     * @throws \App\Exceptions\StorageException
     */
    public function recordOllama(OllamaExecution $execution): OllamaExecution
    {
        if ($execution->durationMs < 0) {
            throw new InvalidArgumentException('Duração negativa.');
        }

        if ($execution->action === \App\Domain\OllamaExecutionAction::Chat && ($execution->prompt === null || trim($execution->prompt) === '')) {
            throw new InvalidArgumentException('Prompt vazio.');
        }

        $gravada = $this->provider->insertOllama($execution->withRedaction());
        $this->provider->trimOllama(self::OLLAMA_RETENTION);

        return $gravada;
    }

    /**
     * @throws \App\Exceptions\StorageException
     */
    public function finalizeOllama(int $id, OllamaExecutionStatus $status, string $output, int $durationMs): ?OllamaExecution
    {
        if ($durationMs < 0) {
            throw new InvalidArgumentException('Duração negativa.');
        }

        return $this->provider->finalizeOllama($id, $status, OllamaExecution::redact($output), $durationMs);
    }

    /**
     * @throws \App\Exceptions\StorageException
     */
    public function openOllamaPull(?string $model = null): ?OllamaExecution
    {
        return $this->provider->openOllamaPull($model);
    }

    /**
     * @return array{rows: list<OllamaExecution>, total: int, page: int, pages: int}
     * @throws InvalidArgumentException
     * @throws \App\Exceptions\StorageException
     */
    public function ollamaPage(int $page = 1): array
    {
        if ($page < 1) {
            throw new InvalidArgumentException('Página inválida.');
        }

        $total = $this->provider->countOllama();
        $pages = max(1, (int) ceil($total / self::OLLAMA_PAGE_SIZE));
        $page = min($page, $pages);
        $offset = ($page - 1) * self::OLLAMA_PAGE_SIZE;

        return [
            'rows'  => $this->provider->recentOllama(self::OLLAMA_PAGE_SIZE, $offset),
            'total' => $total,
            'page'  => $page,
            'pages' => $pages,
        ];
    }

    /**
     * @throws \App\Exceptions\StorageException
     */
    public function clearOllama(): int
    {
        return $this->provider->clearOllama();
    }
}
