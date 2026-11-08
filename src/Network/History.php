<?php

declare(strict_types=1);

namespace App\Network;

use App\Domain\Execution;
use App\Domain\ExecutionKind;
use App\Domain\OllamaExecution;
use App\Services\ExecutionLogService;
use Closure;
use Throwable;

final readonly class History
{
    /** @var Closure(): ExecutionLogService */
    private Closure $makeService;

    /** @param Closure(): ExecutionLogService $makeService */
    public function __construct(Closure $makeService)
    {
        $this->makeService = $makeService;
    }

    public function record(string $event, string $detail = '', bool $ok = true): void
    {
        try {
            ($this->makeService)()->record(new Execution(
                command: 'rede ' . $event,
                output: OllamaExecution::redact($detail),
                exitCode: $ok ? 0 : 1,
                durationMs: 0,
                kind: ExecutionKind::Rede,
                timedOut: false,
            ));
        } catch (Throwable) {
        }
    }
}
