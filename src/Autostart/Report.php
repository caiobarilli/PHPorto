<?php

declare(strict_types=1);

namespace App\Autostart;

final readonly class Report
{
    public function __construct(
        public bool $enabled,
        public TaskObservation $task,
        public bool $matches,
        public bool $serving,
        public ?string $level = null,
    ) {
    }
}
