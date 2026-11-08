<?php

declare(strict_types=1);

namespace App\Network;

use App\Win\OneShot;
use App\Win\PsResult;
use App\Win\WinAction;
use Closure;
use InvalidArgumentException;
use RuntimeException;

final class Firewall
{
    public const TIMEOUT_S = 120;

    /** @var Closure(WinAction, array<string, string|int|bool>, int): PsResult */
    private Closure $dispatch;

    /**
     * @param Closure(WinAction, array<string, string|int|bool>, int): PsResult $dispatch
     */
    public function __construct(Closure $dispatch, private string $phpBinary = PHP_BINARY)
    {
        $this->dispatch = $dispatch;
    }

    public static function fromOneShot(OneShot $oneShot): self
    {
        return new self(static fn (WinAction $action, array $params, int $timeout): PsResult => $oneShot->dispatch($action, $params, $timeout));
    }

    public function add(State $state): FirewallResult
    {
        if ($state->bindIp === null) {
            return new FirewallResult(false, 'o estado da rede não tem IP');
        }

        try {
            $params = WinAction::NetFirewall->validate([
                'SubAction' => 'add',
                'BindIp' => $state->bindIp,
                'Port' => (string) $state->port,
                'Allow' => implode(',', $state->allow),
                'Program' => $this->phpBinary,
            ]);
        } catch (InvalidArgumentException $e) {
            return new FirewallResult(false, $e->getMessage());
        }

        return $this->run($params);
    }

    public function remove(): FirewallResult
    {
        return $this->run(['SubAction' => 'remove']);
    }

    /**
     * @param array<string, string|int|bool> $params
     */
    private function run(array $params): FirewallResult
    {
        try {
            $result = ($this->dispatch)(WinAction::NetFirewall, $params, self::TIMEOUT_S);
        } catch (RuntimeException $e) {
            return new FirewallResult(false, $e->getMessage());
        }

        return self::interpret($result);
    }

    public static function interpret(PsResult $result): FirewallResult
    {
        if ($result->timedOut) {
            return new FirewallResult(false, 'a ação de firewall passou do prazo');
        }

        $erro = preg_match('/^\[ ERROR \] (.*)$/m', $result->output, $m) === 1 ? trim($m[1]) : null;

        if ($erro !== null) {
            return new FirewallResult(false, $erro);
        }

        if ($result->exitCode === 0 && str_contains($result->output, '[ OK ]')) {
            return new FirewallResult(true, 'ok');
        }

        return new FirewallResult(false, 'a ação de firewall não confirmou o resultado');
    }
}
