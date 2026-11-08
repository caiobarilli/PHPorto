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
        $output = (Invoke-Autostart -SubAction on -UserName $Script:Eu -Program $Script:PhpExe -Script $Script:Phporto) 6>&1 | Out-String

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
        (Invoke-Autostart -SubAction on -UserName $Script:Eu -Program $Script:PhpExe -Script $Script:Phporto) 6>&1 | Out-Null
        Should -Invoke -CommandName New-ScheduledTaskAction -Times 1 -Exactly -ParameterFilter {
            $Execute -eq $Script:PhpExe -and $Argument -eq ('"' + $Script:Phporto + '" serve')
        }
    }

    It "refuses (<Caso>) without registering anything" -ForEach @(
        @{ Caso = 'another account';  User = 'OUTRO\alguem'; Php = 'ok';      Roteiro = 'ok' }
        @{ Caso = 'empty account';    User = '';             Php = 'ok';      Roteiro = 'ok' }
        @{ Caso = 'not php.exe';      User = 'eu';           Php = 'notepad'; Roteiro = 'ok' }
        @{ Caso = 'not phporto file'; User = 'eu';           Php = 'ok';      Roteiro = 'token.php' }
    ) {
        $user = if ($User -eq 'eu') { $Script:Eu } else { $User }
        $programa = if ($Php -eq 'ok') { $Script:PhpExe } else { Join-Path $Script:Pasta 'notepad.exe' }
        $arquivo = if ($Roteiro -eq 'ok') { $Script:Phporto } else { Join-Path $Script:Pasta $Roteiro }
        $output = (Invoke-Autostart -SubAction on -UserName $user -Program $programa -Script $arquivo) 6>&1 | Out-String
        $output | Should -Match '\[ ERROR \]'
        Should -Invoke -CommandName Register-ScheduledTask -Times 0 -Exactly
    }

    It "reports the failure when the registration throws" {
        Mock Register-ScheduledTask { throw 'acesso negado' }
        $output = (Invoke-Autostart -SubAction on -UserName $Script:Eu -Program $Script:PhpExe -Script $Script:Phporto) 6>&1 | Out-String
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

    It "needs the phporto script" {
        $output = (Invoke-Autostart -SubAction stop -Script (Join-Path $Script:Pasta 'token.php')) 6>&1 | Out-String
        $output | Should -Match '\[ ERROR \]'
    }

    It "stops the scheduled task when it exists" {
        Mock Get-CimInstance { @() }
        Mock Get-ScheduledTask { [PSCustomObject]@{ TaskName = 'PHPorto' } }
        Mock Stop-ScheduledTask { }
        $output = (Invoke-Autostart -SubAction stop -Script $Script:Phporto) 6>&1 | Out-String
        $output | Should -Match '\[ OK \]'
        Should -Invoke -CommandName Stop-ScheduledTask -Times 1 -Exactly -ParameterFilter { $TaskName -eq 'PHPorto' }
    }

    It "is OK when nothing is running and there is no task" {
        Mock Get-CimInstance { @() }
        Mock Get-ScheduledTask { }
        Mock Stop-ScheduledTask { }
        $output = (Invoke-Autostart -SubAction stop -Script $Script:Phporto) 6>&1 | Out-String
        $output | Should -Match '\[ OK \]'
        Should -Invoke -CommandName Stop-ScheduledTask -Times 0 -Exactly
    }
}
