<?php

declare(strict_types=1);

namespace App\Network;

use App\Win\PsRunner;
use App\Win\PsScriptBuilder;
use Closure;
use Throwable;

final class Probe
{
    public const GROUP = 'PHPorto Rede';

    public const TIMEOUT_S = 20;

    /** @var Closure(string): ?string */
    private Closure $run;

    /**
     * @param (Closure(string): ?string)|null $run
     */
    public function __construct(private string $filesDir, ?Closure $run = null)
    {
        $this->run = $run ?? $this->runScript(...);
    }

    public function observe(?string $bindIp): Observation
    {
        if ($bindIp !== null && (!Ipv4::isPrivate($bindIp) || str_contains($bindIp, '/'))) {
            return Observation::unknown();
        }

        $output = ($this->run)(self::script($bindIp));

        return $output === null ? Observation::unknown() : Observation::parse($output);
    }

    public static function script(?string $bindIp): string
    {
        $group = PsScriptBuilder::literal(self::GROUP);
        $profile = $bindIp === null ? '' : self::profileBlock(PsScriptBuilder::literal($bindIp));

        return <<<PS
            \$ErrorActionPreference = 'Stop'
            {$profile}
            try {
                \$regras = @(Get-NetFirewallRule -Group {$group} -ErrorAction SilentlyContinue)
                'RULES=' + \$regras.Count
                foreach (\$x in \$regras) {
                    \$p = \$x | Get-NetFirewallPortFilter
                    \$a = \$x | Get-NetFirewallAddressFilter
                    'RULE=' + [string]\$x.Enabled + '|' + [string]\$x.Direction + '|' + [string]\$x.Action + '|' + [string]\$x.Profile + '|' + [string]\$p.Protocol + '|' + [string]\$p.LocalPort + '|' + [string]\$a.LocalAddress + '|' + ((\$a.RemoteAddress | ForEach-Object { [string]\$_ }) -join ',')
                }
            } catch {
                'RULES=erro'
            }
            PS;
    }

    private static function profileBlock(string $ip): string
    {
        return <<<PS
            try {
                \$idx = (Get-NetIPAddress -IPAddress {$ip} -AddressFamily IPv4).InterfaceIndex
                'PROFILE=' + [string](Get-NetConnectionProfile -InterfaceIndex \$idx).NetworkCategory
            } catch {
                'PROFILE=erro'
            }
            PS;
    }

    private function runScript(string $script): ?string
    {
        if (!is_dir($this->filesDir)) {
            return null;
        }

        $path = $this->filesDir . DIRECTORY_SEPARATOR . 'rede-sonda-' . bin2hex(random_bytes(4)) . '.ps1';

        try {
            PsScriptBuilder::write($path, $script);

            return (new PsRunner($this->filesDir))->run($path, self::TIMEOUT_S)->output;
        } catch (Throwable) {
            return null;
        } finally {
            @unlink($path);
        }
    }
}
