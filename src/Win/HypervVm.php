<?php

declare(strict_types=1);

namespace App\Win;

/**
 * Uma máquina virtual, como o Invoke-Hyperv a devolveu.
 *
 * Dado puro: quem formata memória, uptime e IP para a tela é a view, do mesmo
 * jeito que a /wsl formata a saída dela. O `ip` é a lista que o Windows
 * conseguiu ler; vazia com a VM rodando quer dizer "não deu para ler", e é a
 * tela que diz isso — o valor não chuta.
 */
final readonly class HypervVm
{
    /**
     * @param list<string> $ip
     */
    public function __construct(
        public string $name,
        public string $state,
        public bool $running,
        public int $memoryBytes,
        public int $uptimeSeconds,
        public array $ip,
    ) {
    }
}
