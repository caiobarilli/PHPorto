<?php

declare(strict_types=1);

namespace App\Http;

use App\Wsl\DistroStatus;

/**
 * A home: o nome e os três botões.
 *
 * $wslEnabled significa "dá para usar", não "está rodando agora". A VM do WSL
 * dormir é normal e ela sobe sozinha no primeiro comando — desabilitar o botão
 * por isso mentiria para o usuário. Só desabilita quando o WSL não está
 * instalado ou a distro do .env não existe, e $wslReason diz qual dos dois é.
 *
 * $winEnabled É OUTRA COISA, e a diferença importa. Aqui não há nada
 * equivalente à VM dormindo: ou existe um PowerShell elevado de pé nesta
 * execução do servidor, ou não existe. O botão WIN NASCE DESABILITADO por
 * construção — subir o servidor não eleva nada, e o que o habilita é o
 * interruptor da /config. Por isso $winReason costuma apontar para lá, em vez
 * de descrever um defeito.
 */
final readonly class HomeView
{
    public function __construct(
        public bool $wslEnabled,
        public string $wslReason,
        public string $distro,
        public DistroStatus $status,
        public bool $winEnabled,
        public string $winReason,
    ) {
    }
}
