<?php

declare(strict_types=1);

namespace App\Http;

use App\Win\ElevationState;

final readonly class ConfigView
{
    /**
     * @param non-empty-string $provider
     * @param list<array{label: string, value: string}> $details
     * @param bool $apiEnabled
     * @param bool $apiFromEnv
     * @param bool $apiOverridden
     * @param string $corsOrigin
     * @param string $dbPath
     * @param bool $dbExists
     * @param ElevationState $win
     * @param bool $winRetry
     * @param int $winProofTimeout
     * @param bool $hypervEnabled
     * @param ?string $hypervEnabledAt
     * @param bool $uacOk
     * @param string $uacSummary
     * @param string $tz
     */
    public function __construct(
        public string $provider,
        public array $details,
        public bool $apiEnabled,
        public bool $apiFromEnv,
        public bool $apiOverridden,
        public string $corsOrigin,
        public string $dbPath,
        public bool $dbExists,
        public ?string $notice,
        public string $csrfToken,
        public string $csrfField,
        public ElevationState $win,
        public bool $winRetry,
        public int $winProofTimeout,
        public bool $hypervEnabled,
        public ?string $hypervEnabledAt,
        public bool $uacOk,
        public string $uacSummary,
        public string $tz,
    ) {
    }
}
