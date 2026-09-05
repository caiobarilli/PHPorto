<?php

declare(strict_types=1);

namespace App\Http;

/** O documento inteiro: título e o miolo já renderizado. */
final readonly class LayoutView
{
    public function __construct(
        public string $title,
        public string $content,
    ) {
    }
}
