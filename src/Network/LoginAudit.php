<?php

declare(strict_types=1);

namespace App\Network;

use App\Domain\Execution;
use App\Domain\ExecutionKind;
use App\Domain\OllamaExecution;
use App\Http\AuthOutcome;
use App\Http\LoginResult;
use App\Services\ExecutionLogService;
use Closure;
use Throwable;

final readonly class LoginAudit
{
    /** @var Closure(): ExecutionLogService */
    private Closure $makeService;

    /** @param Closure(): ExecutionLogService $makeService */
    public function __construct(private ?State $network, Closure $makeService)
    {
        $this->makeService = $makeService;
    }

    /** @param array<array-key, mixed> $server */
    public function record(array $server, LoginResult $result): void
    {
        $ip = LoginGate::ip($server);

        if ($ip === null || !LoginGate::appliesTo($server, $this->network) || !$result->attempt) {
            return;
        }

        $allowed = $result->decision->outcome === AuthOutcome::Allowed;
        $output = OllamaExecution::redact(sprintf(
            'login de fora %s; ip=%s; motivo=%s',
            $allowed ? 'aceito' : 'recusado',
            $ip,
            $result->decision->outcome->name,
        ));

        try {
            ($this->makeService)()->record(new Execution(
                command: 'rede login de fora',
                output: $output,
                exitCode: $allowed ? 0 : 1,
                durationMs: 0,
                kind: ExecutionKind::Rede,
                timedOut: false,
            ));
        } catch (Throwable) {
        }
    }
}
