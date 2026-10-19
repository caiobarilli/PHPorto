<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Domain\Execution;
use App\Domain\ExecutionKind;
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
}
