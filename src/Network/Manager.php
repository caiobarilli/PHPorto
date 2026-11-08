<?php

declare(strict_types=1);

namespace App\Network;

use Closure;
use Throwable;

final class Manager
{
    /** @var Closure(string, int): bool */
    private Closure $responds;

    /**
     * @param (Closure(string, int): bool)|null $responds
     */
    public function __construct(
        private Store $store,
        private Machine $machine,
        private Probe $probe,
        private Firewall $firewall,
        private History $history,
        ?Closure $responds = null,
    ) {
        $this->responds = $responds ?? static function (string $ip, int $port): bool {
            $socket = @fsockopen($ip, $port, $errno, $errstr, 0.5);

            if (!is_resource($socket)) {
                return false;
            }

            fclose($socket);

            return true;
        };
    }

    /**
     * @param list<string> $allow
     */
    public function enable(string $bindIp, int $port, array $allow): ManagerResult
    {
        $invalid = self::validate($bindIp, $port, $allow);

        if ($invalid !== null) {
            return new ManagerResult(ManagerResult::USAGE, [$invalid]);
        }

        if (!$this->machine->owns($bindIp)) {
            return new ManagerResult(ManagerResult::USAGE, ['O IP ' . $bindIp . ' não pertence a nenhuma interface desta máquina.']);
        }

        $profile = $this->probe->observe($bindIp)->profile;
        $warning = [];

        if ($profile === null) {
            $warning[] = 'Aviso: não foi possível ler o perfil da rede desse IP. Sem perfil Private, o listener não sobe.';
        } elseif ($profile !== 'Private') {
            return new ManagerResult(ManagerResult::FAILURE, [
                'A rede desse IP está com o perfil ' . $profile . '. A regra de firewall só vale no perfil Private.',
                'Mude a rede para Privada nas configurações do Windows e rode de novo. Nada foi gravado.',
            ]);
        }

        try {
            $state = $this->store->writeForCli(true, $bindIp, $port, $allow);
        } catch (Throwable $e) {
            return new ManagerResult(ManagerResult::FAILURE, ['Não foi possível gravar o estado da rede: ' . $e->getMessage()]);
        }

        $created = $this->firewall->add($state);

        if (!$created->ok) {
            $this->store->disableFromWeb();
            $this->history->record('ligar falhou', 'regra de firewall não criada: ' . $created->message, false);

            return new ManagerResult(ManagerResult::FAILURE, [
                'A regra de firewall não foi criada: ' . $created->message,
                'A rede ficou desligada.',
            ], true);
        }

        $this->history->record('ligada', sprintf('bind=%s; porta=%d; allow=%s', $bindIp, $port, implode(',', $allow)));
        $this->history->record('regra criada', 'grupo ' . Probe::GROUP);

        return new ManagerResult(ManagerResult::OK, [
            ...$warning,
            'Rede ligada em ' . $state->address() . '.',
            'Se o php phporto serve já está rodando, feche e suba de novo: o listener de rede só sobe na partida.',
        ]);
    }

    public function disable(): ManagerResult
    {
        $before = $this->store->load();
        $wasEnabled = $before->enabled;
        $this->store->disableFromWeb();

        if ($wasEnabled) {
            $this->history->record('desligada');
        }

        $known = $this->probe->observe(null);

        if (!$wasEnabled && $known->ruleCount === 0) {
            return new ManagerResult(ManagerResult::OK, ['A rede já estava desligada e não há regra de firewall. Nada a fazer.'], true);
        }

        $removed = $this->firewall->remove();

        if (!$removed->ok) {
            $this->history->record('regra não removida', $removed->message, false);

            return new ManagerResult(ManagerResult::FAILURE, [
                'A rede está desligada: o painel já recusa tudo pela rede.',
                'A regra de firewall ficou para remover: ' . $removed->message,
                'Rode php phporto network off de novo, ou remova o grupo "' . Probe::GROUP . '" no Firewall do Windows.',
            ], true);
        }

        $this->history->record('regra removida', 'grupo ' . Probe::GROUP);

        return new ManagerResult(ManagerResult::OK, ['Rede desligada e regra de firewall removida.'], true);
    }

    public function report(): Report
    {
        $state = $this->store->load();
        $owns = $state->bindIp === null ? null : $this->machine->owns($state->bindIp);
        $observation = $state->bindIp === null ? $this->probe->observe(null) : $this->probe->observe($state->bindIp);
        $plan = Plan::decide($state, $owns === true, $observation);
        $responds = $state->enabled && $state->bindIp !== null ? ($this->responds)($state->bindIp, $state->port) : null;

        return new Report($state, $owns, $observation, $plan, $responds);
    }

    /**
     * @param list<string> $allow
     */
    public static function validate(string $bindIp, int $port, array $allow): ?string
    {
        if (!Ipv4::isPrivate($bindIp) || str_contains($bindIp, '/')) {
            return 'O IP de escuta precisa ser um IPv4 privado (10.x, 172.16-31.x ou 192.168.x).';
        }

        if ($port < 1 || $port > 65535) {
            return 'A porta precisa estar entre 1 e 65535.';
        }

        if ($allow === []) {
            return 'A lista de IPs liberados não pode ser vazia.';
        }

        foreach ($allow as $rule) {
            if (!Ipv4::isPrivateRule($rule)) {
                return 'IP ou faixa liberada inválida (só IPv4 privado, sozinho ou em CIDR): ' . $rule;
            }
        }

        return null;
    }
}
