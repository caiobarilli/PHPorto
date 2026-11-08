<?php

declare(strict_types=1);

namespace App\Network;

final class Launcher
{
    public function __construct(private Store $store, private Machine $machine, private Probe $probe)
    {
    }

    public function prepare(): Launch
    {
        $state = $this->store->load();

        if ($state->updatedAt === null) {
            $exists = is_file($this->store->path());

            return new Launch($exists, false, $exists ? Plan::decide($state, false, Observation::unknown())->reason : null, null);
        }

        if (!$state->enabled) {
            return new Launch(false, false, null, null);
        }

        $owns = $state->bindIp !== null && $this->machine->owns($state->bindIp);
        $observation = $owns ? $this->probe->observe($state->bindIp) : Observation::unknown();
        $plan = Plan::decide($state, $owns, $observation);

        return new Launch(true, $plan->start, $plan->reason, $plan->start ? $state : null);
    }
}
