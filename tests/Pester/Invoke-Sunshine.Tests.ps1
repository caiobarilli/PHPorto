#Requires -Version 5.1
<#
.SYNOPSIS
    Pester 5+ tests for src/Win/actions/Invoke-Sunshine.ps1
.DESCRIPTION
    Mock-based unit tests for each SubAction. Nothing is installed and no service
    or firewall rule is touched: winget, the winget lib, service control and the
    firewall cmdlets are all mocked, so the suite runs unelevated and changes
    nothing. Pairing is a human step and has no subaction to test.
#>

BeforeAll {
    $Script:RaizProjeto = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $Script:PastaWin    = Join-Path $Script:RaizProjeto 'src\Win'
    $Script:Acao        = Join-Path $Script:PastaWin 'actions\Invoke-Sunshine.ps1'

    # Loads Write-Status, the winget lib and every action (Get-PhportoWingetOutcome included).
    . (Join-Path $Script:PastaWin 'bootstrap.ps1')
}

# ==============================================================
# SANITY
# ==============================================================
Describe "Invoke-Sunshine - Sanity" {

    It "Invoke-Sunshine.ps1 exists in actions/" {
        Test-Path $Script:Acao | Should -BeTrue
    }

    It "Invoke-Sunshine.ps1 has no syntax errors" {
        $errors = $null
        [System.Management.Automation.Language.Parser]::ParseFile($Script:Acao, [ref]$null, [ref]$errors) | Out-Null
        $errors.Count | Should -Be 0
    }

    It "Invoke-Sunshine function is available after dot-sourcing" {
        Get-Command -Name 'Invoke-Sunshine' -ErrorAction SilentlyContinue | Should -Not -BeNullOrEmpty
    }

    It "does not prompt and does not auto-elevate" {
        $text = Get-Content -Path $Script:Acao -Raw
        $text | Should -Not -Match 'Read-Host'
        $text | Should -Not -Match 'RunAs'
    }

    It "never reads a PIN and never persists one" {
        # Pairing is a human step; the action may point at the web UI but must not
        # capture a PIN or write it anywhere.
        $text = Get-Content -Path $Script:Acao -Raw
        $text | Should -Not -Match 'Read-Host'
        $text | Should -Not -Match 'Set-Content'
        $text | Should -Not -Match 'Out-File'
    }
}

# ==============================================================
# SUBACTION — missing / unknown
# ==============================================================
Describe "Invoke-Sunshine - SubAction validation" {

    It "emits [ ERROR ] and lists the options when no subaction is given" {
        $output = (Invoke-Sunshine) 6>&1 | Out-String
        $output | Should -Match '\[ ERROR \]'
        $output | Should -Match 'status, install, start, stop, firewall-open, firewall-close'
    }

    It "emits [ ERROR ] for an unrecognised subaction, pairing included" {
        $output = (Invoke-Sunshine -SubAction 'pair') 6>&1 | Out-String
        $output | Should -Match '\[ ERROR \]'
        $output | Should -Match 'status, install, start, stop, firewall-open, firewall-close'
    }
}

# ==============================================================
# SUBACTION — status (read only)
# ==============================================================
Describe "Invoke-Sunshine - status" {

    It "reports the service and install, and says paired clients cannot be read here" {
        Mock Get-Service { [PSCustomObject]@{ Name = 'SunshineService'; DisplayName = 'Sunshine Service'; Status = 'Running'; StartType = 'Automatic' } }
        Mock winget { "Sunshine  LizardByte.Sunshine  2026.914.233613  winget" }
        Mock Start-Service       { }
        Mock Stop-Service        { }
        Mock New-NetFirewallRule { }

        $output = (Invoke-Sunshine -SubAction 'status') 6>&1 | Out-String

        $output | Should -Match 'Service: SunshineService'
        $output | Should -Match 'Installed \(winget'
        $output | Should -Match 'Paired clients: cannot be read'

        Should -Invoke -CommandName Start-Service       -Times 0
        Should -Invoke -CommandName New-NetFirewallRule -Times 0
    }

    It "warns when the service is not found" {
        Mock Get-Service { $null }
        Mock winget      { "" }

        $output = (Invoke-Sunshine -SubAction 'status') 6>&1 | Out-String
        $output | Should -Match 'Service: not found'
    }
}

