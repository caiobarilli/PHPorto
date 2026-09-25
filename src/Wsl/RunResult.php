<?php

declare(strict_types=1);

namespace App\Wsl;

/**
 * O que voltou de uma execução no WSL, ainda cru — antes de virar registro.
 *
 * Separado da Execution de propósito: aqui não há comando nem tipo, porque o
 * motor não sabe o que está rodando nem por quê. Quem junta as duas coisas é
 * a camada HTTP.
 */
final readonly class RunResult
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
