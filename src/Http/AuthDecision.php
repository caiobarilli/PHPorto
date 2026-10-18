<?php

declare(strict_types=1);

namespace App\Http;

final readonly class AuthDecision
{
    public function __construct(
        public AuthOutcome $outcome,
        public int $retryAfter = 0,
    ) {
    }
}
