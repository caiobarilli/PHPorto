#Requires -Version 5.1
<#
.SYNOPSIS
    Pester 5+ tests for the actions that have no file of their own yet.
.DESCRIPTION
    Migrated from winutil-cli.Tests.ps1. Covers parameter validation and
    mock-based execution of audit, performance, dns, processes and optimize.
    Invoke-GPU and Invoke-Gdid have their own files next to this one.

    WHAT WAS DROPPED, and why:

      - the whole BeforeAll: src/Win/bootstrap.ps1 does the same job, and doing
        it here again would test a copy of the setup instead of the real one
      - "winutil-cli.ps1 exists in root": that entry point does not migrate;
        the /win screen is the menu now
      - the config/ and functions/ sanity checks: Bootstrap.Tests.ps1 covers
        the same ground against the new tree, including the two JSON that did
        not migrate
      - "Entry point wiring": the ValidateSet and the dispatch of the old entry
        point are gone; the mapping is now tested in Bootstrap.Tests.ps1, and
        the allowlists in the Pest suite
      - the transcript to C:\log\: the gate reads the Pester exit code, and
        C:\log\ belongs to the audit action

    Everything else is the original test, unchanged.
#>

BeforeAll {
    $Script:RaizProjeto = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $Script:PastaWin    = Join-Path $Script:RaizProjeto 'src\Win'

    # Loads Write-Status, $sync, $root, lib/ and every action.
    . (Join-Path $Script:PastaWin 'bootstrap.ps1')
}

# ==============================================================
# SANITY
# ==============================================================
Describe "Sanity" {

    It "audit/audit.ps1 exists where \$root says it does" {
        Test-Path (Join-Path $Script:PastaWin 'audit\audit.ps1') | Should -BeTrue
    }

    It "files in lib/ have no syntax errors" {
        $invalidos = @()
        Get-ChildItem -Path (Join-Path $Script:PastaWin 'lib') -Filter '*.ps1' -File |
            ForEach-Object {
                $erros = $null
                [System.Management.Automation.Language.Parser]::ParseFile(
                    $_.FullName, [ref]$null, [ref]$erros
                ) | Out-Null
                if ($erros.Count -gt 0) { $invalidos += $_.Name }
            }
        $invalidos | Should -BeNullOrEmpty -Because "all .ps1 files in lib/ must have valid syntax"
    }
}

# ==============================================================
# NO PROMPTS — added on migration
# ==============================================================
#
# Three actions used to ask. Inside the elevated worker there is nobody to
# type: Read-Host in a -NonInteractive process either fails with an error that
# explains nothing to whoever clicked, or hangs the run until the timeout.
Describe "No prompts in the non-interactive path" {

    It "-Action exporter with no -SubAction refuses instead of opening a menu" {
        $output = (Invoke-Exporter) 6>&1 | Out-String
        $output | Should -Match '\[ ERROR \].*No subaction specified'
        $output | Should -Match '\[ INFO \].*install, status, start, stop, metrics, firewall'
    }

    <#
        Invoke-Network is checked statically, and the reason is worth writing
        down: its -Interface refusal sits AFTER the tshark discovery, so
        reaching it means either having Wireshark installed or letting the
        function reach `winget install` — a test that installs software.
        Mocking the probe is not available either: Pester refuses to mock a
        command that does not exist, and tshark is not on PATH here.

        So this pins the shape instead: the question is gone, the refusal that
        already existed below it is intact. The behaviour is covered where it
        can be covered honestly — Bootstrap.Tests.ps1 proves no action file
        contains Read-Host at all.
    #>
    It "-Action network no longer asks for the interface, and still refuses without it" {
        $texto = Get-Content -Path (Join-Path $Script:PastaWin 'actions\Invoke-Network.ps1') -Raw

        $texto | Should -Not -Match 'Read-Host'
        $texto | Should -Match 'No interface specified\. Aborting\.'
    }
}

