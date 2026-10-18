<?php

declare(strict_types=1);

namespace App\Http;

enum AuthOutcome
{
    case Allowed;

    case NotConfigured;

    case Missing;

    case Wrong;

    case Expired;

    case Locked;

    public function status(): int
    {
        return match ($this) {
            self::Allowed       => 0,
            self::NotConfigured => 503,
            self::Missing,
            self::Wrong,
            self::Expired       => 401,
            self::Locked        => 429,
        };
    }
}
