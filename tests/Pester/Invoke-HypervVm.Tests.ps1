#Requires -Version 5.1

BeforeAll {
    $Script:RaizProjeto = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $Script:PastaWin    = Join-Path $Script:RaizProjeto 'src\Win'
    $Script:Acao        = Join-Path $Script:PastaWin 'actions\Invoke-HypervVm.ps1'

    $stubs = @{
        'Get-VM'     = { [CmdletBinding()] param([guid[]]$Id, [string[]]$Name) }
        'Start-VM'   = { [CmdletBinding(SupportsShouldProcess)] param($VM, [switch]$Passthru) }
        'Resume-VM'  = { [CmdletBinding(SupportsShouldProcess)] param($VM, [switch]$Passthru) }
        'Stop-VM'    = { [CmdletBinding(SupportsShouldProcess)] param($VM, [switch]$Force, [switch]$TurnOff, [switch]$Save) }
        'Restart-VM' = { [CmdletBinding(SupportsShouldProcess)] param($VM, [string]$Type, [switch]$Force) }
    }
    foreach ($nome in $stubs.Keys) {
        if (-not (Get-Command -Name $nome -ErrorAction SilentlyContinue)) {
            Set-Item -Path ('function:global:' + $nome) -Value $stubs[$nome]
        }
    }

    . (Join-Path $Script:PastaWin 'bootstrap.ps1')

    $Script:Id = '6c1e2a4b-0d3f-4e5a-9b8c-7d6e5f4a3b2c'

    function New-FakeVM([string]$State, [string]$Name = 'Ubuntu') {
        [PSCustomObject]@{
            VMId  = [guid]$Script:Id
            Name  = $Name
            State = $State
        }
    }

    function Set-FakeHost([string]$State) {
        $Script:Estado = $State
        Mock Get-VM { New-FakeVM $Script:Estado }
        Mock Start-VM -RemoveParameterType VM { $Script:Estado = 'Running' }
        Mock Resume-VM -RemoveParameterType VM { $Script:Estado = 'Running' }
        Mock Stop-VM -RemoveParameterType VM { $Script:Estado = 'Off' }
        Mock Restart-VM -RemoveParameterType VM { $Script:Estado = 'Running' }
    }

    function Invoke-Vm([string]$Sub, [string]$VmId = $Script:Id) {
        $global:LASTEXITCODE = $null
        $json = Invoke-HypervVm -SubAction $Sub -VmId $VmId
        $obj  = $json | ConvertFrom-Json
        $obj | Add-Member -NotePropertyName exitCode -NotePropertyValue $global:LASTEXITCODE
        return $obj
    }
}

Describe "Invoke-HypervVm - Sanity" {

    It "Invoke-HypervVm.ps1 exists in actions/" {
        Test-Path $Script:Acao | Should -BeTrue
    }

    It "Invoke-HypervVm.ps1 has no syntax errors" {
        $errors = $null
        [System.Management.Automation.Language.Parser]::ParseFile($Script:Acao, [ref]$null, [ref]$errors) | Out-Null
        $errors.Count | Should -Be 0
    }

    It "is reachable through the dispatcher map" {
        $global:PhportoWinActions['hyperv-vm'] | Should -Be 'Invoke-HypervVm'
        Get-Command -Name 'Invoke-HypervVm' -CommandType Function -ErrorAction SilentlyContinue | Should -Not -BeNullOrEmpty
    }

    It "does not prompt, does not auto-elevate, never pulls the plug" {
        $text = Get-Content -Path $Script:Acao -Raw
        $text | Should -Not -Match 'Read-Host'
        $text | Should -Not -Match 'RunAs'
        $text | Should -Not -Match '-TurnOff'
        $text | Should -Not -Match 'New-VM|Remove-VM|Set-VM|Suspend-VM|Save-VM|Checkpoint-VM'
    }

    It "finds the VM by id, never by name" {
        $text = Get-Content -Path $Script:Acao -Raw
        $text | Should -Not -Match '(Get|Start|Stop|Restart|Resume)-VM\b[^\r\n]*-(VM)?Name\b'
        $text | Should -Match 'Get-VM -Id \(\[guid\]\$VmId\)'
    }
}

