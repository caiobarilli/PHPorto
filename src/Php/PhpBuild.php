<?php

declare(strict_types=1);

namespace App\Php;

final readonly class PhpBuild
{
    public function __construct(
        public string $version,
        public bool $threadSafe,
        public string $binary,
        public ?string $ini,
        public string $sapi,
        public ?string $compiler = null,
        public ?string $arch = null,
        public ?bool $uploadProgress = null,
        public bool $uploadProgressDefault = false,
    ) {
    }

    public function tag(): string
    {
        return $this->threadSafe ? 'TS' : 'NTS';
    }

    public function thread(): string
    {
        $partes = [$this->tag()];

        foreach ([$this->compiler, $this->arch] as $parte) {
            if ($parte !== null && $parte !== '') {
                $partes[] = $parte;
            }
        }

        return implode(' ', $partes);
    }
}
