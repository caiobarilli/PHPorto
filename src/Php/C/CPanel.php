<?php

declare(strict_types=1);

namespace App\Php\C;

final readonly class CPanel
{
    public function __construct(
        public TccStatus $tcc,
        public bool $windows,
        public bool $ffiLoaded,
        public string $ffiEnable,
        public ?CRun $last = null,
    ) {
    }

    public function blocked(): ?string
    {
        if (!$this->windows) {
            return 'A seção C só funciona no Windows: o pacote oficial do Tiny C Compiler é para Windows x64.';
        }

        if (!$this->ffiLoaded) {
            return 'A extensão FFI não está carregada neste PHP. Ative extension=ffi no php.ini e suba o php -S de novo.';
        }

        if ($this->ffiEnable === 'false') {
            return 'ffi.enable está false no php.ini. Mude para true na tabela acima e suba o php -S de novo.';
        }

        return null;
    }

    public function canRun(): bool
    {
        return $this->blocked() === null && $this->tcc->installed();
    }

    public function code(): string
    {
        return $this->last->code ?? CCode::EXAMPLE;
    }
}
