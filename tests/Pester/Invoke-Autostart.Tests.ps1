#Requires -Version 5.1

BeforeAll {
    $Script:RaizProjeto = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $Script:PastaWin    = Join-Path $Script:RaizProjeto 'src\Win'
    $Script:Acao        = Join-Path $Script:PastaWin 'actions\Invoke-Autostart.ps1'

    . (Join-Path $Script:PastaWin 'bootstrap.ps1')

    $Script:Criadas = @()
    $stubs = @{
        'New-ScheduledTaskAction'      = '[CmdletBinding()] param([string]$Execute, [string]$Argument, [string]$WorkingDirectory) [PSCustomObject]@{ Execute = $Execute; Argument = $Argument; WorkingDirectory = $WorkingDirectory }'
        'New-ScheduledTaskTrigger'     = '[CmdletBinding()] param([switch]$AtStartup) [PSCustomObject]@{ AtStartup = [bool]$AtStartup }'
        'New-ScheduledTaskSettingsSet' = '[CmdletBinding()] param($MultipleInstances, $RestartCount, $RestartInterval, $ExecutionTimeLimit, [switch]$StartWhenAvailable, [switch]$AllowStartIfOnBatteries, [switch]$DontStopIfGoingOnBatteries) [PSCustomObject]@{ MultipleInstances = $MultipleInstances; RestartCount = $RestartCount }'
        'New-ScheduledTaskPrincipal'   = '[CmdletBinding()] param([string]$UserId, [string]$LogonType, [string]$RunLevel) [PSCustomObject]@{ UserId = $UserId; LogonType = $LogonType; RunLevel = $RunLevel }'
        'Register-ScheduledTask'       = '[CmdletBinding()] param([string]$TaskName, $Action, $Trigger, $Settings, $Principal, [switch]$Force) '
        'Unregister-ScheduledTask'     = '[CmdletBinding()] param([string]$TaskName, [bool]$Confirm) '
        'Get-ScheduledTask'            = '[CmdletBinding()] param([string]$TaskName) '
        'Stop-ScheduledTask'           = '[CmdletBinding()] param([string]$TaskName) '
        'Get-CimInstance'              = '[CmdletBinding()] param([string]$ClassName, [string]$Filter) '
    }
    foreach ($nome in $stubs.Keys) {
        $Script:Criadas += $nome
        Set-Item -Path ('function:global:' + $nome) -Value ([scriptblock]::Create($stubs[$nome]))
    }

    $Script:Pasta = Join-Path ([IO.Path]::GetTempPath()) ('phporto-as-' + [Guid]::NewGuid().ToString('N'))
    New-Item -ItemType Directory -Path $Script:Pasta | Out-Null
    $Script:PhpExe = Join-Path $Script:Pasta 'php.exe'
    $Script:Phporto = Join-Path $Script:Pasta 'phporto'
    Set-Content -Path $Script:PhpExe -Value 'x'
    Set-Content -Path $Script:Phporto -Value 'x'
    $Script:Eu = [Security.Principal.WindowsIdentity]::GetCurrent().Name
    $Script:EuSid = [Security.Principal.WindowsIdentity]::GetCurrent().User.Value
}

