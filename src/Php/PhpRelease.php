<?php

declare(strict_types=1);

namespace App\Php;

final readonly class PhpRelease
{
    public function __construct(
        public string $version,
        public string $sha256,
        public string $file,
    ) {
    }

    public function url(): string
    {
        return PhpReleases::BASE_URL . $this->file;
    }
}
