<?php

declare(strict_types=1);

namespace App\Http;

final readonly class LayoutView
{
    public function __construct(
        public string $title,
        public string $content,
    ) {
    }
}
