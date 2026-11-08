<?php

declare(strict_types=1);

namespace App\Network;

final readonly class FirewallResult
{
    public function __construct(public bool $ok, public string $message)
    {
    }
}
