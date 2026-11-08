<?php

declare(strict_types=1);

namespace App\Network;

final readonly class Launch
{
    public function __construct(
        public bool $enabled,
        public bool $start,
        public ?string $reason,
        public ?State $state,
    ) {
    }
}
