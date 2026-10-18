<?php

declare(strict_types=1);

namespace App\Domain;

enum WinStateScope: string
{
    case Applied = 'aplicado';

    case Selection = 'selecao';

    case PendingReboot = 'pendente-de-reinicio';

    public static function fromStorage(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        return is_string($value) ? self::tryFrom($value) : null;
    }
}
