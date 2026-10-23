<?php

declare(strict_types=1);

namespace App\Php;

final readonly class IniForm
{
    /**
     * @param list<IniRow> $rows
     */
    public function __construct(
        public string $path,
        public string $hash,
        public array $rows,
        public bool $writable,
        public ?string $error = null,
    ) {
    }
}
