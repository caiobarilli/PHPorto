#Requires -Version 5.1
<#
.SYNOPSIS
    Pester 5+ tests for src/Win/actions/Invoke-Gdid.ps1
.DESCRIPTION
    Mock-based unit tests for each SubAction. The hosts file is exercised for real
    against a temporary %WINDIR% tree, so encoding preservation is covered too.

    Migrated from winutil-cli. Two things changed, and only these two: the
    environment now comes from src/Win/bootstrap.ps1 instead of being rebuilt by
    hand from the old entry point via AST, and the transcript to C:\log\ is
    gone — the gate reads the Pester exit code, and C:\log\ belongs to the audit
    action.
#>

BeforeAll {
    $Script:RaizProjeto = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $Script:PastaWin    = Join-Path $Script:RaizProjeto 'src\Win'

    # Loads Write-Status, $sync, $root, lib/ (Set-WinUtilService,
    # Set-WinUtilRegistry) and every action.
    . (Join-Path $Script:PastaWin 'bootstrap.ps1')

    $Script:StateFile = 'C:\WinUtil\gdid-state.json'
    $Script:MarkStart = '#GDID-BLOCKER START'
    $Script:MarkEnd   = '#GDID-BLOCKER END'
    $Script:AnsiEnc   = [System.Text.Encoding]::GetEncoding(
        [System.Globalization.CultureInfo]::CurrentCulture.TextInfo.ANSICodePage
    )
    $Script:Utf8Enc   = [System.Text.UTF8Encoding]::new($false)

    # Redirects %WINDIR% and %LOCALAPPDATA% to a throwaway tree so the real hosts
    # file and the real profile are never touched by the suite.
    function Use-FakeEnvironment {
        param([string[]]$HostsLines, [System.Text.Encoding]$Encoding)

        $Script:FakeRoot = Join-Path ([System.IO.Path]::GetTempPath()) ("gdid-test-" + [guid]::NewGuid().ToString('N'))
        $etc = Join-Path $Script:FakeRoot 'System32\drivers\etc'
        New-Item -ItemType Directory -Path $etc -Force | Out-Null
        [System.IO.File]::WriteAllLines((Join-Path $etc 'hosts'), [string[]]@($HostsLines), $Encoding)

        $local = Join-Path $Script:FakeRoot 'LocalAppData'
        New-Item -ItemType Directory -Path $local -Force | Out-Null

        $Script:OldWinDir = $env:WINDIR
        $Script:OldLocal  = $env:LOCALAPPDATA
        $env:WINDIR       = $Script:FakeRoot
        $env:LOCALAPPDATA = $local
    }

    function Get-FakeHostsLines {
        param([System.Text.Encoding]$Encoding)
        $file = Join-Path $Script:FakeRoot 'System32\drivers\etc\hosts'
        return @($Encoding.GetString([System.IO.File]::ReadAllBytes($file)) -split "`r`n|`n|`r")
    }

    # Called from an AfterEach in every Describe that redirects the environment.
    function Reset-FakeEnvironment {
        if ($Script:OldWinDir) { $env:WINDIR = $Script:OldWinDir; $Script:OldWinDir = $null }
        if ($Script:OldLocal)  { $env:LOCALAPPDATA = $Script:OldLocal; $Script:OldLocal = $null }
        if ($Script:FakeRoot -and (Test-Path $Script:FakeRoot)) {
            Remove-Item -Path $Script:FakeRoot -Recurse -Force -ErrorAction SilentlyContinue
        }
        $Script:FakeRoot = $null
    }
}


# ==============================================================
# SANITY
# ==============================================================
Describe "Invoke-Gdid - Sanity" {

    It "Invoke-Gdid.ps1 exists in scripts/" {
        Test-Path (Join-Path $Script:PastaWin 'actions\Invoke-Gdid.ps1') | Should -BeTrue
    }

    It "Invoke-Gdid.ps1 has no syntax errors" {
        $errors = $null
        [System.Management.Automation.Language.Parser]::ParseFile(
            (Join-Path $Script:PastaWin 'actions\Invoke-Gdid.ps1'),
            [ref]$null, [ref]$errors
        ) | Out-Null
        $errors.Count | Should -Be 0
    }

    It "Invoke-Gdid function is available after dot-sourcing" {
        Get-Command -Name 'Invoke-Gdid' -ErrorAction SilentlyContinue | Should -Not -BeNullOrEmpty
    }

    It "does not auto-elevate: no Start-Process -Verb RunAs" {
        $text = Get-Content -Path (Join-Path $Script:PastaWin 'actions\Invoke-Gdid.ps1') -Raw
        $text | Should -Not -Match 'RunAs'
    }

    It "does not prompt: no Read-Host in the action body" {
        $text = Get-Content -Path (Join-Path $Script:PastaWin 'actions\Invoke-Gdid.ps1') -Raw
        $text | Should -Not -Match 'Read-Host'
    }
}

