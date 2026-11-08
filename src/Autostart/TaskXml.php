<?php

declare(strict_types=1);

namespace App\Autostart;

final class TaskXml
{
    public const FILE = 'PHPorto-tarefa.xml';

    public static function build(string $sid, string $program, string $script): string
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $root = dirname($script);
        $arguments = '"' . $script . '" serve';

        $xml = <<<XML
            <?xml version="1.0" encoding="UTF-16"?>
            <Task version="1.2" xmlns="http://schemas.microsoft.com/windows/2004/02/mit/task">
              <RegistrationInfo>
                <URI>\\{$e(Task::NAME)}</URI>
                <Description>Sobe o painel PHPorto (php phporto serve) na inicialização do Windows.</Description>
              </RegistrationInfo>
              <Triggers>
                <BootTrigger>
                  <Enabled>true</Enabled>
                </BootTrigger>
              </Triggers>
              <Principals>
                <Principal id="Author">
                  <UserId>{$e($sid)}</UserId>
                  <LogonType>Password</LogonType>
                  <RunLevel>LeastPrivilege</RunLevel>
                </Principal>
              </Principals>
              <Settings>
                <MultipleInstancesPolicy>IgnoreNew</MultipleInstancesPolicy>
                <DisallowStartIfOnBatteries>false</DisallowStartIfOnBatteries>
                <StopIfGoingOnBatteries>false</StopIfGoingOnBatteries>
                <AllowHardTerminate>true</AllowHardTerminate>
                <StartWhenAvailable>true</StartWhenAvailable>
                <RunOnlyIfNetworkAvailable>false</RunOnlyIfNetworkAvailable>
                <IdleSettings>
                  <StopOnIdleEnd>false</StopOnIdleEnd>
                  <RestartOnIdle>false</RestartOnIdle>
                </IdleSettings>
                <AllowStartOnDemand>true</AllowStartOnDemand>
                <Enabled>true</Enabled>
                <Hidden>false</Hidden>
                <RunOnlyIfIdle>false</RunOnlyIfIdle>
                <WakeToRun>false</WakeToRun>
                <ExecutionTimeLimit>PT0S</ExecutionTimeLimit>
                <Priority>7</Priority>
                <RestartOnFailure>
                  <Interval>PT1M</Interval>
                  <Count>3</Count>
                </RestartOnFailure>
              </Settings>
              <Actions Context="Author">
                <Exec>
                  <Command>{$e($program)}</Command>
                  <Arguments>{$e($arguments)}</Arguments>
                  <WorkingDirectory>{$e($root)}</WorkingDirectory>
                </Exec>
              </Actions>
            </Task>
            XML;

        return "\xFF\xFE" . (string) mb_convert_encoding(str_replace("\n", "\r\n", $xml) . "\r\n", 'UTF-16LE', 'UTF-8');
    }

    public static function decode(string $file): string
    {
        return (string) mb_convert_encoding(substr($file, 2), 'UTF-8', 'UTF-16LE');
    }
}
