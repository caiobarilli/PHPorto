<?php

declare(strict_types=1);

namespace App\Win;

use App\Domain\WinStateScope;

final readonly class WinStateChange
{
    /**
     * @param bool $applied
     * @param array<string, string|int|bool> $payload
     */
    public function __construct(
        public bool $applied,
        public array $payload = [],
        public WinStateScope $scope = WinStateScope::Applied,
    ) {
    }
}