Describe "Invoke-HypervVm - start" {

    It "starts a VM that is <_>" -ForEach @('Off', 'Saved', 'FastSaved', 'Hibernated') {
        Set-FakeHost $_

        $r = Invoke-Vm 'start'

        $r.ok       | Should -BeTrue
        $r.exitCode | Should -Be 0
        $r.antes    | Should -Be $_
        $r.depois   | Should -Be 'Running'
        $r.metodo   | Should -Be 'Start-VM'
        $r.name     | Should -Be 'Ubuntu'
        $r.vmId     | Should -Be $Script:Id
        Should -Invoke Start-VM -Times 1 -Exactly
        Should -Invoke Resume-VM -Times 0
        Should -Invoke Get-VM -ParameterFilter { $Id -eq [guid]$Script:Id -and $null -eq $Name }
    }

    It "resumes a paused VM instead of starting it" {
        Set-FakeHost 'Paused'

        $r = Invoke-Vm 'start'

        $r.ok     | Should -BeTrue
        $r.metodo | Should -Be 'Resume-VM'
        Should -Invoke Resume-VM -Times 1 -Exactly
        Should -Invoke Start-VM -Times 0
    }

    It "refuses to start a VM that is already running" {
        Set-FakeHost 'Running'

        $r = Invoke-Vm 'start'

        $r.ok       | Should -BeFalse
        $r.exitCode | Should -Be 1
        $r.motivo   | Should -Be 'ja-ligada'
        Should -Invoke Start-VM -Times 0
        Should -Invoke Resume-VM -Times 0
    }
}

Describe "Invoke-HypervVm - stop" {

    It "stops a running VM gracefully: -Force, never -TurnOff, never asks" {
        Set-FakeHost 'Running'

        $r = Invoke-Vm 'stop'

        $r.ok     | Should -BeTrue
        $r.metodo | Should -Be 'Stop-VM'
        $r.depois | Should -Be 'Off'
        Should -Invoke Stop-VM -Times 1 -Exactly -ParameterFilter { $Force -and -not $TurnOff -and -not $Save -and $Confirm -eq $false }
    }

    It "refuses to stop a VM that is <Estado> (<Motivo>)" -ForEach @(
        @{ Estado = 'Off';        Motivo = 'ja-desligada' }
        @{ Estado = 'Saved';      Motivo = 'estado-salvo' }
        @{ Estado = 'FastSaved';  Motivo = 'estado-salvo' }
        @{ Estado = 'Hibernated'; Motivo = 'estado-salvo' }
        @{ Estado = 'Paused';     Motivo = 'pausada' }
    ) {
        Set-FakeHost $Estado

        $r = Invoke-Vm 'stop'

        $r.ok       | Should -BeFalse
        $r.exitCode | Should -Be 1
        $r.motivo   | Should -Be $Motivo
        Should -Invoke Stop-VM -Times 0
    }
}

Describe "Invoke-HypervVm - restart" {

    It "reboots through the guest when Restart-VM has -Type" {
        Set-FakeHost 'Running'
        Mock Test-PhportoRestartVmTemTipo { $true }

        $r = Invoke-Vm 'restart'

        $r.ok     | Should -BeTrue
        $r.metodo | Should -Be 'Restart-VM -Type Reboot'
        Should -Invoke Restart-VM -Times 1 -Exactly -ParameterFilter { $Type -eq 'Reboot' -and $Force }
    }

    It "falls back to a reset when Restart-VM has no -Type, and says so" {
        Set-FakeHost 'Running'
        Mock Test-PhportoRestartVmTemTipo { $false }

        $r = Invoke-Vm 'restart'

        $r.ok       | Should -BeTrue
        $r.metodo   | Should -Be 'Restart-VM -Force'
        $r.mensagem | Should -Match 'reset'
        Should -Invoke Restart-VM -Times 1 -Exactly -ParameterFilter { -not $Type -and $Force }
    }

    It "the -Type probe reads the real parameter list" {
        Test-PhportoRestartVmTemTipo | Should -BeOfType [bool]
    }

    It "refuses to restart a VM that is <_>" -ForEach @('Off', 'Saved', 'FastSaved', 'Hibernated', 'Paused') {
        Set-FakeHost $_

        $r = Invoke-Vm 'restart'

        $r.ok     | Should -BeFalse
        $r.motivo | Should -Be 'nao-ligada'
        Should -Invoke Restart-VM -Times 0
    }
}

