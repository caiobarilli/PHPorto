<?php

declare(strict_types=1);

namespace App\Http;

use App\Network\State;

final readonly class LayoutView
{
    public function __construct(
        public string $title,
        public string $content,
        public ?State $network = null,
    ) {
    }
}
