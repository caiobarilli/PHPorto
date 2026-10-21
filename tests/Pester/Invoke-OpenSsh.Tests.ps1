#Requires -Version 5.1

BeforeAll {
    $Script:RaizProjeto = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $Script:PastaWin    = Join-Path $Script:RaizProjeto 'src\Win'
    $Script:Acao        = Join-Path $Script:PastaWin 'actions\Invoke-OpenSsh.ps1'

    . (Join-Path $Script:PastaWin 'bootstrap.ps1')

    $stubs = @{
        'Get-WindowsCapability'   = { param([switch]$Online, [string]$Name) }
        'Add-WindowsCapability'   = { param([switch]$Online, [string]$Name) }
        'Get-NetFirewallRule'     = { param([string]$Name) }
        'New-NetFirewallRule'     = { param([string]$Name, [string]$DisplayName, $Enabled, $Direction, $Action, $Protocol, $LocalPort, $Profile) }
        'Set-NetFirewallRule'     = { param([string]$Name, $Enabled, $Direction, $Action, $Protocol, $LocalPort, $Profile) }
        'Remove-NetFirewallRule'  = { param([string]$Name) }
        'Disable-NetFirewallRule' = { param([string]$Name) }
        'Get-Service'             = { param([string]$Name) }
        'Set-Service'             = { param([string]$Name, $StartupType) }
        'Start-Service'           = { param([string]$Name) }
        'Stop-Service'            = { param([string]$Name) }
    }
    foreach ($nome in $stubs.Keys) {
        if (-not (Get-Command -Name $nome -ErrorAction SilentlyContinue)) {
            Set-Item -Path "function:global:$nome" -Value $stubs[$nome]
        }
    }

    $Script:Regra = 'PHPorto-OpenSSH-In-TCP-22'
}

Describe "Invoke-OpenSsh - Sanity" {

    It "Invoke-OpenSsh.ps1 has no syntax errors" {
        $errors = $null
        [System.Management.Automation.Language.Parser]::ParseFile($Script:Acao, [ref]$null, [ref]$errors) | Out-Null
        $errors.Count | Should -Be 0
    }

    It "does not prompt, does not auto-elevate, and does not touch sshd_config or keys" {
        $text = Get-Content -Path $Script:Acao -Raw
        $text | Should -Not -Match 'Read-Host'
        $text | Should -Not -Match 'RunAs'
        $text | Should -Not -Match 'sshd_config'
        $text | Should -Not -Match 'ssh-keygen'
        $text | Should -Not -Match 'winget'
    }

    It "the map dispatches openssh to Invoke-OpenSsh" {
        $global:PhportoWinActions['openssh'] | Should -Be 'Invoke-OpenSsh'
    }
}

Describe "Invoke-OpenSsh - SubAction validation" {

    It "emits [ ERROR ] and lists the options when no subaction is given" {
        $output = (Invoke-OpenSsh) 6>&1 | Out-String
        $output | Should -Match '\[ ERROR \]'
        $output | Should -Match 'status, install, enable, disable'
    }

    It "emits [ ERROR ] for an unrecognised subaction" {
        $output = (Invoke-OpenSsh -SubAction 'keygen') 6>&1 | Out-String
        $output | Should -Match '\[ ERROR \]'
    }
}

Describe "Invoke-OpenSsh - install" {

    BeforeEach {
        Mock Get-NetFirewallRule { $null }
        Mock Disable-NetFirewallRule { }
    }

    It "calls Add-WindowsCapability with the OpenSSH Server capability when it is missing" {
        Mock Get-WindowsCapability { [PSCustomObject]@{ Name = 'OpenSSH.Server~~~~0.0.1.0'; State = 'NotPresent' } }
        Mock Add-WindowsCapability { }

        $output = (Invoke-OpenSsh -SubAction 'install') 6>&1 | Out-String

        Should -Invoke Add-WindowsCapability -Times 1 -Exactly -ParameterFilter { $Online -and $Name -eq 'OpenSSH.Server~~~~0.0.1.0' }
        $output | Should -Match 'OpenSSH Server installed'
    }

    It "is idempotent: already installed does not call Add-WindowsCapability" {
        Mock Get-WindowsCapability { [PSCustomObject]@{ State = 'Installed' } }
        Mock Add-WindowsCapability { }

        $output = (Invoke-OpenSsh -SubAction 'install') 6>&1 | Out-String

        Should -Invoke Add-WindowsCapability -Times 0 -Exactly
        $output | Should -Match 'already installed'
    }

    It "disables the built-in all-profiles rule that the capability creates" {
        Mock Get-WindowsCapability { [PSCustomObject]@{ State = 'NotPresent' } }
        Mock Add-WindowsCapability { }
        Mock Get-NetFirewallRule { [PSCustomObject]@{ Name = 'OpenSSH-Server-In-TCP'; Enabled = 'True' } } -ParameterFilter { $Name -eq 'OpenSSH-Server-In-TCP' }

        (Invoke-OpenSsh -SubAction 'install') 6>&1 | Out-Null

        Should -Invoke Disable-NetFirewallRule -Times 1 -Exactly -ParameterFilter { $Name -eq 'OpenSSH-Server-In-TCP' }
    }

    It "reports [ ERROR ] when the capability install fails" {
        Mock Get-WindowsCapability { [PSCustomObject]@{ State = 'NotPresent' } }
        Mock Add-WindowsCapability { throw 'sem rede' }

        $output = (Invoke-OpenSsh -SubAction 'install') 6>&1 | Out-String
        $output | Should -Match '\[ ERROR \] Could not install OpenSSH Server'
    }
}

