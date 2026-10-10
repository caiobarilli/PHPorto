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

    /**
     * Os endereços que servem para alguma coisa, na ordem em que servem.
     *
     * IPv4 primeiro, porque é o que se digita no RDP e no navegador; depois os
     * IPv6 que sobram. O link-local (fe80::) sai: não sai da rede local da VM e
     * só confunde. Lista vazia é "não deu para ler um endereço útil".
     *
     * @return list<string>
     */
    public function usefulIps(): array
    {
        $v4 = [];
        $v6 = [];

        foreach ($this->ip as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                $v4[] = $ip;
            } elseif (stripos($ip, 'fe80:') !== 0) {
                $v6[] = $ip;
            }
        }

        return array_merge($v4, $v6);
    }
}
