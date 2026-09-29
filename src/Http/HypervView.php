<?php

declare(strict_types=1);

namespace App\Http;

/**
 * A tela /hyperv: a faixa do hospedeiro e a tabela de máquinas virtuais.
 *
 * Nasce enxuta de propósito. Nesta fatia a rota só responde, com a faixa do
 * hospedeiro; a leitura das VMs, que exige o PowerShell elevado, e as colunas
 * da tabela entram nas fatias seguintes, e esta classe cresce com elas.
 */
final readonly class HypervView
{
    public function __construct(
        public string $tz,
    ) {
    }
}
