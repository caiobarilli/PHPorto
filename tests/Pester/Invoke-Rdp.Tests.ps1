#Requires -Version 5.1
<#
.SYNOPSIS
    Pester 5+ tests for src/Win/actions/Invoke-Rdp.ps1
.DESCRIPTION
    Mock-based unit tests for each SubAction. The registry is never touched:
    reads go through a mocked Get-ItemProperty and writes through a mocked
    Set-WinUtilRegistry, so the suite runs unelevated and changes nothing.
#>

BeforeAll {
    $Script:RaizProjeto = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $Script:PastaWin    = Join-Path $Script:RaizProjeto 'src\Win'
    $Script:Acao        = Join-Path $Script:PastaWin 'actions\Invoke-Rdp.ps1'

    # Loads Write-Status, $sync, $root, lib/ (Set-WinUtilRegistry) and every action.
    . (Join-Path $Script:PastaWin 'bootstrap.ps1')

    $Script:TsControl = 'HKLM:\SYSTEM\CurrentControlSet\Control\Terminal Server'
    $Script:TsPolicy  = 'HKLM:\SOFTWARE\Policies\Microsoft\Windows NT\Terminal Services'
}

# ==============================================================
# SANITY
# ==============================================================
Describe "Invoke-Rdp - Sanity" {

    It "Invoke-Rdp.ps1 exists in actions/" {
        Test-Path $Script:Acao | Should -BeTrue
    }

    It "Invoke-Rdp.ps1 has no syntax errors" {
        $errors = $null
        [System.Management.Automation.Language.Parser]::ParseFile($Script:Acao, [ref]$null, [ref]$errors) | Out-Null
        $errors.Count | Should -Be 0
    }

    It "Invoke-Rdp function is available after dot-sourcing" {
        Get-Command -Name 'Invoke-Rdp' -ErrorAction SilentlyContinue | Should -Not -BeNullOrEmpty
    }

    It "does not prompt and does not auto-elevate" {
        $text = Get-Content -Path $Script:Acao -Raw
        $text | Should -Not -Match 'Read-Host'
        $text | Should -Not -Match 'RunAs'
    }
}

# ==============================================================
# SUBACTION — missing / unknown
# ==============================================================
Describe "Invoke-Rdp - SubAction validation" {

    It "emits [ ERROR ] and lists the options when no subaction is given" {
        $output = (Invoke-Rdp) 6>&1 | Out-String
        $output | Should -Match '\[ ERROR \]'
        $output | Should -Match 'status, on, off, h264-on, h264-off'
    }

    It "emits [ ERROR ] and lists the options for an unrecognised subaction" {
        $output = (Invoke-Rdp -SubAction 'reboot') 6>&1 | Out-String
        $output | Should -Match '\[ ERROR \]'
        $output | Should -Match 'status, on, off, h264-on, h264-off'
    }
}

# ==============================================================
# SUBACTION — status (read only)
# ==============================================================
Describe "Invoke-Rdp - SubAction status" {

    It "reports RDP on, NLA required, default port, H.264 on and UDP transport" {
        Mock Get-ItemProperty {
            [PSCustomObject]@{
                fDenyTSConnections  = 0
                UserAuthentication  = 1
                PortNumber          = 3389
                AVC444ModePreferred = 1
                SelectTransport     = 2
            }
        }

        $output = (Invoke-Rdp -SubAction 'status') 6>&1 | Out-String

        $output | Should -Match 'Remote Desktop: ON'
        $output | Should -Match 'Network Level Authentication: required'
        $output | Should -Match 'Listening port: default \(3389\)'
        $output | Should -Match 'H.264/AVC 444 video: on'
        $output | Should -Match 'UDP transport: UDP or TCP \(SelectTransport=2\)'
    }

    It "reports RDP off and a non-default port" {
        Mock Get-ItemProperty {
            [PSCustomObject]@{
                fDenyTSConnections  = 1
                UserAuthentication  = 0
                PortNumber          = 3390
                AVC444ModePreferred = 0
                SelectTransport     = 1
            }
        }

        $output = (Invoke-Rdp -SubAction 'status') 6>&1 | Out-String

        $output | Should -Match 'Remote Desktop: OFF'
        $output | Should -Match 'Network Level Authentication: NOT required'
        $output | Should -Match 'Listening port: 3390'
        $output | Should -Match 'H.264/AVC 444 video: off'
        $output | Should -Match 'UDP transport: TCP only'
    }

    It "changes nothing on disk" {
        Mock Get-ItemProperty  { [PSCustomObject]@{ fDenyTSConnections = 0 } }
        Mock Set-WinUtilRegistry { }
        Mock Enable-NetFirewallRule { }

        Invoke-Rdp -SubAction 'status' 6>&1 | Out-Null

        Should -Invoke -CommandName Set-WinUtilRegistry    -Times 0
        Should -Invoke -CommandName Enable-NetFirewallRule -Times 0
    }
}