# ==============================================================
# SUBACTION — missing / unknown
# ==============================================================
Describe "Invoke-Gdid - SubAction validation" {

    It "emits [ ERROR ] and lists the options when no subaction is given" {
        $output = (Invoke-Gdid) 6>&1 | Out-String
        $output | Should -Match '\[ ERROR \]'
        $output | Should -Match 'status, disable, enable'
    }

    It "emits [ ERROR ] and lists the options for an unrecognised subaction" {
        $output = (Invoke-Gdid -SubAction 'bogus') 6>&1 | Out-String
        $output | Should -Match '\[ ERROR \]'
        $output | Should -Match 'status, disable, enable'
    }
}

# ==============================================================
# SUBACTION — status
# ==============================================================
Describe "Invoke-Gdid - SubAction status" {

    AfterEach { Reset-FakeEnvironment }

    It "reports services, policy values, hosts block and cache when everything is in place" {
        Use-FakeEnvironment -HostsLines @(
            '127.0.0.1 localhost'
            $Script:MarkStart
            '0.0.0.0 activity.windows.com'
            '0.0.0.0 client.wns.windows.com'
            $Script:MarkEnd
        ) -Encoding $Script:Utf8Enc
        New-Item -ItemType Directory -Path (Join-Path $env:LOCALAPPDATA 'ConnectedDevicesPlatform') -Force | Out-Null

        Mock Get-Service {
            [PSCustomObject]@{ Name = 'CDPSvc'; Status = 'Stopped'; StartType = 'Disabled' }
        } -ParameterFilter { $Name -eq 'CDPSvc' }
        Mock Get-Service {
            @([PSCustomObject]@{ Name = 'CDPUserSvc'; Status = 'Stopped'; StartType = 'Disabled' },
              [PSCustomObject]@{ Name = 'CDPUserSvc_4a2f1'; Status = 'Stopped'; StartType = 'Disabled' })
        }
        Mock Get-ItemProperty { [PSCustomObject]@{ EnableActivityFeed = 0; PublishUserActivities = 0; UploadUserActivities = 0 } }

        $output = (Invoke-Gdid -SubAction 'status') 6>&1 | Out-String

        $output | Should -Match 'CDPSvc : StartType=Disabled'
        $output | Should -Match 'CDPUserSvc : StartType=Disabled'
        $output | Should -Match 'CDPUserSvc_4a2f1 : StartType=Disabled'
        $output | Should -Match 'Policy EnableActivityFeed : 0'
        $output | Should -Match 'hosts block : present \(2 entry/entries\)'
        $output | Should -Match 'CDP cache : present'
    }

    It "reports the untouched state when nothing has been disabled" {
        Use-FakeEnvironment -HostsLines @('127.0.0.1 localhost') -Encoding $Script:Utf8Enc

        Mock Get-Service {
            [PSCustomObject]@{ Name = 'CDPSvc'; Status = 'Running'; StartType = 'Automatic' }
        } -ParameterFilter { $Name -eq 'CDPSvc' }
        Mock Get-Service { @() }
        Mock Get-ItemProperty { $null }

        $output = (Invoke-Gdid -SubAction 'status') 6>&1 | Out-String

        $output | Should -Match 'CDPSvc : StartType=Automatic / Status=Running'
        $output | Should -Match 'CDPUserSvc\* : no instance found'
        $output | Should -Match 'Policy PublishUserActivities : not set'
        $output | Should -Match 'hosts block : absent'
        $output | Should -Match 'CDP cache : absent'
    }

    It "changes nothing on disk" {
        Use-FakeEnvironment -HostsLines @('127.0.0.1 localhost') -Encoding $Script:Utf8Enc

        Mock Get-Service { $null }
        Mock Get-ItemProperty { $null }
        Mock Set-WinUtilService { }
        Mock Set-WinUtilRegistry { }
        Mock Stop-Service { }
        Mock Set-Content { }
        Mock Remove-Item { }

        Invoke-Gdid -SubAction 'status' 6>&1 | Out-Null

        Should -Invoke -CommandName Set-WinUtilService  -Times 0
        Should -Invoke -CommandName Set-WinUtilRegistry -Times 0
        Should -Invoke -CommandName Set-Content         -Times 0
        (Get-FakeHostsLines -Encoding $Script:Utf8Enc) | Should -Contain '127.0.0.1 localhost'
    }
}

