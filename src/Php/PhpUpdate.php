<?php

declare(strict_types=1);

namespace App\Php;

final readonly class PhpUpdate
{
    public function __construct(
        public string $status,
        public ?PhpRelease $release = null,
        public ?string $dir = null,
    ) {
    }

    public function available(): bool
    {
        return $this->release !== null && $this->dir !== null;
    }
}
