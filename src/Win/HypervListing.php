<?php

declare(strict_types=1);

namespace App\Win;

/**
 * A saída do Invoke-Hyperv, já lida do JSON e virada dado tipado.
 *
 * SEPARADA DA VIEW E DA PÁGINA de propósito: ler o JSON que atravessou o worker
 * é onde uma saída inesperada tem de virar mensagem, não erro fatal — e isso se
 * testa alimentando texto, sem subir PowerShell nenhum. A página só orquestra;
 * a view só desenha; esta classe é a única que sabe a forma do JSON.
 *
 * O JSON pode vir sujo: o canal do worker prefixa notas de timeout ou de recusa
 * à saída. Por isso recorta do primeiro `{` ao último `}` antes de decodificar,
 * e o que não casar com a forma esperada vira problema, não exceção.
 *
 * COM O HOSPEDEIRO FORA, O MOTIVO É FECHADO: recurso desligado, serviço parado
 * ou outro erro. Motivo ausente ou desconhecido cai em "erro", e não em
 * "recurso desligado" — mandar reiniciar o computador sem saber é o conselho
 * errado mais caro que a tela pode dar.
 */
final class HypervListing
{
    /** O recurso Microsoft-Hyper-V não está ligado no Windows. */
    public const REASON_FEATURE_OFF = 'recurso-desligado';

    /** O serviço vmms (Gerenciamento de Máquina Virtual) não está rodando. */
    public const REASON_SERVICE_STOPPED = 'servico-parado';

    /** Qualquer outra falha do Get-VM; a mensagem vem em $error. */
    public const REASON_ERROR = 'erro';

    /**
     * @param list<HypervVm> $vms
     */
    private function __construct(
        public readonly ?bool $hypervOn,
        public readonly array $vms,
        public readonly ?string $problem,
        public readonly ?string $reason = null,
        public readonly ?string $error = null,
    ) {
    }

    public static function fromOutput(string $output): self
    {
        $inicio = strpos($output, '{');
        $fim    = strrpos($output, '}');

        if ($inicio === false || $fim === false || $fim < $inicio) {
            return new self(null, [], 'A leitura das máquinas virtuais não voltou no formato esperado.');
        }

        $json  = substr($output, $inicio, $fim - $inicio + 1);
        $dados = json_decode($json, true);

        if (!is_array($dados) || !array_key_exists('hyperv', $dados)) {
            return new self(null, [], 'A leitura das máquinas virtuais não voltou no formato esperado.');
        }

        $hypervOn = (bool) $dados['hyperv'];

        if (!$hypervOn) {
            $motivo = $dados['motivo'] ?? null;
            $erro   = $dados['erro'] ?? null;

            return new self(
                false,
                [],
                null,
                in_array($motivo, [self::REASON_FEATURE_OFF, self::REASON_SERVICE_STOPPED], true)
                    ? $motivo
                    : self::REASON_ERROR,
                is_string($erro) && trim($erro) !== '' ? trim($erro) : null,
            );
        }

        $vms      = [];
        $listaCru = $dados['vms'] ?? [];

        if (is_array($listaCru)) {
            foreach ($listaCru as $cru) {
                if (is_array($cru)) {
                    $vms[] = self::vm($cru);
                }
            }
        }

        return new self(true, $vms, null);
    }

    /**
     * @param array<mixed> $cru
     */
    private static function vm(array $cru): HypervVm
    {
        $ips = [];

        if (isset($cru['ip']) && is_array($cru['ip'])) {
            foreach ($cru['ip'] as $ip) {
                if (is_string($ip) && $ip !== '') {
                    $ips[] = $ip;
                }
            }
        }

        return new HypervVm(
            name: is_string($cru['name'] ?? null) ? $cru['name'] : '',
            state: is_string($cru['state'] ?? null) ? $cru['state'] : '',
            running: ($cru['running'] ?? false) === true,
            memoryBytes: is_int($cru['memoryBytes'] ?? null) ? $cru['memoryBytes'] : 0,
            uptimeSeconds: is_int($cru['uptimeSeconds'] ?? null) ? $cru['uptimeSeconds'] : 0,
            ip: $ips,
        );
    }
}