Describe "Invoke-OpenSsh - enable" {

    BeforeEach {
        Mock Get-Service { [PSCustomObject]@{ Name = 'sshd'; Status = 'Stopped'; StartType = 'Manual' } }
        Mock Set-Service { }
        Mock Start-Service { }
        Mock New-NetFirewallRule { }
        Mock Set-NetFirewallRule { }
        Mock Disable-NetFirewallRule { }
    }

    It "sets sshd to Automatic, starts it, and creates the rule TCP 22 inbound for Private only" {
        Mock Get-NetFirewallRule { $null }

        $output = (Invoke-OpenSsh -SubAction 'enable') 6>&1 | Out-String

        Should -Invoke Set-Service -Times 1 -Exactly -ParameterFilter { $Name -eq 'sshd' -and $StartupType -eq 'Automatic' }
        Should -Invoke Start-Service -Times 1 -Exactly -ParameterFilter { $Name -eq 'sshd' }
        Should -Invoke New-NetFirewallRule -Times 1 -Exactly -ParameterFilter {
            $Name -eq $Script:Regra -and $Direction -eq 'Inbound' -and $Action -eq 'Allow' -and
            $Protocol -eq 'TCP' -and [string]$LocalPort -eq '22' -and $Profile -eq 'Private'
        }
        $output | Should -Match 'SSH is on for the private network'
    }

    It "is idempotent: an existing rule is reset to Private TCP 22, never duplicated" {
        Mock Get-NetFirewallRule { [PSCustomObject]@{ Name = $Script:Regra; Enabled = 'True' } } -ParameterFilter { $Name -eq $Script:Regra }
        Mock Get-NetFirewallRule { $null } -ParameterFilter { $Name -ne $Script:Regra }

        (Invoke-OpenSsh -SubAction 'enable') 6>&1 | Out-Null

        Should -Invoke New-NetFirewallRule -Times 0 -Exactly
        Should -Invoke Set-NetFirewallRule -Times 1 -Exactly -ParameterFilter { $Name -eq $Script:Regra -and $Profile -eq 'Private' -and [string]$LocalPort -eq '22' }
    }

    It "without the service, reports [ ERROR ] and changes nothing" {
        Mock Get-Service { $null }
        Mock Get-NetFirewallRule { $null }

        $output = (Invoke-OpenSsh -SubAction 'enable') 6>&1 | Out-String

        $output | Should -Match 'run install first'
        Should -Invoke Set-Service -Times 0 -Exactly
        Should -Invoke New-NetFirewallRule -Times 0 -Exactly
    }
}

Describe "Invoke-OpenSsh - disable" {

    BeforeEach {
        Mock Get-Service { [PSCustomObject]@{ Name = 'sshd'; Status = 'Running'; StartType = 'Automatic' } }
        Mock Stop-Service { }
        Mock Set-Service { }
        Mock Remove-NetFirewallRule { }
    }

    It "stops sshd, sets it to Manual and removes the rule" {
        Mock Get-NetFirewallRule { [PSCustomObject]@{ Name = $Script:Regra } }

        (Invoke-OpenSsh -SubAction 'disable') 6>&1 | Out-Null

        Should -Invoke Stop-Service -Times 1 -Exactly -ParameterFilter { $Name -eq 'sshd' }
        Should -Invoke Set-Service -Times 1 -Exactly -ParameterFilter { $Name -eq 'sshd' -and $StartupType -eq 'Manual' }
        Should -Invoke Remove-NetFirewallRule -Times 1 -Exactly -ParameterFilter { $Name -eq $Script:Regra }
    }

    It "is idempotent: without the rule, nothing is removed" {
        Mock Get-NetFirewallRule { $null }

        $output = (Invoke-OpenSsh -SubAction 'disable') 6>&1 | Out-String

        Should -Invoke Remove-NetFirewallRule -Times 0 -Exactly
        $output | Should -Match 'already absent'
    }
}

Describe "Invoke-OpenSsh - status" {

    It "only reads: reports installed, the service state and the rule" {
        Mock Get-WindowsCapability { [PSCustomObject]@{ State = 'Installed' } }
        Mock Get-Service { [PSCustomObject]@{ Name = 'sshd'; Status = 'Running'; StartType = 'Automatic' } }
        Mock Get-NetFirewallRule { [PSCustomObject]@{ Name = $Script:Regra; Enabled = 'True' } } -ParameterFilter { $Name -eq $Script:Regra }
        Mock Get-NetFirewallRule { $null } -ParameterFilter { $Name -ne $Script:Regra }
        Mock Set-Service { }
        Mock New-NetFirewallRule { }

        $output = (Invoke-OpenSsh -SubAction 'status') 6>&1 | Out-String

        $output | Should -Match 'OpenSSH Server: installed'
        $output | Should -Match "Service 'sshd': Running, startup Automatic"
        $output | Should -Match 'Private profile only'
        Should -Invoke Set-Service -Times 0 -Exactly
        Should -Invoke New-NetFirewallRule -Times 0 -Exactly
    }
}
