<?php

declare(strict_types=1);

namespace App\Win;

final readonly class ElevationState
{
    public function __construct(
        public bool $on,
        public ?int $psPid = null,
        public ?string $provedAt = null,
        public ?string $blocked = null,
        public ?string $detail = null,
    ) {
    }

    public function canTry(): bool
    {
        return $this->blocked === null;
    }
}
