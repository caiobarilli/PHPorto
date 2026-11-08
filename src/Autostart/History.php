<?php

declare(strict_types=1);

namespace App\Autostart;

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
                command: 'iniciar com o Windows ' . $event,
                output: OllamaExecution::redact($detail),
                exitCode: $ok ? 0 : 1,
                durationMs: 0,
                kind: ExecutionKind::Windows,
                timedOut: false,
            ));
        } catch (Throwable) {
        }
    }
}
