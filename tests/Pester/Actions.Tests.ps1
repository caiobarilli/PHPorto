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

    Context "-Action debloat" {
        It "removes exactly the packages in config/debloat.json, in file order" {
            $script:removidos = @()
            Mock Remove-WinUtilAPPX { $script:removidos += $Name }

            Invoke-Debloat 6>$null

            # Two steps on purpose: in 5.1, ConvertFrom-Json emits a JSON array as ONE
            # pipeline object, and @(... | ConvertFrom-Json) would wrap it into a single item.
            $esperados = Get-Content (Join-Path $Script:PastaWin 'config\debloat.json') -Raw | ConvertFrom-Json
            $esperados.Count | Should -Be 22
            $script:removidos | Should -Be $esperados
        }

        It "-Packages removes only the listed packages, in the given order" {
            $script:removidos = @()
            Mock Remove-WinUtilAPPX { $script:removidos += $Name }

            $params = @{ 'Packages' = @('MicrosoftTeams', 'Microsoft.BingNews') }
            $output = (Invoke-Debloat @params) 6>&1 | Out-String

            $script:removidos | Should -Be @('MicrosoftTeams', 'Microsoft.BingNews')
            $output | Should -Match '\[ OK \] Debloat complete'
        }

        It "-Packages with a package outside debloat.json is an ERROR, and the summary says so" {
            Mock Remove-WinUtilAPPX { }
            $output = (Invoke-Debloat -Packages 'Microsoft.BingNews,Microsoft.WindowsCalculator') 6>&1 | Out-String

            $output | Should -Match '\[ ERROR \] Microsoft.WindowsCalculator -> not in debloat.json, not removed'
            $output | Should -Match '\[ ERROR \] Debloat finished with 1 error\(s\): Microsoft.WindowsCalculator'
            Should -Invoke Remove-WinUtilAPPX -Times 1 -Exactly
        }

        It "a removal that throws makes the summary an ERROR, not 'complete'" {
            Mock Remove-WinUtilAPPX { throw 'boom' }
            $output = (Invoke-Debloat -Packages 'Microsoft.BingNews') 6>&1 | Out-String

            $output | Should -Match '\[ ERROR \] Microsoft.BingNews -> boom'
            $output | Should -Not -Match 'Debloat complete'
        }
        It "warns and removes nothing when the list is empty" {
            Mock Remove-WinUtilAPPX { }
            $salvo = $global:sync.configs.debloat
            try {
                $global:sync.configs.debloat = @()
                $output = (Invoke-Debloat) 6>&1 | Out-String
            } finally {
                $global:sync.configs.debloat = $salvo
            }
            $output | Should -Match '\[ WARNING \] No packages defined for removal'
            Should -Invoke Remove-WinUtilAPPX -Times 0 -Exactly
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

    Context "-Action dns -Provider custom" {
        BeforeEach {
            Mock Get-NetAdapter {
                @(
                    [PSCustomObject]@{ Name = 'Ethernet'; ifIndex = 3; Status = 'Up' }
                    [PSCustomObject]@{ Name = 'Wi-Fi'; ifIndex = 7; Status = 'Up' }
                    [PSCustomObject]@{ Name = 'Parada'; ifIndex = 9; Status = 'Disconnected' }
                )
            }
            Mock Set-WinUtilDNS { }
        }

        It "sets the typed servers on every adapter that is up, and not through dns.json" {
            Mock Set-DnsClientServerAddress { }
            $output = (Invoke-DNS -Provider 'Custom' -PrimaryDNS '192.168.1.10' -SecondaryDNS '1.0.0.1') 6>&1 | Out-String

            Should -Invoke Set-DnsClientServerAddress -Times 2 -Exactly -ParameterFilter {
                ($ServerAddresses -join ',') -eq '192.168.1.10,1.0.0.1'
            }
            Should -Invoke Set-DnsClientServerAddress -Times 0 -Exactly -ParameterFilter { $InterfaceIndex -eq 9 }
            Should -Invoke Set-WinUtilDNS -Times 0 -Exactly
            $output | Should -Match "\[ OK \] DNS 'Custom' applied"
        }

        It "with only the primary, sets only the primary" {
            Mock Set-DnsClientServerAddress { }
            (Invoke-DNS -Provider 'Custom' -PrimaryDNS '192.168.1.10') 6>&1 | Out-Null

            Should -Invoke Set-DnsClientServerAddress -Times 2 -Exactly -ParameterFilter {
                ($ServerAddresses -join ',') -eq '192.168.1.10'
            }
        }

        It "an adapter that refuses is an ERROR, and the summary says so" {
            Mock Set-DnsClientServerAddress { if ($InterfaceIndex -eq 7) { throw 'recusado' } }
            $output = (Invoke-DNS -Provider 'Custom' -PrimaryDNS '192.168.1.10') 6>&1 | Out-String

            $output | Should -Match '\[ OK \] Ethernet'
            $output | Should -Match '\[ ERROR \] Wi-Fi -> recusado'
            $output | Should -Match '\[ ERROR \] Custom DNS finished with 1 error\(s\): Wi-Fi'
            $output | Should -Not -Match "DNS 'Custom' applied"
        }

        It "with no adapter up, says nothing changed" {
            Mock Get-NetAdapter { @() }
            Mock Set-DnsClientServerAddress { }
            $output = (Invoke-DNS -Provider 'Custom' -PrimaryDNS '192.168.1.10') 6>&1 | Out-String

            $output | Should -Match '\[ ERROR \] No network adapter is up; nothing changed'
            Should -Invoke Set-DnsClientServerAddress -Times 0 -Exactly
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

# ==============================================================
# INVOKE-TWEAKS — missing commands are errors, never OK
# ==============================================================
Describe "Invoke-Tweaks with a tweak that reaches a missing command" {

    BeforeAll {
        $tweaks = $global:sync.configs.tweaks
        $tweaks | Add-Member -Force -NotePropertyName 'PhportoTestOk' -NotePropertyValue (
            [pscustomobject]@{ InvokeScript = @('Get-Date | Out-Null'); UndoScript = @('Get-Date | Out-Null') })
        $tweaks | Add-Member -Force -NotePropertyName 'PhportoTestMissing' -NotePropertyValue (
            [pscustomobject]@{ InvokeScript = @('Invoke-PhportoDoesNotExist'); UndoScript = @('Get-Date | Out-Null') })
        $tweaks | Add-Member -Force -NotePropertyName 'PhportoTestNested' -NotePropertyValue (
            [pscustomobject]@{ InvokeScript = @('Invoke-PhportoTestWrapper') })

        function global:Invoke-PhportoTestWrapper { Invoke-PhportoAlsoMissing }

        $global:sync.configs.preset | Add-Member -Force -NotePropertyName 'Phportotest' -NotePropertyValue @(
            'PhportoTestOk', 'PhportoTestMissing', 'PhportoTestNested')
        $global:sync.configs.preset | Add-Member -Force -NotePropertyName 'Phportoclean' -NotePropertyValue @('PhportoTestOk')
    }

    AfterAll {
        foreach ($k in 'PhportoTestOk', 'PhportoTestMissing', 'PhportoTestNested') {
            $global:sync.configs.tweaks.PSObject.Properties.Remove($k)
        }
        foreach ($k in 'Phportotest', 'Phportoclean') {
            $global:sync.configs.preset.PSObject.Properties.Remove($k)
        }
        Remove-Item function:global:Invoke-PhportoTestWrapper -ErrorAction SilentlyContinue
    }

    It "reports the item as ERROR and does not apply it" {
        Mock Invoke-WinUtilTweaks { }
        $output = (Invoke-Tweaks -Preset phportotest) 6>&1 | Out-String

        $output | Should -Match '\[ ERROR \] PhportoTestMissing -> not applied, missing command: Invoke-PhportoDoesNotExist'
        $output | Should -Not -Match '\[ OK \] PhportoTestMissing'
        Should -Invoke Invoke-WinUtilTweaks -Times 1 -Exactly -ParameterFilter { $CheckBox -eq 'PhportoTestOk' }
        Should -Invoke Invoke-WinUtilTweaks -Times 0 -Exactly -ParameterFilter { $CheckBox -ne 'PhportoTestOk' }
    }

    It "follows a function that exists into the command it is missing" {
        Mock Invoke-WinUtilTweaks { }
        $output = (Invoke-Tweaks -Preset phportotest) 6>&1 | Out-String

        $output | Should -Match '\[ ERROR \] PhportoTestNested -> not applied, missing command: Invoke-PhportoAlsoMissing'
    }

    It "ends with an ERROR summary instead of success" {
        Mock Invoke-WinUtilTweaks { }
        $output = (Invoke-Tweaks -Preset phportotest) 6>&1 | Out-String

        $output | Should -Match "\[ ERROR \] Preset 'Phportotest' applying finished with 2 error\(s\): PhportoTestMissing, PhportoTestNested"
        $output | Should -Not -Match 'successfully'
    }

    It "checks the undo script when reverting, not the apply script" {
        Mock Invoke-WinUtilTweaks { }
        $output = (Invoke-Tweaks -Preset phportotest -Undo) 6>&1 | Out-String

        $output | Should -Match "\[ OK \] Preset 'Phportotest' reverting successfully"
    }

    It "still reports success when every command resolves" {
        Mock Invoke-WinUtilTweaks { }
        $output = (Invoke-Tweaks -Preset phportoclean) 6>&1 | Out-String

        $output | Should -Match "\[ OK \] Preset 'Phportoclean' applying successfully"
    }

    It "-Items applies exactly the listed tweaks, as a selection" {
        Mock Invoke-WinUtilTweaks { }
        $output = (Invoke-Tweaks -Items 'PhportoTestOk') 6>&1 | Out-String

        Should -Invoke Invoke-WinUtilTweaks -Times 1 -Exactly -ParameterFilter { $CheckBox -eq 'PhportoTestOk' }
        $output | Should -Match 'Applying selection \(1 tweaks\)'
        $output | Should -Match "\[ OK \] Selection applying successfully"
    }

    It "-Items binds the string array the worker splats, and still reports missing commands" {
        Mock Invoke-WinUtilTweaks { }
        $params = @{ 'Items' = @('PhportoTestOk', 'PhportoTestMissing') }
        $output = (Invoke-Tweaks @params) 6>&1 | Out-String

        $output | Should -Match '\[ ERROR \] PhportoTestMissing -> not applied, missing command'
        $output | Should -Match "\[ ERROR \] Selection applying finished with 1 error\(s\): PhportoTestMissing"
    }

    It "-Items also accepts a comma-separated string" {
        Mock Invoke-WinUtilTweaks { }
        (Invoke-Tweaks -Items 'PhportoTestOk, PhportoTestOk') 6>&1 | Out-Null

        Should -Invoke Invoke-WinUtilTweaks -Times 2 -Exactly
    }

    It "-Items with a key that is not in tweaks.json is an ERROR and is not applied" {
        Mock Invoke-WinUtilTweaks { }
        $output = (Invoke-Tweaks -Items 'PhportoNoSuchTweak') 6>&1 | Out-String

        $output | Should -Match '\[ ERROR \] PhportoNoSuchTweak -> not applied, not in tweaks.json'
        Should -Invoke Invoke-WinUtilTweaks -Times 0 -Exactly
    }

    It "-Undo with -Items reverts the listed tweaks" {
        Mock Invoke-WinUtilTweaks { }
        $output = (Invoke-Tweaks -Items 'PhportoTestOk' -Undo) 6>&1 | Out-String

        Should -Invoke Invoke-WinUtilTweaks -Times 1 -Exactly -ParameterFilter { $undo -eq $true }
        $output | Should -Match "\[ OK \] Selection reverting successfully"
    }

    It "refuses -Preset and -Items together" {
        Mock Invoke-WinUtilTweaks { }
        $output = (Invoke-Tweaks -Preset phportoclean -Items 'PhportoTestOk') 6>&1 | Out-String

        $output | Should -Match '\[ ERROR \] Use -Preset or -Items, not both'
        Should -Invoke Invoke-WinUtilTweaks -Times 0 -Exactly
    }

    It "refuses a call with neither" {
        $output = (Invoke-Tweaks) 6>&1 | Out-String
        $output | Should -Match '\[ ERROR \] No preset or items given'
    }
    It "no tweak in tweaks.json reaches a missing command, applying or reverting" {
        $achados = @()
        foreach ($tweak in $global:sync.configs.tweaks.PSObject.Properties.Name) {
            if ($tweak -like 'PhportoTest*') { continue }
            foreach ($undo in $false, $true) {
                $faltam = @(Get-PhportoMissingTweakCommand -CheckBox $tweak -Undo:$undo)
                if ($faltam.Count -gt 0) { $achados += "$tweak undo=$undo -> $($faltam -join ', ')" }
            }
        }
        $achados | Should -BeNullOrEmpty -Because 'a tweak that reaches a missing command is reported as ERROR and never applied'
    }
}

# ==============================================================
# INSTALL — one exact ID at a time, and each exit code read
# ==============================================================
Describe "Install-WinUtilProgramWinget builds a command that cannot stop to ask" {

    It "runs winget once per program, by exact ID, accepting both agreements" {
        $script:argumentos = @()
        Mock Start-Process { $script:argumentos += $ArgumentList; [PSCustomObject]@{ ExitCode = 0 } }

        $r = @(Install-WinUtilProgramWinget -Action Install -Programs @('Git.Git', 'VB-Audio.Voicemeeter.Potato'))

        $script:argumentos.Count | Should -Be 2
        $script:argumentos[0] | Should -Be 'install --id "Git.Git" --exact --accept-package-agreements --accept-source-agreements --source winget --silent --disable-interactivity'
        $script:argumentos[1] | Should -Match '^install --id "VB-Audio.Voicemeeter.Potato" --exact '
        $r.Program  | Should -Be @('Git.Git', 'VB-Audio.Voicemeeter.Potato')
        $r.ExitCode | Should -Be @(0, 0)
    }

    It "waits for each process and returns its exit code" {
        Mock Start-Process { [PSCustomObject]@{ ExitCode = -1978335189 } }

        (Install-WinUtilProgramWinget -Action Install -Programs 'Git.Git').ExitCode | Should -Be -1978335189
        Should -Invoke Start-Process -Times 1 -Exactly -ParameterFilter { $Wait -and $PassThru }
    }
}

Describe "Invoke-Install reports each package from its winget exit code" {

    BeforeEach {
        Mock Install-WinUtilWinget { }
    }

    It "classifies the exit codes winget documents" {
        Get-PhportoWingetOutcome -ExitCode 0           | Should -Be 'ok'
        Get-PhportoWingetOutcome -ExitCode -1978335189 | Should -Be 'installed'
        Get-PhportoWingetOutcome -ExitCode -1978335135 | Should -Be 'installed'
        Get-PhportoWingetOutcome -ExitCode -1978334967 | Should -Be 'reboot'
        Get-PhportoWingetOutcome -ExitCode 1           | Should -Be 'error'
        Get-PhportoWingetOutcome -ExitCode $null       | Should -Be 'error'
    }

    It "all installed says complete" {
        Mock Install-WinUtilProgramWinget { [PSCustomObject]@{ Program = 'Git.Git'; ExitCode = 0 }; [PSCustomObject]@{ Program = '7zip.7zip'; ExitCode = -1978335135 } }
        $output = (Invoke-Install -Apps 'Git.Git,7zip.7zip') 6>&1 | Out-String

        $output | Should -Match '\[ OK \] Git.Git installed'
        $output | Should -Match '\[ OK \] 7zip.7zip was already installed'
        $output | Should -Match '\[ OK \] Installation complete'
    }

    It "a failed package is an ERROR with the code in hex, and the summary is not complete" {
        Mock Install-WinUtilProgramWinget { [PSCustomObject]@{ Program = 'Git.Git'; ExitCode = 0 }; [PSCustomObject]@{ Program = 'Nada.Disso'; ExitCode = -1978335212 } }
        $output = (Invoke-Install -Apps 'Git.Git,Nada.Disso') 6>&1 | Out-String

        $output | Should -Match '\[ ERROR \] Nada.Disso -> winget exit code 0x8A150014'
        $output | Should -Match '\[ ERROR \] Installation finished with 1 error\(s\): Nada.Disso'
        $output | Should -Not -Match 'Installation complete'
    }

    It "a package that needs a restart is a WARNING, and the summary says to restart" {
        Mock Install-WinUtilProgramWinget { [PSCustomObject]@{ Program = 'VB-Audio.Voicemeeter.Potato'; ExitCode = -1978334967 } }
        $output = (Invoke-Install -Apps 'VB-Audio.Voicemeeter.Potato') 6>&1 | Out-String

        $output | Should -Match '\[ WARNING \] VB-Audio.Voicemeeter.Potato installed; Windows must restart to finish'
        $output | Should -Match '\[ WARNING \] Installation complete; restart Windows to finish: VB-Audio.Voicemeeter.Potato'
    }
}
