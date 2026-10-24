<?php

declare(strict_types=1);

namespace App\Php\C;

final readonly class TccStatus
{
    public function __construct(
        public string $dir,
        public ?string $version = null,
        public ?string $libtcc = null,
    ) {
    }

    public function installed(): bool
    {
        return $this->version !== null && $this->libtcc !== null;
    }
}
