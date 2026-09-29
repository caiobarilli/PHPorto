<?php

declare(strict_types=1);

namespace App\Http;

use App\Win\HypervListing;

/**
 * A tela /hyperv: a faixa do hospedeiro e a tabela de máquinas virtuais.
 *
 * $blocked é o que impede LISTAR agora: o PowerShell elevado desligado, ou o
 * checkout sem os .ps1. Listar exige elevação porque o Get-VM exige — medido —,
 * e por isso esta tela depende do mesmo interruptor da /win, embora o painel do
 * Hyper-V em si seja decisão persistente.
 *
 * $listing é a leitura já virada dado: null quando $blocked impediu de correr.
 * Ela carrega o próprio problema quando a saída do worker não veio no formato
 * esperado, e é a view que decide, entre bloqueio, problema, hospedeiro
 * desligado e a lista, o que a faixa diz.
 */
final readonly class HypervView
{
    public function __construct(
        public string $tz,
        public ?string $blocked,
        public ?HypervListing $listing,
        public string $readAtUtc,
    ) {
    }
}
