<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Execution;
use App\Win\ElevationState;

/**
 * A tela /win: as doze seções do menu do winutil-cli.
 *
 * DOZE E NÃO ONZE, e a diferença é achado, não capricho: o ValidateSet do
 * winutil-cli.ps1 tem gpu, o menu do código mostra [12] GPU e existe um
 * Invoke-GPU.ps1. A lista de onze é a do README, que ficou atrás. A tela
 * espelha o código.
 *
 * $blocked é o que impede executar agora, e tem mais de uma causa: o
 * PHPORTO_WINUTIL_PATH pode não estar configurado, ou o PowerShell elevado
 * pode estar desligado. A tela precisa dizer qual das duas é, porque uma se
 * resolve no .env e a outra num clique na /config.
 *
 * $rows e $result vêm FILTRADOS por tipo Windows. O painel mostra sempre o
 * registro mais recente do banco, e o filtro é o que impede a última execução
 * do WSL de aparecer aqui logo depois de alguém rodar uma ação do Windows —
 * exatamente o momento em que a tela precisa ser confiável.
 */
final readonly class WinView
{
    /**
     * @param list<Execution> $rows           execuções do tipo Windows, mais recentes primeiro
     * @param Execution|null  $result         a mais recente, para o painel de saída
     * @param string|null     $blocked        o que impede executar agora
     * @param ElevationState  $win            estado do PowerShell elevado
     * @param string          $winutilPath    caminho do winutil-cli.ps1
     * @param int             $timeout        segundos até uma ação ser cancelada
     * @param string          $tz             fuso das datas exibidas
     * @param int             $maxOutputBytes teto de saída de uma execução
     * @param int             $maxParamBytes  teto de um campo de texto livre
     */
    public function __construct(
        public array $rows,
        public ?Execution $result,
        public ?string $blocked,
        public ?string $notice,
        public string $csrfToken,
        public string $csrfField,
        public ElevationState $win,
        public string $winutilPath,
        public int $timeout,
        public string $tz,
        public int $maxOutputBytes,
        public int $maxParamBytes,
    ) {
    }
}
