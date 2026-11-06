<?php

declare(strict_types=1);

namespace App\Domain;

final readonly class OllamaExecution
{
    public const USER = '—';

    public const TABLE = 'ollama_executions';

    public function __construct(
        public OllamaExecutionAction $action,
        public string $output,
        public OllamaExecutionStatus $status,
        public int $durationMs,
        public ?string $model = null,
        public ?string $prompt = null,
        public string $user = self::USER,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
        public ?int $id = null,
    ) {
    }

    public function durationSeconds(): float
    {
        return $this->durationMs / 1000;
    }

    public function withRedaction(): self
    {
        return new self(
            action: $this->action,
            output: self::redact($this->output),
            status: $this->status,
            durationMs: $this->durationMs,
            model: $this->model,
            prompt: $this->prompt === null ? null : self::redact($this->prompt),
            user: self::USER,
            createdAt: $this->createdAt,
            updatedAt: $this->updatedAt,
            id: $this->id,
        );
    }

    public static function redact(string $texto): string
    {
        $saida = preg_replace('/(?i)(Authorization\s*:\s*Bearer\s+)\S+/', '$1***', $texto) ?? $texto;
        $saida = preg_replace('/(?i)(Bearer\s+)\S+/', '$1***', $saida) ?? $saida;
        $saida = preg_replace('/(?i)(?<!\w)(-?(?:Password|Pin|Token|Api[_-]?Key|Secret))(?!\w)\s+\S+/', '$1 ***', $saida) ?? $saida;
        $saida = preg_replace('/(?i)\b((?:password|passwd|pwd|pin|token|api[_-]?key|secret)\s*[=:]\s*)\S+/', '$1***', $saida) ?? $saida;
        $saida = preg_replace('/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/s', '[chave privada omitida]', $saida) ?? $saida;

        return $saida;
    }
}
