<?php

declare(strict_types=1);

namespace App\Network;

final readonly class Report
{
    public function __construct(
        public State $state,
        public ?bool $ownsIp,
        public Observation $observation,
        public Plan $plan,
        public ?bool $listenerResponds,
    ) {
    }
}
