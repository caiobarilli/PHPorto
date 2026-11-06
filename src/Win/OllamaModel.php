<?php

declare(strict_types=1);

namespace App\Win;

final readonly class OllamaModel
{
    public const MAX_LEN = 128;

    public const OVERHEAD_VRAM_MB = 1536;

    private const KNOWN_SIZE_MB = [
        'qwen3:4b' => 2560,
        'llama3.2:3b' => 2048,
    ];

    public function __construct(public string $value)
    {
    }

    /**
     * @return list<self>
     */
    public static function recommended(): array
    {
        $lista = [];

        foreach (array_keys(self::KNOWN_SIZE_MB) as $nome) {
            $lista[] = new self($nome);
        }

        return $lista;
    }

    public static function isRecommended(string $nome): bool
    {
        return isset(self::KNOWN_SIZE_MB[$nome]);
    }

    public static function isValid(string $nome): bool
    {
        if ($nome === '' || strlen($nome) > self::MAX_LEN) {
            return false;
        }

        return preg_match(
            '/\A(?:[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?\/){0,2}[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?(?::[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?)?\z/',
            $nome,
        ) === 1;
    }

    public static function parse(mixed $valor): self
    {
        if (!is_string($valor) || !self::isValid($valor)) {
            throw new \InvalidArgumentException('Nome de modelo inválido.');
        }

        return new self($valor);
    }

    public function sizeMb(): ?int
    {
        return self::KNOWN_SIZE_MB[$this->value] ?? null;
    }

    public function minVramMb(): ?int
    {
        $tamanho = $this->sizeMb();

        return $tamanho === null ? null : $tamanho + self::OVERHEAD_VRAM_MB;
    }

    public static function minVramFromBytes(int $bytes): int
    {
        return intdiv(max(0, $bytes), 1048576) + self::OVERHEAD_VRAM_MB;
    }

    public static function sizeMbFromBytes(int $bytes): int
    {
        return intdiv(max(0, $bytes), 1048576);
    }
}
