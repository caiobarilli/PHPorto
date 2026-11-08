<?php

declare(strict_types=1);

namespace App\Network;

final readonly class Plan
{
    private function __construct(public bool $start, public ?string $reason)
    {
    }

    public static function decide(State $state, bool $ownsIp, Observation $observation): self
    {
        if ($state->updatedAt === null) {
            return new self(false, 'o estado da rede está ausente ou inválido; rode php phporto network on');
        }

        if (!$state->enabled) {
            return new self(false, 'a rede está desligada');
        }

        if ($state->bindIp === null || !$ownsIp) {
            return new self(false, 'o IP ' . ($state->bindIp ?? '?') . ' não pertence mais a esta máquina; rode php phporto network on de novo');
        }

        if ($observation->profile === null) {
            return new self(false, 'não foi possível ler o perfil da rede do IP ' . $state->bindIp);
        }

        if (!$observation->profileIsPrivate()) {
            return new self(false, 'o perfil da rede do IP ' . $state->bindIp . ' é ' . $observation->profile . ', e precisa ser Private');
        }

        return match ($observation->rule($state)) {
            Observation::RULE_OK => new self(true, null),
            Observation::RULE_MISSING => new self(false, 'a regra de firewall não existe; rode php phporto network on de novo'),
            Observation::RULE_DIVERGENT => new self(false, 'a regra de firewall não bate com o estado da rede; rode php phporto network on de novo'),
            default => new self(false, 'não foi possível ler a regra de firewall'),
        };
    }
}
