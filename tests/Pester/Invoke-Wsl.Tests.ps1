#Requires -Version 5.1

BeforeAll {
    $Script:RaizProjeto = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $Script:PastaWin    = Join-Path $Script:RaizProjeto 'src\Win'
    $Script:Acao        = Join-Path $Script:PastaWin 'actions\Invoke-Wsl.ps1'

    . (Join-Path $Script:PastaWin 'bootstrap.ps1')
}

Describe 'Invoke-Wsl - Sanity' {

    It 'Invoke-Wsl.ps1 has no syntax errors' {
        $errors = $null
        [System.Management.Automation.Language.Parser]::ParseFile($Script:Acao, [ref]$null, [ref]$errors) | Out-Null
        $errors.Count | Should -Be 0
    }

    It 'does not prompt, does not auto-elevate, and never unregisters or terminates a distro' {
        $text = Get-Content -Path $Script:Acao -Raw
        $text | Should -Not -Match 'Read-Host'
        $text | Should -Not -Match 'RunAs'
        $text | Should -Not -Match 'unregister'
        $text | Should -Not -Match 'terminate'
        $text | Should -Not -Match 'Start-Process'
    }

    It 'the map dispatches wsl to Invoke-Wsl' {
        $global:PhportoWinActions['wsl'] | Should -Be 'Invoke-Wsl'
    }

    It 'the map dispatches wsl-enable to Invoke-WslEnable' {
        $global:PhportoWinActions['wsl-enable'] | Should -Be 'Invoke-WslEnable'
    }
}

Describe 'Invoke-Wsl - SubAction validation' {

    BeforeEach {
        Mock Test-PhportoWslExe { $true }
        Mock Invoke-PhportoWslExe { @{ ExitCode = 0; Output = '' } }
    }

    It 'emits [ ERROR ] and lists the options when no subaction is given' {
        $output = (Invoke-Wsl) 6>&1 | Out-String
        $output | Should -Match '\[ ERROR \]'
        $output | Should -Match 'shutdown, wake'
        $global:LASTEXITCODE | Should -Be 1
        Should -Invoke Invoke-PhportoWslExe -Times 0
    }

    It 'emits [ ERROR ] for an unrecognised subaction without calling wsl' {
        $output = (Invoke-Wsl -SubAction 'unregister') 6>&1 | Out-String
        $output | Should -Match '\[ ERROR \]'
        $global:LASTEXITCODE | Should -Be 1
        Should -Invoke Invoke-PhportoWslExe -Times 0
    }

    It 'reports a missing wsl.exe as an error' {
        Mock Test-PhportoWslExe { $false }

        $output = (Invoke-Wsl -SubAction 'shutdown') 6>&1 | Out-String

        $output | Should -Match '\[ ERROR \].*wsl\.exe not found'
        $global:LASTEXITCODE | Should -Be 1
        Should -Invoke Invoke-PhportoWslExe -Times 0
    }
}

Describe 'Invoke-Wsl - shutdown' {

    BeforeEach {
        Mock Test-PhportoWslExe { $true }
    }

    It 'runs only wsl --shutdown and reports OK' {
        Mock Invoke-PhportoWslExe { @{ ExitCode = 0; Output = '' } }

        $output = (Invoke-Wsl -SubAction 'SHUTDOWN') 6>&1 | Out-String

        Should -Invoke Invoke-PhportoWslExe -Times 1 -Exactly
        Should -Invoke Invoke-PhportoWslExe -Times 1 -Exactly -ParameterFilter { ($Argumentos -join ' ') -eq '--shutdown' }
        $output | Should -Match '\[ OK \].*shut down'
        $global:LASTEXITCODE | Should -Be 0
    }

    It 'reports a failing shutdown as an error with its exit code' {
        Mock Invoke-PhportoWslExe { @{ ExitCode = 5; Output = 'access denied' } }

        $output = (Invoke-Wsl -SubAction 'shutdown') 6>&1 | Out-String

        $output | Should -Match 'access denied'
        $output | Should -Match '\[ ERROR \].*exit 5'
        $global:LASTEXITCODE | Should -Be 1
    }
}

