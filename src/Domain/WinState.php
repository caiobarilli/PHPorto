<?php

declare(strict_types=1);

namespace App\Domain;

final readonly class WinState
{
    /**
     * @param string $action
     * @param array<string, string|int|bool> $payload
     * @param string|null $updatedAt
     */
    public function __construct(
        public WinStateScope $scope,
        public string $action,
        public array $payload = [],
        public ?string $updatedAt = null,
    ) {
    }

    public function key(): string
    {
        return $this->scope->value . ':' . $this->action;
    }

    public function encodedPayload(): string
    {
        if ($this->payload === []) {
            return '{}';
        }

        $json = json_encode($this->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json === false ? '{}' : $json;
    }

    /**
     * @return array<string, string|int|bool>|null
     */
    public static function decodePayload(mixed $raw): ?array
    {
        if (is_array($raw)) {
            $raw = json_encode($raw);
        }

        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return null;
        }

        if ($decoded !== [] && array_is_list($decoded)) {
            return null;
        }

        $limpo = [];

        foreach ($decoded as $chave => $valor) {
            if (is_string($chave) && (is_string($valor) || is_int($valor) || is_bool($valor))) {
                $limpo[$chave] = $valor;
            }
        }

        return $limpo;
    }
}