# ==============================================================
# SUBACTION — disable
# ==============================================================
Describe "Invoke-Gdid - SubAction disable" {

    AfterEach { Reset-FakeEnvironment }

    BeforeEach {
        Use-FakeEnvironment -HostsLines @(
            '# Copyright (c) 1993-2009 Microsoft Corp.'
            '127.0.0.1 localhost'
        ) -Encoding $Script:Utf8Enc
    }

    It "disables both services, zeroes the policy and blocks the domains" {
        Mock Get-Service {
            [PSCustomObject]@{ Name = 'CDPSvc'; Status = 'Running'; StartType = 'Automatic' }
        } -ParameterFilter { $Name -eq 'CDPSvc' }
        Mock Get-Service {
            [PSCustomObject]@{ Name = 'CDPUserSvc'; Status = 'Running'; StartType = 'Automatic' }
        } -ParameterFilter { $Name -eq 'CDPUserSvc' }
        Mock Get-Service {
            @([PSCustomObject]@{ Name = 'CDPUserSvc_4a2f1'; Status = 'Running'; StartType = 'Automatic' })
        }
        Mock Set-WinUtilService  { }
        Mock Set-WinUtilRegistry { }
        Mock Stop-Service        { }
        Mock New-Item            { }
        Mock Copy-Item           { }
        Mock Set-Content         { }
        Mock Remove-Item         { }
        Mock ipconfig            { }

        $output = (Invoke-Gdid -SubAction 'disable') 6>&1 | Out-String

        Should -Invoke -CommandName Set-WinUtilService -Times 1 `
            -ParameterFilter { $Name -eq 'CDPSvc' -and $StartupType -eq 'Disabled' }
        Should -Invoke -CommandName Set-WinUtilService -Times 1 `
            -ParameterFilter { $Name -eq 'CDPUserSvc' -and $StartupType -eq 'Disabled' }
        # CDPUserSvc template + CDPUserSvc_4a2f1 instance + CDPSvc
        Should -Invoke -CommandName Stop-Service -Times 3
        Should -Invoke -CommandName Set-WinUtilRegistry -Times 3 `
            -ParameterFilter { $Path -eq 'HKLM:\SOFTWARE\Policies\Microsoft\Windows\System' -and $Value -eq '0' }
        Should -Invoke -CommandName ipconfig -Times 1

        $output | Should -Match '\[ OK \].*GDID pipeline disabled'

        $lines = Get-FakeHostsLines -Encoding $Script:Utf8Enc
        $lines | Should -Contain $Script:MarkStart
        $lines | Should -Contain $Script:MarkEnd
        $lines | Should -Contain '0.0.0.0 activity.windows.com'
        $lines | Should -Contain '0.0.0.0 client.wns.windows.com'
        $lines | Should -Contain '127.0.0.1 localhost'
    }

    It "warns in plain text about the notification side effect, without prompting" {
        Mock Get-Service          { $null }
        Mock Test-Path            { $false } -ParameterFilter { $Path -eq $Script:StateFile }
        Mock Set-WinUtilService   { }
        Mock Set-WinUtilRegistry  { }
        Mock Stop-Service         { }
        Mock New-Item             { }
        Mock Copy-Item            { }
        Mock Set-Content          { }
        Mock Remove-Item          { }
        Mock ipconfig             { }

        $output = (Invoke-Gdid -SubAction 'disable') 6>&1 | Out-String
        $output | Should -Match '\[ WARNING \].*push notifications'
    }

    It "saves the original startup types to C:\WinUtil\gdid-state.json" {
        Mock Get-Service {
            [PSCustomObject]@{ Name = 'CDPSvc'; Status = 'Running'; StartType = 'Automatic' }
        } -ParameterFilter { $Name -eq 'CDPSvc' }
        Mock Get-Service {
            [PSCustomObject]@{ Name = 'CDPUserSvc'; Status = 'Running'; StartType = 'Manual' }
        } -ParameterFilter { $Name -eq 'CDPUserSvc' }
        Mock Get-Service          { @() }
        Mock Test-Path            { $false } -ParameterFilter { $Path -eq $Script:StateFile }
        Mock Set-WinUtilService   { }
        Mock Set-WinUtilRegistry  { }
        Mock Stop-Service         { }
        Mock New-Item             { }
        Mock Copy-Item            { }
        Mock Remove-Item          { }
        Mock ipconfig             { }
        $Script:CapturedState = $null
        Mock Set-Content { $Script:CapturedState = ($Value -join '') } `
            -ParameterFilter { $Path -eq $Script:StateFile }

        Invoke-Gdid -SubAction 'disable' 6>&1 | Out-Null

        Should -Invoke -CommandName Set-Content -Times 1 -ParameterFilter { $Path -eq $Script:StateFile }
        $Script:CapturedState | Should -Match '"CDPSvc"\s*:\s*"Automatic"'
        $Script:CapturedState | Should -Match '"CDPUserSvc"\s*:\s*"Manual"'
    }

    It "is idempotent: running twice leaves a single hosts block" {
        Mock Get-Service          { $null }
        Mock Test-Path            { $false } -ParameterFilter { $Path -eq $Script:StateFile }
        Mock Set-WinUtilService   { }
        Mock Set-WinUtilRegistry  { }
        Mock Stop-Service         { }
        Mock New-Item             { }
        Mock Copy-Item            { }
        Mock Set-Content          { }
        Mock Remove-Item          { }
        Mock ipconfig             { }

        Invoke-Gdid -SubAction 'disable' 6>&1 | Out-Null
        Invoke-Gdid -SubAction 'disable' 6>&1 | Out-Null

        $lines = Get-FakeHostsLines -Encoding $Script:Utf8Enc
        @($lines | Where-Object { $_ -eq $Script:MarkStart }).Count | Should -Be 1
        @($lines | Where-Object { $_ -eq '0.0.0.0 dds.microsoft.com' }).Count | Should -Be 1
    }
}

