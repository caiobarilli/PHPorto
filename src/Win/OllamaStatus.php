<?php

declare(strict_types=1);

namespace App\Win;

final readonly class OllamaStatus
{
    public function __construct(
        public string $nivel,
        public bool $installed,
        public int $processes,
        public ?string $hostEnv,
        public ?string $gpu,
        public ?int $vramMb,
        public ?int $vramUsedMb,
        public bool $vramApprox,
    ) {
    }

    public static function parse(string $output): self
    {
        $gpu = null;
        $total = null;
        $usado = null;
        $aprox = false;
        $linha = Ollama::marker($output, 'GPU');

        if ($linha !== null && preg_match('/^(.+?),\s*(\d+),\s*(\d+)$/', $linha, $m) === 1) {
            [$gpu, $total, $usado] = [trim($m[1]), (int) $m[2], (int) $m[3]];
        } else {
            $linha = Ollama::marker($output, 'GPUAPROX');

            if ($linha !== null && preg_match('/^(.+?),\s*(\d+)$/', $linha, $m) === 1) {
                [$gpu, $total, $aprox] = [trim($m[1]), (int) $m[2], true];
            }
        }

        $proc = Ollama::marker($output, 'PROC');

        return new self(
            nivel: Ollama::nivel($output),
            installed: Ollama::marker($output, 'EXE') === 'sim',
            processes: $proc !== null && ctype_digit($proc) ? (int) $proc : 0,
            hostEnv: Ollama::marker($output, 'HOSTENV'),
            gpu: $gpu,
            vramMb: $total !== null && $total > 0 ? $total : null,
            vramUsedMb: $usado,
            vramApprox: $aprox,
        );
    }
}
