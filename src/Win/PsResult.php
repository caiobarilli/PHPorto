<?php

declare(strict_types=1);

namespace App\Win;

/**
 * O que voltou de uma execução no PowerShell, ainda cru.
 *
 * Espelha a RunResult do WSL e NÃO é a mesma classe de propósito: carrega
 * $truncated, que o lado WSL não tem porque o Runner de lá ainda lê a saída
 * inteira. Compartilhar a classe faria o campo aparecer nos dois lugares
 * significando "sempre falso" em um deles — e campo que só um lado preenche é
 * onde alguém confia num valor que ninguém escreveu.
 */
final readonly class PsResult
{
    public function __construct(
        public string $output,
        public ?int $exitCode,
        public bool $timedOut,
        public int $durationMs,
        /** Saída cortada no teto do PsRunner. A tela precisa avisar. */
        public bool $truncated = false,
    ) {
    }
}
