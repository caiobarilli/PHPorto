<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Execution;

final readonly class WslView
{
    /**
     * @param list<Execution> $rows
     * @param ?Execution $result
     * @param ?string $blocked
     * @param ?string $notice
     * @param int $maxOutputBytes
     * @param int $maxCommandBytes
     * @param int $maxPathBytes
     * @param ?bool $awake
     * @param int $maxUploadBytes
     */
    public function __construct(
        public array $rows,
        public ?Execution $result,
        public ?string $blocked,
        public ?string $notice,
        public string $csrfToken,
        public string $csrfField,
        public string $distro,
        public string $root,
        public int $timeout,
        public string $tz,
        public int $coldStartSeconds,
        public int $maxOutputBytes,
        public int $maxCommandBytes,
        public int $maxPathBytes,
        public int $maxUploadBytes,
        public ?bool $awake = null,
    ) {
    }
}
