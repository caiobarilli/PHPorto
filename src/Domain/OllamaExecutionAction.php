<?php

declare(strict_types=1);

namespace App\Domain;

enum OllamaExecutionAction: string
{
    case Pull = 'pull';
    case Remove = 'remove';
    case Install = 'install';
    case Update = 'update';
    case Chat = 'chat';

    public static function fromStorage(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        return is_string($value) ? (self::tryFrom($value) ?? self::Pull) : self::Pull;
    }
}
