<?php

declare(strict_types=1);

namespace App\Wsl;

enum DistroStatus
{
    case Ok;

    case WslMissing;

    case DistroMissing;


    public function isUsable(): bool
    {
        return $this === self::Ok;
    }

    public function reason(string $distro): string
    {
        return match ($this) {
            self::Ok            => '',
            self::WslMissing    => 'WSL não encontrado nesta máquina (wsl.exe não respondeu).',
            self::DistroMissing => $distro === ''
                ? 'PHPORTO_DISTRO está vazio no .env.'
                : sprintf('A distro "%s" do .env não aparece em "wsl -l -q".', $distro),
        };
    }
}
