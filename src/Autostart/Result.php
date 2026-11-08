<?php

declare(strict_types=1);

namespace App\Autostart;

final readonly class Result
{
    /**
     * @param list<string> $lines
     */
    public function __construct(public bool $ok, public array $lines)
    {
    }
}
