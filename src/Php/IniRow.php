<?php

declare(strict_types=1);

namespace App\Php;

final readonly class IniRow
{
    public function __construct(
        public IniKey $key,
        public string $value,
        public bool $inFile,
        public ?string $running = null,
        public ?string $overriddenBy = null,
    ) {
    }

    public function differsFromDefault(): bool
    {
        return !self::same($this->key, $this->value, $this->key->default());
    }

    public function pendingRestart(): bool
    {
        return $this->running !== null && !self::same($this->key, $this->value, $this->running);
    }

    public static function same(IniKey $chave, string $a, string $b): bool
    {
        $a = $chave->normalize($a);
        $b = $chave->normalize($b);

        if ($a === $b) {
            return true;
        }

        $ba = IniKey::bytes($a);
        $bb = IniKey::bytes($b);

        return !$chave->isSwitch() && $chave !== IniKey::Timezone && $ba !== null && $ba === $bb;
    }
}