AfterAll {
    foreach ($nome in $Script:Criadas) { Remove-Item -Path ('Function:\' + $nome) -Force -ErrorAction SilentlyContinue }
    Remove-Item -Recurse -Force $Script:Pasta -ErrorAction SilentlyContinue
}

Describe "Invoke-Autostart - Sanity" {

    It "has no syntax errors" {
        $errors = $null
        [System.Management.Automation.Language.Parser]::ParseFile($Script:Acao, [ref]$null, [ref]$errors) | Out-Null
        $errors.Count | Should -Be 0
    }

    It "never stores or asks for a password, never runs as Highest" {
        $text = Get-Content -Path $Script:Acao -Raw
        $text | Should -Not -Match '-Password|Get-Credential|SecureString|LogonType\s+Password|Read-Host|RunAs'
        $text | Should -Not -Match 'RunLevel Highest'
        $text | Should -Match '-LogonType S4U'
        $text | Should -Match '-RunLevel Limited'
    }
}

Describe "Invoke-Autostart - SubAction validation" {

    It "emits [ ERROR ] and lists the options when no subaction is given" {
        $output = (Invoke-Autostart) 6>&1 | Out-String
        $output | Should -Match '\[ ERROR \]'
        $output | Should -Match 'on, off, stop'
    }

    It "emits [ ERROR ] for an unrecognised subaction" {
        $output = (Invoke-Autostart -SubAction 'status') 6>&1 | Out-String
        $output | Should -Match '\[ ERROR \]'
    }
}

Describe "Invoke-Autostart - on" {

    BeforeEach {
        Mock Register-ScheduledTask { }
        Mock New-ScheduledTaskPrincipal { [PSCustomObject]@{ UserId = $UserId; LogonType = $LogonType; RunLevel = $RunLevel } }
    }

    It "registers an at-startup task for the own account with S4U and RunLevel Limited" {
        $output = (Invoke-Autostart -SubAction on -Sid $Script:EuSid -Program $Script:PhpExe -Script $Script:Phporto) 6>&1 | Out-String

        $output | Should -Match '\[ OK \]'
        Should -Invoke -CommandName New-ScheduledTaskPrincipal -Times 1 -Exactly -ParameterFilter {
            $UserId -eq $Script:Eu -and $LogonType -eq 'S4U' -and $RunLevel -eq 'Limited'
        }
        Should -Invoke -CommandName Register-ScheduledTask -Times 1 -Exactly -ParameterFilter {
            $TaskName -eq 'PHPorto' -and $Force
        }
    }

    It "runs php.exe with the phporto script and the serve command" {
        Mock New-ScheduledTaskAction { [PSCustomObject]@{ Execute = $Execute; Argument = $Argument } }
        (Invoke-Autostart -SubAction on -Sid $Script:EuSid -Program $Script:PhpExe -Script $Script:Phporto) 6>&1 | Out-Null
        Should -Invoke -CommandName New-ScheduledTaskAction -Times 1 -Exactly -ParameterFilter {
            $Execute -eq $Script:PhpExe -and $Argument -eq ('"' + $Script:Phporto + '" serve')
        }
    }

    It "refuses (<Caso>) without registering anything" -ForEach @(
        @{ Caso = 'another SID';       User = 'S-1-5-21-1-2-3-1001';  Php = 'ok'; Roteiro = 'ok' }
        @{ Caso = 'empty SID';         User = '';                     Php = 'ok'; Roteiro = 'ok' }
        @{ Caso = 'malformed SID';     User = 'S-1-5-21-1-2-3';       Php = 'ok'; Roteiro = 'ok' }
        @{ Caso = 'account name';      User = 'nome';                 Php = 'ok'; Roteiro = 'ok' }
        @{ Caso = 'trailing garbage';  User = 'eu;calc';              Php = 'ok'; Roteiro = 'ok' }
        @{ Caso = 'not php.exe';      User = 'eu';           Php = 'notepad'; Roteiro = 'ok' }
        @{ Caso = 'not phporto file'; User = 'eu';           Php = 'ok';      Roteiro = 'token.php' }
    ) {
        $user = if ($User -eq 'eu') { $Script:EuSid } elseif ($User -eq 'eu;calc') { $Script:EuSid + ';calc' } elseif ($User -eq 'nome') { $Script:Eu } else { $User }
        $programa = if ($Php -eq 'ok') { $Script:PhpExe } else { Join-Path $Script:Pasta 'notepad.exe' }
        $arquivo = if ($Roteiro -eq 'ok') { $Script:Phporto } else { Join-Path $Script:Pasta $Roteiro }
        $output = (Invoke-Autostart -SubAction on -Sid $user -Program $programa -Script $arquivo) 6>&1 | Out-String
        $output | Should -Match '\[ ERROR \]'
        Should -Invoke -CommandName Register-ScheduledTask -Times 0 -Exactly
    }

    It "creates nothing when the SID differs, and says so" {
        $output = (Invoke-Autostart -SubAction on -Sid 'S-1-5-21-1-2-3-1001' -Program $Script:PhpExe -Script $Script:Phporto) 6>&1 | Out-String
        $output | Should -Match '\[ ERROR \]'
        $output | Should -Match 'does not match'
        Should -Invoke -CommandName New-ScheduledTaskPrincipal -Times 0 -Exactly
        Should -Invoke -CommandName Register-ScheduledTask -Times 0 -Exactly
    }

    It "does not depend on USERDOMAIN or USERNAME" {
        $d = $env:USERDOMAIN; $u = $env:USERNAME
        try {
            $env:USERDOMAIN = 'DOMINIO-FALSO'; $env:USERNAME = 'outra-pessoa'
            $output = (Invoke-Autostart -SubAction on -Sid $Script:EuSid -Program $Script:PhpExe -Script $Script:Phporto) 6>&1 | Out-String
        } finally {
            $env:USERDOMAIN = $d; $env:USERNAME = $u
        }
        $output | Should -Match '\[ OK \]'
        Should -Invoke -CommandName New-ScheduledTaskPrincipal -Times 1 -Exactly -ParameterFilter { $UserId -eq $Script:Eu }
    }

    It "reports the failure when the registration throws" {
        Mock Register-ScheduledTask { throw 'acesso negado' }
        $output = (Invoke-Autostart -SubAction on -Sid $Script:EuSid -Program $Script:PhpExe -Script $Script:Phporto) 6>&1 | Out-String
        $output | Should -Match '\[ ERROR \]'
        $output | Should -Not -Match '\[ OK \]'
    }
}

Describe "Invoke-Autostart - off" {

    It "removes the task and says the running server is not stopped" {
        Mock Get-ScheduledTask { [PSCustomObject]@{ TaskName = 'PHPorto' } }
        Mock Unregister-ScheduledTask { }
        $output = (Invoke-Autostart -SubAction off) 6>&1 | Out-String
        $output | Should -Match '\[ OK \]'
        $output | Should -Match 'not stopped'
        Should -Invoke -CommandName Unregister-ScheduledTask -Times 1 -Exactly -ParameterFilter { $TaskName -eq 'PHPorto' }
    }

    It "is idempotent: without the task it is OK and removes nothing" {
        Mock Get-ScheduledTask { }
        Mock Unregister-ScheduledTask { }
        $output = (Invoke-Autostart -SubAction off) 6>&1 | Out-String
        $output | Should -Match '\[ OK \]'
        $output | Should -Not -Match '\[ ERROR \]'
        Should -Invoke -CommandName Unregister-ScheduledTask -Times 0 -Exactly
    }
}

Describe "Invoke-Autostart - stop" {

    BeforeAll {
        function global:New-FakePhp([int]$Id, [string]$Linha) { [PSCustomObject]@{ ProcessId = $Id; CommandLine = $Linha } }
        $Script:Colchetes = Join-Path $Script:Pasta 'a[1]b'
        New-Item -ItemType Directory -Path $Script:Colchetes | Out-Null
        $Script:PhportoColchetes = Join-Path $Script:Colchetes 'phporto'
        Set-Content -LiteralPath $Script:PhportoColchetes -Value 'x'
        $Script:Outra = Join-Path $Script:Pasta 'outra'
        New-Item -ItemType Directory -Path $Script:Outra | Out-Null
        $Script:PhportoOutra = Join-Path $Script:Outra 'phporto'
        Set-Content -Path $Script:PhportoOutra -Value 'x'
    }

    AfterAll {
        Remove-Item -Path 'Function:\New-FakePhp' -Force -ErrorAction SilentlyContinue
    }

    BeforeEach {
        Mock Get-ScheduledTask { }
        Mock Stop-ScheduledTask { }
        Mock Stop-PhportoTree { }
        Mock Get-PhportoProcessCwd { $null }
    }

    It "needs the phporto script" {
        $output = (Invoke-Autostart -SubAction stop -Script (Join-Path $Script:Pasta 'token.php')) 6>&1 | Out-String
        $output | Should -Match '\[ ERROR \]'
    }

    It "stops the scheduled task when it exists" {
        Mock Get-CimInstance { @() }
        Mock Get-ScheduledTask { [PSCustomObject]@{ TaskName = 'PHPorto' } }
        $output = (Invoke-Autostart -SubAction stop -Script $Script:Phporto) 6>&1 | Out-String
        $output | Should -Match '\[ OK \]'
        Should -Invoke -CommandName Stop-ScheduledTask -Times 1 -Exactly -ParameterFilter { $TaskName -eq 'PHPorto' }
    }

    It "is OK when nothing is running and there is no task" {
        Mock Get-CimInstance { @() }
        $output = (Invoke-Autostart -SubAction stop -Script $Script:Phporto) 6>&1 | Out-String
        $output | Should -Match '\[ OK \]'
        Should -Invoke -CommandName Stop-ScheduledTask -Times 0 -Exactly
        Should -Invoke -CommandName Stop-PhportoTree -Times 0 -Exactly
    }

    It "finds a serve opened by hand: <Caso>" -ForEach @(
        @{ Caso = 'with quotes (the task form)'; Linha = '"C:\php\php.exe" "{0}" serve';     Relativo = $false }
        @{ Caso = 'without quotes';              Linha = 'php.exe {0} serve';                 Relativo = $false }
        @{ Caso = 'with another case';           Linha = 'php.exe {1} serve';                 Relativo = $false }
        @{ Caso = 'with php options before';     Linha = 'php -d memory_limit=1G "{0}" serve'; Relativo = $false }
        @{ Caso = 'with a trailing option';      Linha = 'php.exe "{0}" serve --x';           Relativo = $false }
        @{ Caso = 'with a dotted path';          Linha = 'php.exe "{2}" serve';               Relativo = $false }
        @{ Caso = 'with a relative path';        Linha = 'php.exe phporto serve';             Relativo = $true }
        @{ Caso = 'with a dotted relative path'; Linha = 'php.exe .\phporto serve';           Relativo = $true }
    ) {
        $alvo = $Script:Phporto
        $linha = $Linha -f $alvo, $alvo.ToUpper(), (Join-Path $Script:Pasta ('outra\..\' + 'phporto'))
        Mock Get-CimInstance { @(New-FakePhp 4242 $linha) }
        if ($Relativo) { Mock Get-PhportoProcessCwd { $Script:Pasta } }

        $output = (Invoke-Autostart -SubAction stop -Script $alvo) 6>&1 | Out-String

        $output | Should -Match '\[ OK \]'
        $output | Should -Match '1 process tree'
        Should -Invoke -CommandName Stop-PhportoTree -Times 1 -Exactly -ParameterFilter { $ProcessId -eq 4242 }
    }

    It "finds a path with square brackets without treating them as wildcards" {
        $linha = 'php.exe "' + $Script:PhportoColchetes + '" serve'
        Mock Get-CimInstance { @(New-FakePhp 7001 $linha) }

        $output = (Invoke-Autostart -SubAction stop -Script $Script:PhportoColchetes) 6>&1 | Out-String

        $output | Should -Match '1 process tree'
        Should -Invoke -CommandName Stop-PhportoTree -Times 1 -Exactly -ParameterFilter { $ProcessId -eq 7001 }
    }

    It "does not match the sibling folder that a bracket pattern would catch" {
        $linha = 'php.exe "' + $Script:PhportoOutra + '" serve'
        Mock Get-CimInstance { @(New-FakePhp 7002 $linha) }

        $output = (Invoke-Autostart -SubAction stop -Script $Script:PhportoColchetes) 6>&1 | Out-String

        $output | Should -Match '0 process tree'
        Should -Invoke -CommandName Stop-PhportoTree -Times 0 -Exactly
    }

    It "ignores another project's serve and a php.exe without serve" {
        $linhas = @(
            ('php.exe "' + $Script:PhportoOutra + '" serve'),
            ('php.exe "' + $Script:Phporto + '" token'),
            'php.exe -S 127.0.0.1:4001 -t public public/router.php',
            'php.exe -r "echo 1;"',
            ('php.exe "' + $Script:Phporto + '"')
        )
        $i = 100
        $fakes = @($linhas | ForEach-Object { $i++; New-FakePhp $i $_ })
        Mock Get-CimInstance { $fakes }

        $output = (Invoke-Autostart -SubAction stop -Script $Script:Phporto) 6>&1 | Out-String

        $output | Should -Match '\[ OK \]'
        $output | Should -Match '0 process tree'
        $output | Should -Match '0 php.exe process\(es\) could not be confirmed'
        Should -Invoke -CommandName Stop-PhportoTree -Times 0 -Exactly
    }

    It "does not kill a relative path when the working directory cannot be read, and says so" {
        Mock Get-CimInstance { @(New-FakePhp 8001 'php.exe phporto serve') }

        $output = (Invoke-Autostart -SubAction stop -Script $Script:Phporto) 6>&1 | Out-String

        $output | Should -Match '\[ OK \]'
        $output | Should -Match '0 process tree'
        $output | Should -Match '1 php.exe process\(es\) could not be confirmed'
        Should -Invoke -CommandName Stop-PhportoTree -Times 0 -Exactly
    }

    It "does not kill a relative path whose working directory is another project" {
        Mock Get-CimInstance { @(New-FakePhp 8002 'php.exe phporto serve') }
        Mock Get-PhportoProcessCwd { $Script:Outra }

        $output = (Invoke-Autostart -SubAction stop -Script $Script:Phporto) 6>&1 | Out-String

        $output | Should -Match '0 process tree'
        Should -Invoke -CommandName Stop-PhportoTree -Times 0 -Exactly
    }

    It "does not kill a php.exe whose command line cannot be read, and counts it" {
        Mock Get-CimInstance { @(New-FakePhp 8003 $null; New-FakePhp 8004 '') }

        $output = (Invoke-Autostart -SubAction stop -Script $Script:Phporto) 6>&1 | Out-String

        $output | Should -Match '0 process tree'
        $output | Should -Match '2 php.exe process\(es\) could not be confirmed'
        Should -Invoke -CommandName Stop-PhportoTree -Times 0 -Exactly
    }

    It "kills only the confirmed ones among several" {
        $linhas = @(
            ('php.exe "' + $Script:Phporto + '" serve'),
            ('php.exe "' + $Script:PhportoOutra + '" serve'),
            ('"C:\php\php.exe" ' + $Script:Phporto.ToUpper() + ' serve')
        )
        $i = 200
        $fakes = @($linhas | ForEach-Object { $i++; New-FakePhp $i $_ })
        Mock Get-CimInstance { $fakes }

        $output = (Invoke-Autostart -SubAction stop -Script $Script:Phporto) 6>&1 | Out-String

        $output | Should -Match '2 process tree'
        Should -Invoke -CommandName Stop-PhportoTree -Times 1 -Exactly -ParameterFilter { $ProcessId -eq 201 }
        Should -Invoke -CommandName Stop-PhportoTree -Times 1 -Exactly -ParameterFilter { $ProcessId -eq 203 }
        Should -Invoke -CommandName Stop-PhportoTree -Times 0 -Exactly -ParameterFilter { $ProcessId -eq 202 }
    }
}

Describe "Split-PhportoCommandLine" {

    It "splits <Caso>" -ForEach @(
        @{ Caso = 'plain words';       Linha = 'php.exe phporto serve';              Esperado = @('php.exe', 'phporto', 'serve') }
        @{ Caso = 'quoted paths';      Linha = '"C:\a b\php.exe" "C:\c d\phporto" serve'; Esperado = @('C:\a b\php.exe', 'C:\c d\phporto', 'serve') }
        @{ Caso = 'a trailing slash';  Linha = '"C:\x\" serve';                      Esperado = @('C:\x" serve') }
        @{ Caso = 'a doubled slash';   Linha = '"C:\x\\" serve';                     Esperado = @('C:\x\', 'serve') }
        @{ Caso = 'extra spaces';      Linha = '  php   serve  ';                    Esperado = @('php', 'serve') }
        @{ Caso = 'an empty quote';    Linha = 'php "" serve';                       Esperado = @('php', '', 'serve') }
    ) {
        $r = Split-PhportoCommandLine $Linha
        $r.Count | Should -Be $Esperado.Count
        for ($k = 0; $k -lt $r.Count; $k++) { $r[$k] | Should -BeExactly $Esperado[$k] }
    }
}

Describe "Get-PhportoProcessCwd" {

    It "reads the real working directory of a running process" {
        $pasta = $Script:Pasta
        $proc = Start-Process -FilePath 'powershell.exe' -ArgumentList '-NoProfile', '-Command', 'Start-Sleep -Seconds 20' -WorkingDirectory $pasta -PassThru -WindowStyle Hidden
        try {
            Start-Sleep -Milliseconds 1500
            $cwd = Get-PhportoProcessCwd -ProcessId $proc.Id
            if ($null -eq $cwd) {
                Set-ItResult -Skipped -Because 'the 64-bit process memory is not readable here'
            } else {
                $cwd.TrimEnd('\') | Should -BeExactly $pasta.TrimEnd('\')
            }
        } finally {
            Stop-Process -Id $proc.Id -Force -ErrorAction SilentlyContinue
        }
    }

    It "returns null for a process that does not exist" {
        Get-PhportoProcessCwd -ProcessId 2147483000 | Should -BeNullOrEmpty
    }
}