# ==============================================================
# PARAMETER VALIDATION
# ==============================================================
Describe "Parameter Validation" {

    # 6>&1 redirects the Information stream (Write-Host PS 5+) to the pipeline
    It "-Action dns without -Provider returns [ ERROR ]" {
        $output = (Invoke-DNS -Provider '') 6>&1 | Out-String
        $output | Should -Match '\[ ERROR \]'
    }

    It "-Action install without -Apps returns [ ERROR ]" {
        $output = (Invoke-Install -Apps '') 6>&1 | Out-String
        $output | Should -Match '\[ ERROR \]'
    }

    It "-Action dns -Provider custom without -PrimaryDNS returns [ ERROR ]" {
        $output = (Invoke-DNS -Provider 'custom' -PrimaryDNS '') 6>&1 | Out-String
        $output | Should -Match '\[ ERROR \]'
    }

    It "-Action optimize without -Preset or -Kill returns [ ERROR ]" {
        $output = (Invoke-Optimize) 6>&1 | Out-String
        $output | Should -Match '\[ ERROR \]'
    }

    It "-Action optimize with unknown -Preset returns [ ERROR ]" {
        $output = (Invoke-Optimize -Preset 'invalid') 6>&1 | Out-String
        $output | Should -Match '\[ ERROR \]'
    }
}

