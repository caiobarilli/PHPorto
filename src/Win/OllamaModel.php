<?php

declare(strict_types=1);

namespace App\Win;

enum OllamaModel: string
{
    case Qwen3 = 'qwen3:4b';
    case Llama32 = 'llama3.2:3b';

    public function sizeMb(): int
    {
        return match ($this) {
            self::Qwen3   => 2560,
            self::Llama32 => 2048,
        };
    }

    public function minVramMb(): int
    {
        return $this->sizeMb() + 1536;
    }

    public static function parse(mixed $valor): self
    {
        $modelo = is_string($valor) ? self::tryFrom($valor) : null;

        if ($modelo === null) {
            throw new \InvalidArgumentException('Modelo fora da lista permitida.');
        }

        return $modelo;
    }
}