# ==============================================================
# HOSTS ENCODING — accented lines must survive the rewrite
# ==============================================================
Describe "Invoke-Gdid - hosts encoding" {

    AfterEach { Reset-FakeEnvironment }

    It "preserves an accented line in a UTF-8 hosts file" {
        Use-FakeEnvironment -HostsLines @(
            '127.0.0.1 localhost',
            "# servidor de produ$([char]0x00E7)$([char]0x00E3)o"
        ) -Encoding $Script:Utf8Enc

        Mock Get-Service         { $null }
        Mock Test-Path            { $false } -ParameterFilter { $Path -eq $Script:StateFile }
        Mock Set-WinUtilService  { }
        Mock Set-WinUtilRegistry { }
        Mock Stop-Service        { }
        Mock New-Item            { }
        Mock Copy-Item           { }
        Mock Set-Content         { }
        Mock Remove-Item         { }
        Mock ipconfig            { }

        Invoke-Gdid -SubAction 'disable' 6>&1 | Out-Null

        (Get-FakeHostsLines -Encoding $Script:Utf8Enc) |
            Should -Contain "# servidor de produ$([char]0x00E7)$([char]0x00E3)o"
    }

    It "preserves an accented line in an ANSI hosts file" {
        Use-FakeEnvironment -HostsLines @(
            '127.0.0.1 localhost',
            "# servidor de produ$([char]0x00E7)$([char]0x00E3)o"
        ) -Encoding $Script:AnsiEnc

        Mock Get-Service         { $null }
        Mock Test-Path            { $false } -ParameterFilter { $Path -eq $Script:StateFile }
        Mock Set-WinUtilService  { }
        Mock Set-WinUtilRegistry { }
        Mock Stop-Service        { }
        Mock New-Item            { }
        Mock Copy-Item           { }
        Mock Set-Content         { }
        Mock Remove-Item         { }
        Mock ipconfig            { }

        Invoke-Gdid -SubAction 'disable' 6>&1 | Out-Null

        (Get-FakeHostsLines -Encoding $Script:AnsiEnc) |
            Should -Contain "# servidor de produ$([char]0x00E7)$([char]0x00E3)o"
    }
}

