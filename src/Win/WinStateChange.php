<?php

declare(strict_types=1);

namespace App\Win;

use App\Domain\WinStateScope;

/**
 * O que uma execução significa para o estado aplicado da /win.
 *
 * Existe como tipo, e não como par de valores devolvido solto, porque as três
 * informações só fazem sentido juntas: "passou a estar aplicado" sem o payload
 * não permite reverter, e o payload sem a direção não diz se é para aplicar ou
 * esquecer.
 *
 * AUSÊNCIA DESTE OBJETO — o null que o WinAction devolve — significa "esta
 * execução não diz nada sobre estado". É o caso das ações que não são
 * reversíveis e também do `gdid -SubAction status`, que só relata. Ausência
 * NÃO é "não aplicado": quem recebe null não deve mexer no que está guardado.
 *
 * O ESCOPO É O ALVO da mudança, e o padrão é o Applied para as ações que só
 * mexem no estado aplicado. O RDP é a primeira a apontar para outro: ligar e
 * desligar valem na hora e vão para o Applied, mas o vídeo H.264/UDP só passa a
 * valer depois de reiniciar, então vai para o PendingReboot. Uma execução move
 * um escopo só — é assim que "aplicado" e "pendente-de-reinicio" ficam
 * excludentes para o mesmo item, cada mudança gravando o seu.
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
        public WinStateScope $scope = WinStateScope::Applied,
    ) {
    }
}