# ==============================================================
# EXECUTION WITH MOCK
# ==============================================================
Describe "Execution with Mock" {

    Context "-Action audit" {
        It "generates the 8 audit files in C:\log\DD.MM.YYYY\" {
            Invoke-Audit
            $logDir    = "C:\log\$(Get-Date -Format 'dd.MM.yyyy')"
            $esperados = @(
                '01-system.txt', '02-hardware.txt', '03-processes.txt',
                '04-services.txt', '05-startup.txt',  '06-network.txt',
                '07-tasks.txt',  '08-hyperv.txt'
            )
            foreach ($f in $esperados) {
                Test-Path (Join-Path $logDir $f) |
                    Should -BeTrue -Because "audit must generate the file $f"
            }
        }
    }

    Context "-Action performance" {
        It "calls powercfg without throwing an exception" {
            # Returns simulated list already containing the original GUID (Priority 1)
            Mock powercfg {
                "Power Scheme GUID: e9a42b02-d5df-448d-aa00-03f14749eb61  (Ultimate Performance)"
            }
            { Invoke-Performance -State 'on' } | Should -Not -Throw
        }
    }

    Context "-Action dns -Provider cloudflare" {
        It "calls Set-WinUtilDNS with the correct provider" {
            Mock Set-WinUtilDNS { }
            Invoke-DNS -Provider 'cloudflare'
            Should -Invoke -CommandName Set-WinUtilDNS -Times 1 `
                -ParameterFilter { $DNSProvider -eq 'cloudflare' }
        }
    }

    Context "-Action processes" {
        It "Invoke-Processes returns 30 lines of process output" {
            $output = Invoke-Processes 6>&1 | Out-String
            $lines = ($output -split "`n") | Where-Object { $_ -match '\S' }
            $lines.Count | Should -BeGreaterOrEqual 30
        }
    }

    Context "-Action optimize" {
        It "-Preset ssh calls Set-Service Disabled and Stop-Service for service-backed, Stop-Process for process-only" {
            Mock Get-Process { [PSCustomObject]@{ Name = 'mock' } }
            Mock Get-Service { [PSCustomObject]@{ Name = 'mock'; Status = 'Running'; StartType = 'Automatic' } }
            Mock Set-Service { }
            Mock Stop-Service { }
            Mock Stop-Process { }
            Mock Test-Path { $true }
            Mock Set-Content { }
            Invoke-Optimize -Preset 'ssh'
            # 5 service-backed: SearchHost (WSearch), TextInputHost, OfficeClickToRun, LDSvc (PaceLicenseDServices), WslService
            Should -Invoke -CommandName Set-Service  -Times 5
            Should -Invoke -CommandName Stop-Service -Times 5
            # 6 process-only: LogonUI, StartMenuExperienceHost, ShellExperienceHost, ShellHost, msedgewebview2, cowork-svc
            Should -Invoke -CommandName Stop-Process -Times 6
        }

        It "-Kill stops each process in the comma-separated list" {
            Mock Get-Process { [PSCustomObject]@{ Name = 'mock' } }
            Mock Stop-Process { }
            Invoke-Optimize -Kill 'notepad,calc'
            Should -Invoke -CommandName Stop-Process -Times 2
        }

        It "-Preset ssh combined with -Kill stops preset + custom processes" {
            Mock Get-Process { [PSCustomObject]@{ Name = 'mock' } }
            Mock Get-Service { [PSCustomObject]@{ Name = 'mock'; Status = 'Running'; StartType = 'Automatic' } }
            Mock Set-Service { }
            Mock Stop-Service { }
            Mock Stop-Process { }
            Mock Test-Path { $true }
            Mock Set-Content { }
            Invoke-Optimize -Preset 'ssh' -Kill 'notepad'
            # 5 service-backed from preset + 0 extra = 5
            Should -Invoke -CommandName Stop-Service -Times 5
            # 6 process-only from preset + 1 custom kill
            Should -Invoke -CommandName Stop-Process -Times 7
        }

        It "not-running process emits [ WARNING ] instead of [ OK ]" {
            Mock Get-Process { $null }
            Mock Stop-Process { }
            $output = (Invoke-Optimize -Kill 'ghostproc') 6>&1 | Out-String
            $output | Should -Match '\[ WARNING \]'
            Should -Invoke -CommandName Stop-Process -Times 0
        }

        It "-Preset ssh does not throw" {
            Mock Get-Process { $null }
            Mock Get-Service { $null }
            Mock Get-ScheduledTask { $null }
            Mock Set-Service { }
            Mock Stop-Process { }
            Mock Stop-Service { }
            Mock Disable-ScheduledTask { }
            { Invoke-Optimize -Preset 'ssh' } | Should -Not -Throw
        }

        It "-Undo restores services from state file and deletes it" {
            Mock Test-Path { $true } -ParameterFilter { $Path -eq 'C:\WinUtil\optimize-state.json' }
            Mock Get-Content { '{"services":{"WSearch":"Automatic","ClickToRunSvc":"Automatic"}}' }
            Mock Set-Service   { }
            Mock Start-Service { }
            Mock Remove-Item   { }
            Invoke-Optimize -Undo
            Should -Invoke -CommandName Set-Service   -Times 2
            Should -Invoke -CommandName Start-Service -Times 2
            Should -Invoke -CommandName Remove-Item   -Times 1
        }

        It "-Undo with missing state file emits [ ERROR ]" {
            Mock Test-Path { $false } -ParameterFilter { $Path -eq 'C:\WinUtil\optimize-state.json' }
            $output = (Invoke-Optimize -Undo) 6>&1 | Out-String
            $output | Should -Match '\[ ERROR \]'
        }

        It "-Preset kill-rdp calls Set-Service Disabled and Stop-Service for service-backed, Stop-Process for process-only" {
            Mock Get-Process { [PSCustomObject]@{ Name = 'mock' } }
            Mock Get-Service { [PSCustomObject]@{ Name = 'mock'; Status = 'Running'; StartType = 'Automatic' } }
            Mock Set-Service { }
            Mock Stop-Service { }
            Mock Stop-Process { }
            Mock Test-Path { $true }
            Mock Set-Content { }
            Mock query { @(" SESSIONNAME       USERNAME                 ID  STATE   TYPE") }
            Mock logoff { }
            Invoke-Optimize -Preset 'kill-rdp'
            # 2 service-backed: SearchHost (WSearch), TextInputHost (TextInputManagementService)
            Should -Invoke -CommandName Set-Service  -Times 2
            Should -Invoke -CommandName Stop-Service -Times 2
            # 10 process-only: explorer, StartMenuExperienceHost, ShellExperienceHost, ShellHost,
            #                   msedgewebview2, dwm, sihost, RuntimeBroker, backgroundTaskHost, CrossDeviceResume
            Should -Invoke -CommandName Stop-Process -Times 10
        }

        It "-Preset kill-rdp does not throw" {
            Mock Get-Process { $null }
            Mock Get-Service { $null }
            Mock Set-Service { }
            Mock Stop-Process { }
            Mock Stop-Service { }
            Mock query { @(" SESSIONNAME       USERNAME                 ID  STATE   TYPE") }
            Mock logoff { }
            { Invoke-Optimize -Preset 'kill-rdp' } | Should -Not -Throw
        }

        It "-Preset kill-rdp emits INFO when no disconnected sessions are found" {
            Mock Get-Process { [PSCustomObject]@{ Name = 'mock'; SessionId = 99 } }
            Mock Get-Service { [PSCustomObject]@{ Name = 'mock'; Status = 'Running'; StartType = 'Automatic' } }
            Mock Set-Service { }
            Mock Stop-Service { }
            Mock Stop-Process { }
            Mock Test-Path { $true }
            Mock Set-Content { }
            Mock query { @(" SESSIONNAME       USERNAME                 ID  STATE   TYPE") }
            Mock logoff { }
            $output = (Invoke-Optimize -Preset 'kill-rdp') 6>&1 | Out-String
            $output | Should -Match 'No disconnected RDP sessions found'
            Should -Invoke -CommandName logoff -Times 0
        }

        It "-Preset kill-rdp logs off a disconnected session and emits OK" {
            Mock Get-Process { [PSCustomObject]@{ Name = 'mock'; SessionId = 99 } }
            Mock Get-Process { $null } -ParameterFilter { $Name -contains 'rdpclip' }
            Mock Get-Service { [PSCustomObject]@{ Name = 'mock'; Status = 'Running'; StartType = 'Automatic' } }
            Mock Set-Service { }
            Mock Stop-Service { }
            Mock Stop-Process { }
            Mock Test-Path { $true }
            Mock Set-Content { }
            Mock query {
                @(
                    " SESSIONNAME       USERNAME                 ID  STATE   TYPE",
                    " rdp-tcp#2         caiob                    2  Disc    rdpwd"
                )
            }
            Mock logoff { }
            $output = (Invoke-Optimize -Preset 'kill-rdp') 6>&1 | Out-String
            $output | Should -Match 'Found 1 disconnected RDP session'
            $output | Should -Match '\[ OK \].*Logged off session 2.*caiob'
            Should -Invoke -CommandName logoff -Times 1
        }

        It "-Preset kill-rdp logs off a disconnected session with pt-BR state string (Disco)" {
            Mock Get-Process { [PSCustomObject]@{ Name = 'mock'; SessionId = 99 } }
            Mock Get-Process { $null } -ParameterFilter { $Name -contains 'rdpclip' }
            Mock Get-Service { [PSCustomObject]@{ Name = 'mock'; Status = 'Running'; StartType = 'Automatic' } }
            Mock Set-Service { }
            Mock Stop-Service { }
            Mock Stop-Process { }
            Mock Test-Path { $true }
            Mock Set-Content { }
            Mock query {
                @(
                    " SESSIONNAME       USERNAME                 ID  STATE   TYPE",
                    " rdp-tcp#2         caiob                    2  Disco   rdpwd"
                )
            }
            Mock logoff { }
            $output = (Invoke-Optimize -Preset 'kill-rdp') 6>&1 | Out-String
            $output | Should -Match 'Found 1 disconnected RDP session'
            Should -Invoke -CommandName logoff -Times 1
        }

        It "-Preset kill-rdp logs off a disconnected session with es state string (Descon)" {
            Mock Get-Process { [PSCustomObject]@{ Name = 'mock'; SessionId = 99 } }
            Mock Get-Process { $null } -ParameterFilter { $Name -contains 'rdpclip' }
            Mock Get-Service { [PSCustomObject]@{ Name = 'mock'; Status = 'Running'; StartType = 'Automatic' } }
            Mock Set-Service { }
            Mock Stop-Service { }
            Mock Stop-Process { }
            Mock Test-Path { $true }
            Mock Set-Content { }
            Mock query {
                @(
                    " SESSIONNAME       USERNAME                 ID  STATE   TYPE",
                    " rdp-tcp#5         pedro                    5  Descon  rdpwd"
                )
            }
            Mock logoff { }
            $output = (Invoke-Optimize -Preset 'kill-rdp') 6>&1 | Out-String
            $output | Should -Match 'Found 1 disconnected RDP session'
            Should -Invoke -CommandName logoff -Times 1
        }

        It "-Preset kill-rdp -KeepUser skips the protected user's session (case-insensitive)" {
            Mock Get-Process { [PSCustomObject]@{ Name = 'mock'; SessionId = 99 } }
            Mock Get-Process { $null } -ParameterFilter { $Name -contains 'rdpclip' }
            Mock Get-Service { [PSCustomObject]@{ Name = 'mock'; Status = 'Running'; StartType = 'Automatic' } }
            Mock Set-Service { }
            Mock Stop-Service { }
            Mock Stop-Process { }
            Mock Test-Path { $true }
            Mock Set-Content { }
            Mock query {
                @(
                    " SESSIONNAME       USERNAME                 ID  STATE   TYPE",
                    " rdp-tcp#2         caiob                    2  Disc    rdpwd",
                    " rdp-tcp#3         jsmith                   3  Disc    rdpwd"
                )
            }
            Mock logoff { }
            $output = (Invoke-Optimize -Preset 'kill-rdp' -KeepUser 'CAIOB') 6>&1 | Out-String
            $output | Should -Match '\[ WARNING \].*Skipped session 2'
            $output | Should -Match '\[ OK \].*Logged off session 3.*jsmith'
            Should -Invoke -CommandName logoff -Times 1
        }
    }
}
