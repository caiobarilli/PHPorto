<?php

declare(strict_types=1);

namespace App\Win;

/**
 * O que uma execução significa para o estado aplicado da /win.
 *
 * Existe como tipo, e não como par de valores devolvido solto, porque as duas
 * informações só fazem sentido juntas: "passou a estar aplicado" sem o payload
 * não permite reverter, e o payload sem a direção não diz se é para aplicar ou
 * esquecer.
 *
 * AUSÊNCIA DESTE OBJETO — o null que o WinAction devolve — significa "esta
 * execução não diz nada sobre estado". É o caso das nove ações que não são
 * reversíveis e também do `gdid -SubAction status`, que só relata. Ausência
 * NÃO é "não aplicado": quem recebe null não deve mexer no que está guardado.
 */
final readonly class WinStateChange
{
    /**
     * @param bool                           $applied true grava a linha, false a esquece
     * @param array<string, string|int|bool> $payload o que a reversão vai precisar de volta —
     *                                                para o tweaks, o Preset que o -Undo exige
     */
    public function __construct(
        public bool $applied,
        public array $payload = [],
    ) {
    }
}