# ==============================================================
# SUBACTION — install
# ==============================================================
Describe "Invoke-Sunshine - install" {

    It "installs LizardByte.Sunshine through the winget lib and points to the web UI for pairing" {
        Mock Install-WinUtilWinget        { }
        Mock Install-WinUtilProgramWinget { [PSCustomObject]@{ Program = 'LizardByte.Sunshine'; ExitCode = 0 } }

        $output = (Invoke-Sunshine -SubAction 'install') 6>&1 | Out-String

        Should -Invoke -CommandName Install-WinUtilProgramWinget -Times 1 -ParameterFilter {
            $Action -eq 'Install' -and ($Programs -contains 'LizardByte.Sunshine')
        }
        $output | Should -Match '\[ OK \] LizardByte.Sunshine installed'
        $output | Should -Match 'https://localhost:47990'
        $output | Should -Match 'PHPorto does not do that step'
    }
}

# ==============================================================
# SUBACTION — start / stop (service state -> Applied)
# ==============================================================
Describe "Invoke-Sunshine - start and stop" {

    It "start finds the service, starts it, and points to pairing" {
        Mock Get-Service   { [PSCustomObject]@{ Name = 'SunshineService'; DisplayName = 'Sunshine'; Status = 'Stopped'; StartType = 'Automatic' } }
        Mock Start-Service { }

        $output = (Invoke-Sunshine -SubAction 'start') 6>&1 | Out-String

        Should -Invoke -CommandName Start-Service -Times 1 -ParameterFilter { $Name -eq 'SunshineService' }
        $output | Should -Match 'Sunshine service started'
        $output | Should -Match 'https://localhost:47990'
    }

    It "start errors when the service is not found" {
        Mock Get-Service { $null }
        $output = (Invoke-Sunshine -SubAction 'start') 6>&1 | Out-String
        $output | Should -Match '\[ ERROR \].*not found'
    }

    It "stop stops a running service" {
        Mock Get-Service  { [PSCustomObject]@{ Name = 'SunshineService'; Status = 'Running'; StartType = 'Automatic' } }
        Mock Stop-Service { }

        (Invoke-Sunshine -SubAction 'stop') 6>&1 | Out-Null

        Should -Invoke -CommandName Stop-Service -Times 1 -ParameterFilter { $Name -eq 'SunshineService' }
    }
}

# ==============================================================
# SUBACTION — firewall-open / firewall-close
# ==============================================================
Describe "Invoke-Sunshine - firewall" {

    It "firewall-open creates the 47990 inbound rule when absent" {
        Mock Get-NetFirewallRule { $null }
        Mock New-NetFirewallRule { }

        $output = (Invoke-Sunshine -SubAction 'firewall-open') 6>&1 | Out-String

        Should -Invoke -CommandName New-NetFirewallRule -Times 1 -ParameterFilter {
            $LocalPort -eq 47990 -and $Protocol -eq 'TCP' -and $Direction -eq 'Inbound'
        }
        $output | Should -Match 'Firewall rule created'
    }

    It "firewall-open is idempotent when the rule already exists" {
        Mock Get-NetFirewallRule { [PSCustomObject]@{ DisplayName = 'PHPorto - Sunshine 47990' } }
        Mock New-NetFirewallRule { }

        (Invoke-Sunshine -SubAction 'firewall-open') 6>&1 | Out-Null

        Should -Invoke -CommandName New-NetFirewallRule -Times 0
    }

    It "firewall-close removes the rule when present" {
        Mock Get-NetFirewallRule    { [PSCustomObject]@{ DisplayName = 'PHPorto - Sunshine 47990' } }
        Mock Remove-NetFirewallRule { }

        (Invoke-Sunshine -SubAction 'firewall-close') 6>&1 | Out-Null

        Should -Invoke -CommandName Remove-NetFirewallRule -Times 1
    }
}
