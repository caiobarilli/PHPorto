<?php

declare(strict_types=1);

namespace App\Http;

use App\Win\HypervListing;

final readonly class HypervView
{
    public const PARTE_CASCA = 'casca';

    public const PARTE_TABELA = 'tabela';

    public const PARTE_COMPLETA = 'completa';

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
        public string $parte = self::PARTE_COMPLETA,
    ) {
    }
}
