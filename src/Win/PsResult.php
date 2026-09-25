<?php

declare(strict_types=1);

namespace App\Win;

/**
 * O que voltou de uma execução no PowerShell, ainda cru.
 */
final readonly class PsResult
{
    public function __construct(
        public string $output,
        public ?int $exitCode,
        public bool $timedOut,
        public int $durationMs,
        /** Saída cortada no teto do OutputCap. */
        public bool $truncated = false,
    ) {
    }
}
