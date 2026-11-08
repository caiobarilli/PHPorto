<?php

declare(strict_types=1);

namespace App\Autostart;

use App\Win\PsRunner;
use App\Win\PsScriptBuilder;
use Closure;
use Throwable;

final class Task
{
    public const NAME = 'PHPorto';

    public const TIMEOUT_S = 20;

    public const PRESENT = 'presente';

    public const ABSENT = 'ausente';

    public const UNKNOWN = 'desconhecida';

    /** @var Closure(string): ?string */
    private Closure $run;

    /**
     * @param (Closure(string): ?string)|null $run
     */
    public function __construct(private string $filesDir, ?Closure $run = null)
    {
        $this->run = $run ?? $this->runScript(...);
    }

    public function observe(): TaskObservation
    {
        $output = ($this->run)(self::script());

        return $output === null ? TaskObservation::unknown() : TaskObservation::parse($output);
    }

    public static function script(): string
    {
        $name = PsScriptBuilder::literal(self::NAME);

        return <<<PS
            \$ErrorActionPreference = 'Stop'
            try {
                \$t = Get-ScheduledTask -TaskName {$name} -ErrorAction SilentlyContinue
                if (\$null -eq \$t) {
                    'TASK=ausente'
                } else {
                    'TASK=presente'
                    'STATE=' + [string]\$t.State
                    'USER=' + [string]\$t.Principal.UserId
                    'LOGON=' + [string]\$t.Principal.LogonType
                    'RUNLEVEL=' + [string]\$t.Principal.RunLevel
                    \$a = @(\$t.Actions)[0]
                    'EXEC=' + [string]\$a.Execute
                    'ARGS=' + [string]\$a.Arguments
                    'TRIGGERS=' + ((@(\$t.Triggers) | ForEach-Object { [string]\$_.CimClass.CimClassName }) -join ',')
                }
            } catch {
                'TASK=erro'
            }
            PS;
    }

    private function runScript(string $script): ?string
    {
        if (!is_dir($this->filesDir)) {
            return null;
        }

        $path = $this->filesDir . DIRECTORY_SEPARATOR . 'autostart-sonda-' . bin2hex(random_bytes(4)) . '.ps1';

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