Describe 'Invoke-Wsl - wake' {

    BeforeEach {
        Mock Test-PhportoWslExe { $true }
    }

    It 'boots the default distro with a no-op and lists what is running' {
        Mock Invoke-PhportoWslExe { @{ ExitCode = 0; Output = '' } } -ParameterFilter { $Argumentos[0] -eq '--exec' }
        Mock Invoke-PhportoWslExe { @{ ExitCode = 0; Output = "Debian`nUbuntu" } } -ParameterFilter { $Argumentos[0] -eq '--list' }

        $output = (Invoke-Wsl -SubAction 'wake') 6>&1 | Out-String

        Should -Invoke Invoke-PhportoWslExe -Times 1 -Exactly -ParameterFilter { ($Argumentos -join ' ') -eq '--exec /bin/true' }
        Should -Invoke Invoke-PhportoWslExe -Times 1 -Exactly -ParameterFilter { ($Argumentos -join ' ') -eq '--list --running --quiet' }
        $output | Should -Match '\[ OK \].*Running: Debian, Ubuntu\.'
        $output | Should -Match 'idle timeout'
        $global:LASTEXITCODE | Should -Be 0
    }

    It 'never names a distro: it is always the Windows default' {
        Mock Invoke-PhportoWslExe { @{ ExitCode = 0; Output = '' } }

        (Invoke-Wsl -SubAction 'wake') 6>&1 | Out-Null

        Should -Invoke Invoke-PhportoWslExe -Times 0 -ParameterFilter { $Argumentos -contains '-d' -or $Argumentos -contains '--distribution' }
    }

    It 'reports a failing boot as an error and does not list' {
        Mock Invoke-PhportoWslExe { @{ ExitCode = 4294967295; Output = 'There is no distribution with the supplied name.' } }

        $output = (Invoke-Wsl -SubAction 'wake') 6>&1 | Out-String

        $output | Should -Match '\[ ERROR \].*Starting the default distro failed'
        $global:LASTEXITCODE | Should -Be 1
        Should -Invoke Invoke-PhportoWslExe -Times 1 -Exactly
    }
}

Describe 'Invoke-WslEnable' {

    BeforeEach {
        Mock Test-PhportoWslExe { $true }
    }

    It 'runs only wsl --install --no-distribution as an argument array and asks for a restart' {
        Mock Invoke-PhportoWslExe { @{ ExitCode = 0; Output = 'The requested operation is successful.' } }

        $output = (Invoke-WslEnable) 6>&1 | Out-String

        Should -Invoke Invoke-PhportoWslExe -Times 1 -Exactly
        Should -Invoke Invoke-PhportoWslExe -Times 1 -Exactly -ParameterFilter {
            $Argumentos.Count -eq 2 -and $Argumentos[0] -eq '--install' -and $Argumentos[1] -eq '--no-distribution'
        }
        $output | Should -Match '\[ OK \].*without installing a distro'
        $output | Should -Match 'Restart Windows'
        $global:LASTEXITCODE | Should -Be 0
    }

    It 'never names or installs a distro' {
        Mock Invoke-PhportoWslExe { @{ ExitCode = 0; Output = '' } }

        (Invoke-WslEnable) 6>&1 | Out-Null

        Should -Invoke Invoke-PhportoWslExe -Times 0 -ParameterFilter { $Argumentos -contains '-d' -or $Argumentos -contains '--distribution' }
    }

    It 'builds the call from an array literal, not from a joined string' {
        $text = Get-Content -Path $Script:Acao -Raw
        $text | Should -Match "Invoke-PhportoWslExe -Argumentos @\('--install', '--no-distribution'\)"
        $text | Should -Not -Match 'Invoke-Expression'
    }

    It 'reports a failing install as an error with its exit code' {
        Mock Invoke-PhportoWslExe { @{ ExitCode = 5; Output = 'access denied' } }

        $output = (Invoke-WslEnable) 6>&1 | Out-String

        $output | Should -Match 'access denied'
        $output | Should -Match '\[ ERROR \].*exit 5'
        $output | Should -Not -Match 'Restart Windows'
        $global:LASTEXITCODE | Should -Be 1
    }

    It 'reports a missing wsl.exe without calling it' {
        Mock Test-PhportoWslExe { $false }
        Mock Invoke-PhportoWslExe { @{ ExitCode = 0; Output = '' } }

        $output = (Invoke-WslEnable) 6>&1 | Out-String

        $output | Should -Match '\[ ERROR \].*wsl\.exe not found'
        $global:LASTEXITCODE | Should -Be 1
        Should -Invoke Invoke-PhportoWslExe -Times 0
    }
}
