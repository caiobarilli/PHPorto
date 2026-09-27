<?php

declare(strict_types=1);

namespace App\Http;

/** A decisão do portão de token, com os segundos restantes quando bloqueado. */
final readonly class AuthDecision
{
    public function __construct(
        public AuthOutcome $outcome,
        public int $retryAfter = 0,
    ) {
    }
}
