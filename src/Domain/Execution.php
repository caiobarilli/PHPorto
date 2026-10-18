<?php

declare(strict_types=1);

namespace App\Domain;

final readonly class Execution
{
    public function __construct(
        public string $command,
        public string $output,
        public ?int $exitCode,
        public int $durationMs,
        public ExecutionKind $kind,
        public bool $timedOut,
        public ?string $createdAt = null,
    ) {
    }

    public function durationSeconds(): float
    {
        return $this->durationMs / 1000;
    }
}
