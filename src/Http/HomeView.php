<?php

declare(strict_types=1);

namespace App\Http;

use App\Wsl\DistroStatus;

final readonly class HomeView
{
    public function __construct(
        public bool $wslEnabled,
        public string $wslReason,
        public string $distro,
        public DistroStatus $status,
        public bool $winEnabled,
        public string $winReason,
        public bool $hypervEnabled,
        public string $hypervReason,
    ) {
    }
}
