<?php

declare(strict_types=1);

namespace App\Http;

use App\Wsl\DistroStatus;

/**
 * A home: o nome e os dois botões.
 *
 * $wslEnabled significa "dá para usar", não "está rodando agora". A VM do WSL
 * dormir é normal e ela sobe sozinha no primeiro comando — desabilitar o botão
 * por isso mentiria para o usuário. Só desabilita quando o WSL não está
 * instalado ou a distro do .env não existe, e $wslReason diz qual dos dois é.
 */
final readonly class HomeView
{
    public function __construct(
        public bool $wslEnabled,
        public string $wslReason,
        public string $distro,
        public DistroStatus $status,
    ) {
    }
}
