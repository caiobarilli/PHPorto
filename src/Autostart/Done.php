<?php

declare(strict_types=1);

namespace App\Autostart;

final readonly class Done
{
    public function __construct(public bool $ok, public string $message)
    {
    }
}
