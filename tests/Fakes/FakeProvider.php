<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Domain\Execution;
use App\Domain\ExecutionKind;
use App\Domain\OllamaExecution;
use App\Domain\OllamaExecutionAction;
use App\Domain\OllamaExecutionStatus;
use App\Domain\WinState;
use App\Domain\WinStateScope;
use App\Providers\DatabaseProviderInterface;

final class FakeProvider implements DatabaseProviderInterface
{
    /** @var list<Execution> */
    public array $inserted = [];

    public int $clearCalls = 0;

    /**
     * @var array<string, WinState>
     */
    public array $winState = [];

    /** @var list<OllamaExecution> */
    public array $ollama = [];

    private int $ollamaSeq = 0;

    public function insert(Execution $execution): Execution
    {
        $stored = new Execution(
            command: $execution->command,
            output: $execution->output,
            exitCode: $execution->exitCode,
            durationMs: $execution->durationMs,
            kind: $execution->kind,
            timedOut: $execution->timedOut,
            createdAt: $execution->createdAt ?? gmdate(DATE_ATOM),
        );

        $this->inserted[] = $stored;

        return $stored;
    }

    public function recent(int $limit = 100, ?array $kinds = null): array
    {
        $todas = array_reverse($this->inserted);

        if ($kinds !== null) {
            $todas = array_values(array_filter(
                $todas,
                static fn (Execution $e): bool => in_array($e->kind, $kinds, true)
            ));
        }

        return array_slice($todas, 0, $limit);
    }

    public function clear(?array $kinds = null): int
    {
        $this->clearCalls++;

        if ($kinds === null) {
            $n              = count($this->inserted);
            $this->inserted = [];

            return $n;
        }

        $antes          = count($this->inserted);
        $this->inserted = array_values(array_filter(
            $this->inserted,
            static fn (Execution $e): bool => !in_array($e->kind, $kinds, true)
        ));

        return $antes - count($this->inserted);
    }

    public function putWinState(WinState $state): WinState
    {
        $stored = new WinState(
            scope: $state->scope,
            action: $state->action,
            payload: $state->payload,
            updatedAt: $state->updatedAt ?? gmdate(DATE_ATOM),
        );

        $this->winState[$stored->key()] = $stored;

        return $stored;
    }

    public function winStates(WinStateScope $scope): array
    {
        $estados = [];

        foreach ($this->winState as $estado) {
            if ($estado->scope !== $scope) {
                continue;
            }

            $estados[$estado->action] = $estado;
        }

        return $estados;
    }

    public function forgetWinState(WinStateScope $scope, string $action): int
    {
        $chave = (new WinState($scope, $action))->key();

        if (!isset($this->winState[$chave])) {
            return 0;
        }

        unset($this->winState[$chave]);

        return 1;
    }

    public function insertOllama(OllamaExecution $execution): OllamaExecution
    {
        $safe = $execution->withRedaction();
        $this->ollamaSeq++;
        $stored = new OllamaExecution(
            action: $safe->action,
            output: $safe->output,
            status: $safe->status,
            durationMs: $safe->durationMs,
            model: $safe->model,
            prompt: $safe->prompt,
            user: OllamaExecution::USER,
            createdAt: $safe->createdAt ?? gmdate(DATE_ATOM),
            updatedAt: $safe->updatedAt,
            id: $this->ollamaSeq,
        );
        $this->ollama[] = $stored;

        return $stored;
    }

    public function finalizeOllama(int $id, OllamaExecutionStatus $status, string $output, int $durationMs): ?OllamaExecution
    {
        foreach ($this->ollama as $i => $row) {
            if ($row->id !== $id || $row->status !== OllamaExecutionStatus::Iniciado) {
                continue;
            }

            $updated = new OllamaExecution(
                action: $row->action,
                output: OllamaExecution::redact($output),
                status: $status,
                durationMs: max(0, $durationMs),
                model: $row->model,
                prompt: $row->prompt,
                user: OllamaExecution::USER,
                createdAt: $row->createdAt,
                updatedAt: gmdate(DATE_ATOM),
                id: $row->id,
            );
            $this->ollama[$i] = $updated;

            return $updated;
        }

        return null;
    }

    public function openOllamaPull(?string $model = null): ?OllamaExecution
    {
        for ($i = count($this->ollama) - 1; $i >= 0; $i--) {
            $row = $this->ollama[$i];

            if ($row->status !== OllamaExecutionStatus::Iniciado || $row->action !== OllamaExecutionAction::Pull) {
                continue;
            }

            if ($model !== null && $row->model !== $model) {
                continue;
            }

            return $row;
        }

        return null;
    }

    public function recentOllama(int $limit, int $offset = 0): array
    {
        $todas = array_reverse($this->ollama);

        return array_slice($todas, $offset, $limit);
    }

    public function countOllama(): int
    {
        return count($this->ollama);
    }

    public function clearOllama(): int
    {
        $n = count($this->ollama);
        $this->ollama = [];

        return $n;
    }

    public function trimOllama(int $keep): int
    {
        $total = count($this->ollama);

        if ($total <= $keep) {
            return 0;
        }

        $excess = $total - $keep;
        $this->ollama = array_values(array_slice($this->ollama, $excess));

        return $excess;
    }
}
