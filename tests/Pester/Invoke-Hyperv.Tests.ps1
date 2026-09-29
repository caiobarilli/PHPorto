#Requires -Version 5.1
<#
.SYNOPSIS
    Pester 5+ tests for src/Win/actions/Invoke-Hyperv.ps1
.DESCRIPTION
    Mock-based unit tests for the read-only VM listing. Get-VM and
    Get-VMNetworkAdapter are mocked, so the suite runs unelevated and touches no
    virtual machine. The action emits a single JSON document; each test parses
    it and checks the shape the /hyperv screen depends on.
#>

BeforeAll {
    $Script:RaizProjeto = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $Script:PastaWin    = Join-Path $Script:RaizProjeto 'src\Win'
    $Script:Acao        = Join-Path $Script:PastaWin 'actions\Invoke-Hyperv.ps1'

    # Loads Write-Status, $sync, $root and every action.
    . (Join-Path $Script:PastaWin 'bootstrap.ps1')

    function New-FakeVM([string]$Name, [string]$State, [long]$Memory, [int]$UptimeSeconds) {
        [PSCustomObject]@{
            Name           = $Name
            State          = $State
            MemoryAssigned = $Memory
            Uptime         = [TimeSpan]::FromSeconds($UptimeSeconds)
        }
    }
}

# ==============================================================
# SANITY
# ==============================================================
Describe "Invoke-Hyperv - Sanity" {

    It "Invoke-Hyperv.ps1 exists in actions/" {
        Test-Path $Script:Acao | Should -BeTrue
    }

    It "Invoke-Hyperv.ps1 has no syntax errors" {
        $errors = $null
        [System.Management.Automation.Language.Parser]::ParseFile($Script:Acao, [ref]$null, [ref]$errors) | Out-Null
        $errors.Count | Should -Be 0
    }

    It "Invoke-Hyperv function is available after dot-sourcing" {
        Get-Command -Name 'Invoke-Hyperv' -CommandType Function -ErrorAction SilentlyContinue | Should -Not -BeNullOrEmpty
    }

    It "does not prompt and does not auto-elevate" {
        $text = Get-Content -Path $Script:Acao -Raw
        $text | Should -Not -Match 'Read-Host'
        $text | Should -Not -Match 'RunAs'
    }

    It "is read only: no VM-mutating cmdlet is present" {
        $text = Get-Content -Path $Script:Acao -Raw
        $text | Should -Not -Match 'Start-VM|Stop-VM|New-VM|Remove-VM|Set-VM|Suspend-VM|Restart-VM|Checkpoint-VM'
    }
}

# ==============================================================
# LISTING — running and off VMs
# ==============================================================
Describe "Invoke-Hyperv - listing" {

    It "reports host on, both VMs, and the running one's fields" {
        Mock Get-VM {
            @(
                (New-FakeVM 'Ubuntu' 'Running' 2147483648 3661),
                (New-FakeVM 'Win11'  'Off'     0          0)
            )
        }
        Mock Get-VMNetworkAdapter { [PSCustomObject]@{ IPAddresses = @('192.168.1.5', 'fe80::1') } }

        $obj = Invoke-Hyperv | ConvertFrom-Json

        $obj.hyperv | Should -BeTrue
        @($obj.vms).Count | Should -Be 2

        $rodando = $obj.vms | Where-Object { $_.name -eq 'Ubuntu' }
        $rodando.state         | Should -Be 'Running'
        $rodando.running       | Should -BeTrue
        $rodando.memoryBytes   | Should -Be 2147483648
        $rodando.uptimeSeconds | Should -Be 3661
        @($rodando.ip)         | Should -Be @('192.168.1.5', 'fe80::1')
    }

    It "the off VM has no IP read and no uptime" {
        Mock Get-VM { @( (New-FakeVM 'Win11' 'Off' 0 0) ) }
        Mock Get-VMNetworkAdapter { throw 'should not be called for an off VM' }

        $obj = Invoke-Hyperv | ConvertFrom-Json
        $off = $obj.vms | Where-Object { $_.name -eq 'Win11' }

        $off.running       | Should -BeFalse
        $off.uptimeSeconds | Should -Be 0
        @($off.ip).Count   | Should -Be 0
        Should -Invoke -CommandName Get-VMNetworkAdapter -Times 0
    }

    It "a running VM whose IP cannot be read reports an empty list, not a guess" {
        Mock Get-VM { @( (New-FakeVM 'Ubuntu' 'Running' 1073741824 120) ) }
        Mock Get-VMNetworkAdapter { throw 'integration services not reporting' }

        $obj = Invoke-Hyperv | ConvertFrom-Json
        $vm  = $obj.vms | Where-Object { $_.name -eq 'Ubuntu' }

        $vm.running     | Should -BeTrue
        @($vm.ip).Count | Should -Be 0
    }

    It "an adapter with no addresses yields an empty IP list" {
        Mock Get-VM { @( (New-FakeVM 'Ubuntu' 'Running' 1073741824 120) ) }
        Mock Get-VMNetworkAdapter { [PSCustomObject]@{ IPAddresses = @() } }

        $obj = Invoke-Hyperv | ConvertFrom-Json
        $vm  = $obj.vms | Where-Object { $_.name -eq 'Ubuntu' }

        @($vm.ip).Count | Should -Be 0
    }
}

# ==============================================================
# EMPTY host
# ==============================================================
Describe "Invoke-Hyperv - no VMs" {

    It "reports host on with an empty VM list" {
        Mock Get-VM { @() }

        $obj = Invoke-Hyperv | ConvertFrom-Json

        $obj.hyperv     | Should -BeTrue
        @($obj.vms).Count | Should -Be 0
    }
}

# ==============================================================
# Hyper-V off
# ==============================================================
Describe "Invoke-Hyperv - host off" {

    It "reports host off when Get-VM throws" {
        Mock Get-VM { throw 'Hyper-V is not enabled' }

        $obj = Invoke-Hyperv | ConvertFrom-Json

        $obj.hyperv       | Should -BeFalse
        @($obj.vms).Count | Should -Be 0
    }
}
