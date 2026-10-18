<?php

declare(strict_types=1);

namespace App\Domain;

enum ExecutionKind: string
{
    case Comando = 'comando';
    case Anexo = 'anexo';

    case Windows = 'windows';

    public const WSL = [self::Comando, self::Anexo];

    public static function fromStorage(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        return is_string($value) ? (self::tryFrom($value) ?? self::Comando) : self::Comando;
    }
}
