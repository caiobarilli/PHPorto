<?php

declare(strict_types=1);

namespace App\Cli;

use App\Config\Config;
use App\Network\Machine;
use App\Network\Manager;
use App\Network\ManagerResult;
use App\Network\Observation;
use Closure;

final class NetworkCommand
{
    public const CONFIRMATION = 'sim';

    /**
     * @param Closure(): Manager $manager
     */
    public function __construct(
        private string $root,
        private Console $console,
        private Closure $manager,
        private Machine $machine,
    ) {
    }

    /**
     * @param list<string> $args
     */
    public function run(array $args): int
    {
        if ($args === ['--help'] || $args === ['-h']) {
            $this->console->out(Help::for('network'));

            return Status::OK;
        }

        if (count($args) !== 1 || !in_array($args[0], ['on', 'off', 'status'], true)) {
            $this->console->err("Use: php phporto network on|off|status\n\n" . Help::for('network'));

            return Status::USAGE;
        }

        return match ($args[0]) {
            'on' => $this->on(),
            'off' => $this->finish(($this->manager)()->disable()),
            default => $this->status(),
        };
    }

    private function on(): int
    {
        $env = EnvReader::load($this->root, $this->console);

        if (is_int($env)) {
            return $env;
        }

        $port = Config::parsePort($env['PHPORTO_PORT'] ?? null) ?? Config::DEFAULT_PORT;
        $ips = $this->machine->privateIpv4();

        if ($ips === []) {
            $this->console->err("Esta máquina não tem nenhum IPv4 privado. Nada foi feito.\n");

            return Status::FAILURE;
        }

        $this->console->out("IPs privados desta máquina:\n");

        foreach ($ips as $ip) {
            $this->console->out('  ' . $ip . "\n");
        }

        $default = count($ips) === 1 ? $ips[0] : '';
        $bind = $this->ask('IP de escuta', $default);
        $answer = $this->ask('Porta', (string) $port);
        $allow = array_values(array_filter(array_map('trim', explode(',', $this->ask('IPs ou faixas liberados, separados por vírgula (ex.: 192.168.1.0/24)', '')))));

        if (preg_match('/\A[0-9]{1,5}\z/', $answer) !== 1) {
            $this->console->err("Porta inválida. Nada foi feito.\n");

            return Status::USAGE;
        }

        $invalid = Manager::validate($bind, (int) $answer, $allow);

        if ($invalid !== null) {
            $this->console->err($invalid . " Nada foi feito.\n");

            return Status::USAGE;
        }

        $this->console->out("\nRISCO: o PHPorto fala HTTP sem TLS. Na rede, o token da instância e tudo o que o painel mostra\n");
        $this->console->out("viajam em texto puro, e o painel executa ações de administrador. Quem estiver nos IPs\n");
        $this->console->out("liberados e alcançar a porta pode tentar o token (há limite de tentativas por IP).\n");
        $this->console->out('Vai escutar em ' . $bind . ':' . $answer . ' e liberar só: ' . implode(', ', $allow) . ".\n");
        $this->console->out('O Windows vai pedir o UAC para criar a regra de firewall (só no perfil Private).' . "\n\n");
        $this->console->out('Digite "' . self::CONFIRMATION . '" para ligar: ');

        if (strtolower($this->console->readLine()) !== self::CONFIRMATION) {
            $this->console->out("Nada mudou.\n");

            return Status::FAILURE;
        }

        return $this->finish(($this->manager)()->enable($bind, (int) $answer, $allow));
    }

    private function status(): int
    {
        $report = ($this->manager)()->report();
        $state = $report->state;
        $yes = static fn (?bool $value): string => $value === null ? 'não se aplica' : ($value ? 'sim' : 'não');
        $rule = $state->bindIp === null ? $report->observation->ruleCount : $report->observation->rule($state);

        $this->console->out('Rede: ' . ($state->enabled ? 'ligada' : 'desligada') . "\n");

        if ($state->updatedAt !== null) {
            $this->console->out('Escuta: ' . $state->host() . "\n");
            $this->console->out('Liberados: ' . implode(', ', $state->allow) . "\n");
            $this->console->out('Atualizado em: ' . $state->updatedAt . "\n");
        }

        $this->console->out('O IP ainda é desta máquina: ' . $yes($report->ownsIp) . "\n");
        $this->console->out('Perfil da rede: ' . ($report->observation->profile ?? 'não foi possível ler') . "\n");
        $this->console->out('Regra de firewall: ' . $this->ruleText($rule, $report->observation) . "\n");
        $this->console->out('Listener responde: ' . $yes($report->listenerResponds) . "\n");
        $this->console->out('O listener subiria agora: ' . ($report->plan->start ? 'sim' : 'não (' . $report->plan->reason . ')') . "\n");

        return Status::OK;
    }

    private function ruleText(int|string|null $rule, Observation $observation): string
    {
        if (is_int($rule)) {
            return $rule === 0 ? 'ausente' : (string) $rule . ' regra(s)';
        }

        return match ($rule) {
            Observation::RULE_OK => 'presente e igual ao estado',
            Observation::RULE_MISSING => 'ausente',
            Observation::RULE_DIVERGENT => 'presente, mas diferente do estado',
            default => $observation->ruleCount === null ? 'não foi possível ler' : 'desconhecida',
        };
    }

    private function finish(ManagerResult $result): int
    {
        $text = implode("\n", $result->lines) . "\n";

        if ($result->ok()) {
            $this->console->out($text);

            return Status::OK;
        }

        $this->console->err($text);

        return $result->kind === ManagerResult::USAGE ? Status::USAGE : Status::FAILURE;
    }

    private function ask(string $label, string $default): string
    {
        $this->console->out($label . ($default === '' ? '' : ' [' . $default . ']') . ': ');
        $answer = $this->console->readLine();

        return $answer === '' ? $default : $answer;
    }
}