Describe "Invoke-HypervVm - refusals" {

    It "refuses <Sub> while the VM is <Estado>, touching nothing" -ForEach @(
        foreach ($e in 'Starting', 'Stopping', 'Saving', 'Pausing', 'Resuming', 'Reset', 'FastSaving', 'Other') {
            foreach ($s in 'start', 'stop', 'restart') { @{ Estado = $e; Sub = $s } }
        }
    ) {
        Set-FakeHost $Estado

        $r = Invoke-Vm $Sub

        $r.ok       | Should -BeFalse
        $r.exitCode | Should -Be 1
        $r.motivo   | Should -Be 'em-transicao'
        $r.mensagem | Should -Match $Estado
        Should -Invoke Start-VM -Times 0
        Should -Invoke Resume-VM -Times 0
        Should -Invoke Stop-VM -Times 0
        Should -Invoke Restart-VM -Times 0
    }

    It "refuses an id that is not a GUID, without asking Hyper-V: <_>" -ForEach @(
        '*'
        'Ubuntu'
        ''
        '6c1e2a4b0d3f4e5a9b8c7d6e5f4a3b2c'
        '{6c1e2a4b-0d3f-4e5a-9b8c-7d6e5f4a3b2c}'
        '6c1e2a4b-0d3f-4e5a-9b8c-7d6e5f4a3b2g'
    ) {
        Set-FakeHost 'Running'

        $r = Invoke-Vm 'stop' $_

        $r.ok     | Should -BeFalse
        $r.motivo | Should -Be 'id-invalido'
        Should -Invoke Get-VM -Times 0
        Should -Invoke Stop-VM -Times 0
    }

    It "refuses a subaction outside start, stop and restart: <_>" -ForEach @('', 'reset', 'turnoff', 'START') {
        Set-FakeHost 'Off'

        $r = Invoke-Vm $_

        $r.ok     | Should -BeFalse
        $r.motivo | Should -Be 'subacao-invalida'
        Should -Invoke Get-VM -Times 0
        Should -Invoke Start-VM -Times 0
    }

    It "an id that matches no VM is a clear refusal" {
        Set-FakeHost 'Off'
        Mock Get-VM { throw 'Hyper-V was unable to find a virtual machine with id' }

        $r = Invoke-Vm 'start'

        $r.ok     | Should -BeFalse
        $r.motivo | Should -Be 'vm-nao-encontrada'
        $r.erro   | Should -Match 'unable to find'
        Should -Invoke Start-VM -Times 0
    }

    It "an empty answer from Get-VM is the same refusal" {
        Set-FakeHost 'Off'
        Mock Get-VM { }

        (Invoke-Vm 'start').motivo | Should -Be 'vm-nao-encontrada'
    }

    It "a cmdlet that fails is reported, with the state read after" {
        Set-FakeHost 'Running'
        Mock Stop-VM -RemoveParameterType VM { throw 'The virtual machine does not have the shutdown integration service' }

        $r = Invoke-Vm 'stop'

        $r.ok       | Should -BeFalse
        $r.exitCode | Should -Be 1
        $r.motivo   | Should -Be 'falhou'
        $r.mensagem | Should -Match 'shutdown integration service'
        $r.depois   | Should -Be 'Running'
    }

    It "the output is one JSON document" {
        Set-FakeHost 'Off'

        $out = @(Invoke-HypervVm -SubAction 'start' -VmId $Script:Id)

        $out.Count | Should -Be 1
        { $out[0] | ConvertFrom-Json } | Should -Not -Throw
    }
}
