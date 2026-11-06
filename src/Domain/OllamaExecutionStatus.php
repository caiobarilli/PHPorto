<?php

declare(strict_types=1);

namespace App\Domain;

enum OllamaExecutionStatus: string
{
    case Iniciado = 'iniciado';
    case Ok = 'ok';
    case Erro = 'erro';

    public static function fromStorage(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        return is_string($value) ? (self::tryFrom($value) ?? self::Erro) : self::Erro;
    }
}
