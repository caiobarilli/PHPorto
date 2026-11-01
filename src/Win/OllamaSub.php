<?php

declare(strict_types=1);

namespace App\Win;

enum OllamaSub: string
{
    case Status = 'status';
    case List = 'list';
    case Install = 'install';
    case Uninstall = 'uninstall';
    case Start = 'start';
    case Stop = 'stop';
    case Pull = 'pull';
    case Remove = 'remove';

    public function needsModel(): bool
    {
        return $this === self::Pull || $this === self::Remove;
    }

    public static function parse(mixed $valor): self
    {
        $sub = is_string($valor) ? self::tryFrom($valor) : null;

        if ($sub === null) {
            throw new \InvalidArgumentException('Subação desconhecida.');
        }

        return $sub;
    }
}
