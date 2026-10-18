<?php

declare(strict_types=1);

namespace App\Win;

final readonly class PsResult
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
