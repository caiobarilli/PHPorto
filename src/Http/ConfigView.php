<?php

declare(strict_types=1);

namespace App\Http;

use App\Win\ElevationState;

/**
 * A tela /config.
 *
 * ANTES esta tela era só leitura, e a razão estava certa: editar significaria
 * escrever no .env pela web, num projeto que já executa comando arbitrário,
 * para ganhar pouco — quem sobe a ferramenta tem o arquivo aberto no editor.
 *
 * O QUE MUDOU não foi essa conclusão, foi o alcance dela. O .env continua
 * intocado pelo processo web. O que a tela alterna é uma única chave, gravada
 * em storage/flags.json (ver App\Config\Flags), e o motivo de existir é que
 * PHPORTO_API_ENABLED não é preferência: é uma superfície que executa comando,
 * e ligar às cegas editando arquivo é pior do que ligar com um aviso na frente.
 *
 * Por isso a tela mostra as DUAS respostas — a do .env e a que está valendo.
 * Uma chave ligada sem que o .env diga isso é informação, não detalhe.
 */
final readonly class ConfigView
{
    /**
     * @param non-empty-string                         $provider     valor de DB_PROVIDER em uso
     * @param list<array{label: string, value: string}> $details
     * @param bool   $apiEnabled    o que vale agora (flags.json ou .env)
     * @param bool   $apiFromEnv    o que o .env sozinho diria
     * @param bool   $apiOverridden true quando a tela sobrescreve o .env
     * @param string $corsOrigin    a única origem que a API aceita
     * @param string $dbPath        caminho do sqlite, vazio nos outros bancos
     * @param bool   $dbExists      se há arquivo de banco para apagar
     *
     * O interruptor de PowerShell é diferente em natureza do da API, e a tela
     * precisa deixar isso visível. O da API grava um booleano em arquivo e
     * sobrevive a reinício. Este abre um PROCESSO ELEVADO e vive só enquanto
     * este php -S viver: reiniciar o servidor desliga, porque o marcador é
     * amarrado ao PID dele. Prometer permanência aqui seria mentir.
     *
     * @param ElevationState $win             o que vale agora
     * @param bool           $winRetry        última tentativa falhou: oferece "tentar novamente"
     * @param int            $winProofTimeout segundos que o POST espera pela prova
     *
     * O painel do Hyper-V é diferente dos dois de cima: não é prova viva nem
     * booleano da API, é decisão persistente do dono da máquina, gravada em
     * storage/hyperv.json e sobrevivente a reinício. Por isso mostra desde
     * quando está ligado, e não fala em "vida curta".
     *
     * @param bool    $hypervEnabled   se o painel do Hyper-V está habilitado
     * @param ?string $hypervEnabledAt desde quando, em UTC, ou null
     *
     * O UAC entra na seção do PowerShell porque é dele que as ações sensíveis
     * dependem: cada uma abre o próprio prompt, e sem prompt (UAC silencioso
     * ou desligado) elas são recusadas.
     *
     * @param bool   $uacOk      se a política do UAC garante um prompt por ação sensível
     * @param string $uacSummary a linha que diz em que pé o UAC está
     * @param string $tz         fuso das datas da tela (o do Windows, como no resto do app)
     */
    public function __construct(
        public string $provider,
        public array $details,
        public bool $apiEnabled,
        public bool $apiFromEnv,
        public bool $apiOverridden,
        public string $corsOrigin,
        public string $dbPath,
        public bool $dbExists,
        public ?string $notice,
        public string $csrfToken,
        public string $csrfField,
        public ElevationState $win,
        public bool $winRetry,
        public int $winProofTimeout,
        public bool $hypervEnabled,
        public ?string $hypervEnabledAt,
        public bool $uacOk,
        public string $uacSummary,
        public string $tz,
    ) {
    }
}
