<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Domain\Execution;
use App\Domain\ExecutionKind;
use App\Domain\WinState;
use App\Domain\WinStateScope;
use App\Providers\DatabaseProviderInterface;

/**
 * Provider em memória para os testes de unidade do ExecutionLogService.
 *
 * Implementa o mesmo contrato dos providers reais sem nenhuma dependência de
 * rede ou banco, e guarda o que recebeu para inspeção.
 */
final class FakeProvider implements DatabaseProviderInterface
{
    /** @var list<Execution> na ordem em que chegaram */
    public array $inserted = [];

    public int $clearCalls = 0;

    /**
     * O estado da /win, indexado pela chave composta, como nos providers reais.
     *
     * @var array<string, WinState>
     */
    public array $winState = [];

    /** Carimba o created_at como um banco faria, para o teste ver o retorno. */
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

    /**
     * Filtra ANTES de aplicar o limite, como os providers reais fazem na
     * query. Um fake que limitasse primeiro e filtrasse depois passaria em
     * teste e esconderia justamente o defeito que o filtro no banco evita.
     */
    public function recent(int $limit = 100, ?ExecutionKind $kind = null): array
    {
        $todas = array_reverse($this->inserted);

        if ($kind !== null) {
            $todas = array_values(array_filter(
                $todas,
                static fn (Execution $e): bool => $e->kind === $kind
            ));
        }

        return array_slice($todas, 0, $limit);
    }

    /**
     * NÃO TOCA NO winState, e é justamente o que este fake precisa provar:
     * limpar histórico e mudar o que a tela afirma sobre a máquina são coisas
     * diferentes. Um fake que apagasse os dois deixaria o teste passar e
     * esconderia o defeito.
     */
    public function clear(?ExecutionKind $kind = null): int
    {
        $this->clearCalls++;

        if ($kind === null) {
            $n              = count($this->inserted);
            $this->inserted = [];

            return $n;
        }

        $antes          = count($this->inserted);
        $this->inserted = array_values(array_filter(
            $this->inserted,
            static fn (Execution $e): bool => $e->kind !== $kind
        ));

        return $antes - count($this->inserted);
    }

    /** Carimba o updated_at como um banco faria, e sobrescreve a chave. */
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
