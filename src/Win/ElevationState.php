<?php

declare(strict_types=1);

namespace App\Win;

/**
 * O estado do interruptor de PowerShell, do ponto de vista da tela.
 *
 * TRÊS RESPOSTAS E NÃO UM BOOLEANO, pelo mesmo motivo do DistroStatus: a tela
 * precisa dizer QUAL é o problema. "Não configurado", "desligado" e "o
 * elevado parou de responder" têm soluções diferentes, e uma mensagem
 * genérica manda a pessoa procurar no lugar errado.
 *
 * $blocked é problema de CONFIGURAÇÃO: nem faz sentido tentar ligar. $detail é
 * o que aconteceu com uma tentativa que já houve.
 */
final readonly class ElevationState
{
    public function __construct(
        public bool $on,
        public ?int $psPid = null,
        public ?string $provedAt = null,
        public ?string $blocked = null,
        public ?string $detail = null,
    ) {
    }

    /** Se dá para tentar ligar. Configuração faltando é o único impedimento. */
    public function canTry(): bool
    {
        return $this->blocked === null;
    }
}