# ==============================================================
# SUBACTION — on / off (Applied, takes effect immediately)
# ==============================================================
Describe "Invoke-Rdp - on and off" {

    It "on clears fDenyTSConnections and enables the Remote Desktop firewall group" {
        Mock Set-WinUtilRegistry    { }
        Mock Enable-NetFirewallRule { }

        $output = (Invoke-Rdp -SubAction 'on') 6>&1 | Out-String

        Should -Invoke -CommandName Set-WinUtilRegistry -Times 1 -ParameterFilter {
            $Name -eq 'fDenyTSConnections' -and $Path -eq $Script:TsControl -and $Value -eq '0'
        }
        Should -Invoke -CommandName Enable-NetFirewallRule -Times 1 -ParameterFilter {
            $DisplayGroup -eq 'Remote Desktop'
        }
        $output | Should -Match 'takes effect immediately'
    }

    It "off sets fDenyTSConnections to 1 and leaves the firewall alone" {
        Mock Set-WinUtilRegistry    { }
        Mock Enable-NetFirewallRule { }

        $output = (Invoke-Rdp -SubAction 'off') 6>&1 | Out-String

        Should -Invoke -CommandName Set-WinUtilRegistry -Times 1 -ParameterFilter {
            $Name -eq 'fDenyTSConnections' -and $Value -eq '1'
        }
        Should -Invoke -CommandName Enable-NetFirewallRule -Times 0
        $output | Should -Match 'takes effect immediately'
    }
}

# ==============================================================
# SUBACTION — h264-on / h264-off (PendingReboot)
# ==============================================================
Describe "Invoke-Rdp - H.264/UDP" {

    It "h264-on prefers AVC444 and sets SelectTransport to both, warning about the restart" {
        Mock Set-WinUtilRegistry { }

        $output = (Invoke-Rdp -SubAction 'h264-on') 6>&1 | Out-String

        Should -Invoke -CommandName Set-WinUtilRegistry -Times 1 -ParameterFilter {
            $Name -eq 'AVC444ModePreferred' -and $Path -eq $Script:TsPolicy -and $Value -eq '1'
        }
        Should -Invoke -CommandName Set-WinUtilRegistry -Times 1 -ParameterFilter {
            $Name -eq 'SelectTransport' -and $Path -eq $Script:TsPolicy -and $Value -eq '0'
        }
        $output | Should -Match 'takes effect only after Windows restarts'
    }

    It "h264-off removes both policy values with <RemoveEntry>" {
        Mock Set-WinUtilRegistry { }

        (Invoke-Rdp -SubAction 'h264-off') 6>&1 | Out-Null

        Should -Invoke -CommandName Set-WinUtilRegistry -Times 1 -ParameterFilter {
            $Name -eq 'AVC444ModePreferred' -and $Value -eq '<RemoveEntry>'
        }
        Should -Invoke -CommandName Set-WinUtilRegistry -Times 1 -ParameterFilter {
            $Name -eq 'SelectTransport' -and $Value -eq '<RemoveEntry>'
        }
    }
}
