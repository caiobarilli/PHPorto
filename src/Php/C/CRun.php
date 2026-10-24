<?php

declare(strict_types=1);

namespace App\Php\C;

final readonly class CRun
{
    public function __construct(
        public string $code,
        public string $output,
        public ?int $exitCode,
        public bool $timedOut,
        public int $durationMs,
        public int $at,
    ) {
    }

    /**
     * @return array{code: string, output: string, exit: ?int, timedOut: bool, ms: int, at: int}
     */
    public function toArray(): array
    {
        return [
            'code'     => $this->code,
            'output'   => $this->output,
            'exit'     => $this->exitCode,
            'timedOut' => $this->timedOut,
            'ms'       => $this->durationMs,
            'at'       => $this->at,
        ];
    }

    public static function fromArray(mixed $dados): ?self
    {
        if (!is_array($dados) || !is_string($dados['code'] ?? null) || !is_string($dados['output'] ?? null)) {
            return null;
        }

        $saida = $dados['exit'] ?? null;

        return new self(
            $dados['code'],
            $dados['output'],
            is_int($saida) ? $saida : null,
            ($dados['timedOut'] ?? false) === true,
            is_int($dados['ms'] ?? null) ? $dados['ms'] : 0,
            is_int($dados['at'] ?? null) ? $dados['at'] : 0,
        );
    }
}
