<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Domain\Execution;
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

    public function recent(int $limit = 100): array
    {
        return array_slice(array_reverse($this->inserted), 0, $limit);
    }

    public function clear(): int
    {
        $this->clearCalls++;
        $n = count($this->inserted);
        $this->inserted = [];

        return $n;
    }
}
