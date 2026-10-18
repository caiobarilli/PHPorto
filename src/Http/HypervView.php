<?php

declare(strict_types=1);

namespace App\Http;

use App\Win\HypervListing;

final readonly class HypervView
{
    public function __construct(
        public string $tz,
        public ?string $blocked,
        public ?HypervListing $listing,
        public string $readAtUtc,
        public bool $timedOut = false,
        public ?string $notice = null,
        public string $csrfToken = '',
        public string $csrfField = '',
        public ?string $actionsBlocked = null,
    ) {
    }
}
