<?php

declare(strict_types=1);

namespace App\Http;

final readonly class LoginResult
{
    public function __construct(
        public AuthDecision $decision,
        public bool $attempt,
    ) {
    }
}
