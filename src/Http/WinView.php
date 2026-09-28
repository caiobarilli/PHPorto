<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Execution;
use App\Win\ElevationState;

/**
 * A tela /win: as quatorze seções das ações do Windows.
 *
 * QUATORZE, e a contagem tem história: a tela nasceu com doze porque o README
 * do winutil-cli listava onze e o código tinha gpu — a tela seguiu o código. A
 * décima terceira é o gdid, que existia em src/Win/actions desde a migração mas
 * só passou a ser alcançável quando entrou nas DUAS allowlists. A décima quarta
 * é o rdp, que nasceu aqui, na aba Acesso Remoto.
 *
 * $blocked é o que impede executar agora, e tem mais de uma causa: o checkout
 * pode estar sem os .ps1 de src/Win, ou o PowerShell elevado pode estar
 * desligado. A tela precisa dizer qual das duas é, porque uma se resolve
 * refazendo o clone e a outra num clique na /config.
 *
 * A primeira causa já foi outra: até a migração, era uma chave do .env
 * apontando para um projeto externo. Não há mais o que configurar.
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
     * @param int             $timeout        segundos até uma ação ser cancelada
     * @param string          $tz             fuso das datas exibidas
     * @param int             $maxOutputBytes teto de saída de uma execução
     * @param int             $maxParamBytes  teto de um campo de texto livre
     * @param list<string>    $debloatPackages os pacotes APPX do config/debloat.json
     * @param string|null     $debloatProblem  por que a lista não pôde ser lida
     * @param list<string>    $debloatChecked  os pacotes que nascem marcados
     * @param list<array{key: string, content: string, description: string, category: string, caution: bool, explorer: bool}> $tweaks os tweaks oferecidos
     * @param array<string, list<string>> $tweakPresets os presets, reduzidos às chaves existentes
     * @param list<string>    $tweaksChecked   os tweaks que nascem marcados
     * @param string          $tweaksMatch     o preset que a seleção reproduz, "custom", ou vazio
     * @param string|null     $tweaksProblem   por que os tweaks não puderam ser lidos
     * @param list<string>    $tweaksApplied   os tweaks no estado aplicado
     * @param list<string>    $appliedActions  as ações com estado aplicado guardado
     * @param list<string>    $pendingRebootActions as ações com mudança que só vale depois de reiniciar
     * @param list<array{key: string, label: string, text: string}> $dnsProviders as opções de DNS
     * @param array<string, string> $dnsChosen   a última escolha de DNS guardada
     * @param string|null     $dnsProblem      por que a lista de DNS não pôde ser lida
     * @param WinTab          $tab             a aba aberta
     */
    public function __construct(
        public array $rows,
        public ?Execution $result,
        public ?string $blocked,
        public ?string $notice,
        public string $csrfToken,
        public string $csrfField,
        public ElevationState $win,
        public int $timeout,
        public string $tz,
        public int $maxOutputBytes,
        public int $maxParamBytes,
        public array $debloatPackages,
        public ?string $debloatProblem,
        public array $debloatChecked,
        public array $tweaks,
        public array $tweakPresets,
        public array $tweaksChecked,
        public string $tweaksMatch,
        public ?string $tweaksProblem,
        public array $tweaksApplied,
        public array $appliedActions,
        public array $pendingRebootActions,
        public array $dnsProviders,
        public array $dnsChosen,
        public ?string $dnsProblem,
        public WinTab $tab = WinTab::Sistema,
    ) {
    }
}