# ==============================================================
# SUBACTION — enable
# ==============================================================
Describe "Invoke-Gdid - SubAction enable" {

    AfterEach { Reset-FakeEnvironment }

    BeforeEach {
        Use-FakeEnvironment -HostsLines @(
            '127.0.0.1 localhost'
            $Script:MarkStart
            '0.0.0.0 activity.windows.com'
            '0.0.0.0 client.wns.windows.com'
            $Script:MarkEnd
            '10.0.0.5 nas.local'
        ) -Encoding $Script:Utf8Enc
    }

    It "restores the startup types recorded in the state file and removes it" {
        Mock Test-Path   { $true } -ParameterFilter { $Path -eq $Script:StateFile }
        Mock Get-Content { '{"services":{"CDPSvc":"Automatic","CDPUserSvc":"Manual"}}' } `
            -ParameterFilter { $Path -eq $Script:StateFile }
        Mock Get-Service {
            [PSCustomObject]@{ Name = 'CDPSvc'; Status = 'Stopped'; StartType = 'Disabled' }
        } -ParameterFilter { $Name -eq 'CDPSvc' }
        Mock Set-WinUtilService  { }
        Mock Set-WinUtilRegistry { }
        Mock Start-Service       { }
        Mock Remove-Item         { }
        Mock ipconfig            { }

        $output = (Invoke-Gdid -SubAction 'enable') 6>&1 | Out-String

        Should -Invoke -CommandName Set-WinUtilService -Times 1 `
            -ParameterFilter { $Name -eq 'CDPSvc' -and $StartupType -eq 'Automatic' }
        Should -Invoke -CommandName Set-WinUtilService -Times 1 `
            -ParameterFilter { $Name -eq 'CDPUserSvc' -and $StartupType -eq 'Manual' }
        Should -Invoke -CommandName Start-Service -Times 1
        Should -Invoke -CommandName Remove-Item -Times 1 -ParameterFilter { $Path -eq $Script:StateFile }
        $output | Should -Match '\[ OK \].*GDID pipeline enabled'
    }

    It "removes the policy values with <RemoveEntry>" {
        Mock Test-Path   { $false } -ParameterFilter { $Path -eq $Script:StateFile }
        Mock Get-Service { $null }
        Mock Set-WinUtilService  { }
        Mock Set-WinUtilRegistry { }
        Mock Start-Service       { }
        Mock Remove-Item         { }
        Mock ipconfig            { }

        Invoke-Gdid -SubAction 'enable' 6>&1 | Out-Null

        Should -Invoke -CommandName Set-WinUtilRegistry -Times 3 `
            -ParameterFilter { $Value -eq '<RemoveEntry>' }
    }

    It "removes the hosts block and keeps every other line" {
        Mock Test-Path   { $false } -ParameterFilter { $Path -eq $Script:StateFile }
        Mock Get-Service { $null }
        Mock Set-WinUtilService  { }
        Mock Set-WinUtilRegistry { }
        Mock Start-Service       { }
        Mock Remove-Item         { }
        Mock ipconfig            { }

        Invoke-Gdid -SubAction 'enable' 6>&1 | Out-Null

        $lines = Get-FakeHostsLines -Encoding $Script:Utf8Enc
        $lines | Should -Not -Contain $Script:MarkStart
        $lines | Should -Not -Contain '0.0.0.0 activity.windows.com'
        $lines | Should -Contain '127.0.0.1 localhost'
        $lines | Should -Contain '10.0.0.5 nas.local'
    }

    It "falls back to Manual and warns when no state file exists" {
        Mock Test-Path   { $false } -ParameterFilter { $Path -eq $Script:StateFile }
        Mock Get-Service { $null }
        Mock Set-WinUtilService  { }
        Mock Set-WinUtilRegistry { }
        Mock Start-Service       { }
        Mock Remove-Item         { }
        Mock ipconfig            { }

        $output = (Invoke-Gdid -SubAction 'enable') 6>&1 | Out-String

        $output | Should -Match '\[ WARNING \].*State file not found'
        Should -Invoke -CommandName Set-WinUtilService -Times 2 `
            -ParameterFilter { $StartupType -eq 'Manual' }
    }

    It "says the CDP cache rebuilds itself instead of restoring it" {
        Mock Test-Path   { $false } -ParameterFilter { $Path -eq $Script:StateFile }
        Mock Get-Service { $null }
        Mock Set-WinUtilService  { }
        Mock Set-WinUtilRegistry { }
        Mock Start-Service       { }
        Mock Remove-Item         { }
        Mock ipconfig            { }

        $output = (Invoke-Gdid -SubAction 'enable') 6>&1 | Out-String
        $output | Should -Match '\[ INFO \].*rebuilds itself'
    }

    It "reports [ INFO ] when there is no block to remove" {
        Use-FakeEnvironment -HostsLines @('127.0.0.1 localhost') -Encoding $Script:Utf8Enc

        Mock Test-Path   { $false } -ParameterFilter { $Path -eq $Script:StateFile }
        Mock Get-Service { $null }
        Mock Set-WinUtilService  { }
        Mock Set-WinUtilRegistry { }
        Mock Start-Service       { }
        Mock Remove-Item         { }
        Mock ipconfig            { }

        $output = (Invoke-Gdid -SubAction 'enable') 6>&1 | Out-String
        $output | Should -Match 'No GDID block found in hosts'
    }
}
