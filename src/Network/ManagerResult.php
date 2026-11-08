<?php

declare(strict_types=1);

namespace App\Network;

final readonly class ManagerResult
{
    public const OK = 'ok';

    public const USAGE = 'uso';

    public const FAILURE = 'falha';

    /**
     * @param list<string> $lines
     */
    public function __construct(
        public string $kind,
        public array $lines,
        public bool $closed = false,
    ) {
    }

    public function ok(): bool
    {
        return $this->kind === self::OK;
    }
}
