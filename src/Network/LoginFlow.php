<?php

declare(strict_types=1);

namespace App\Network;

use App\Http\Auth;
use App\Http\AuthDecision;
use App\Http\LoginResult;

final readonly class LoginFlow
{
    public function __construct(private LoginAttempts $attempts, private ?State $network, private ?LoginAudit $audit)
    {
    }

    /** @param array<array-key, mixed> $server */
    public function before(array $server): ?AuthDecision
    {
        $ip = LoginGate::ip($server);

        if ($ip === null || !LoginGate::appliesTo($server, $this->network)) {
            return null;
        }

        $wait = $this->attempts->before($ip);

        if ($wait !== null) {
            $this->audit?->record($server, new LoginResult($wait, true));
        }

        return $wait;
    }

    public function state(): ?State
    {
        return $this->network;
    }

    /** @param array<array-key, mixed> $server */
    public function check(Auth $auth, ?string $password, array $server): LoginResult
    {
        $ip = LoginGate::ip($server);
        $isNetwork = $ip !== null && LoginGate::appliesTo($server, $this->network);
        $result = new LoginResult(
            $isNetwork ? $auth->checkNetwork($password) : $auth->check($password),
            $password !== null,
        );

        if ($isNetwork) {
            $result = new LoginResult($this->attempts->after($ip, $result->decision, $result->attempt), $result->attempt);
            $this->audit?->record($server, $result);
        }

        return $result;
    }
}
