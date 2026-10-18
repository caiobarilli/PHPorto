<?php

declare(strict_types=1);

namespace App\Wsl;

final readonly class RunResult
{
    public function __construct(
        public string $output,
        public ?int $exitCode,
        public bool $timedOut,
        public int $durationMs,
        public bool $truncated = false,
    ) {
    }
}
