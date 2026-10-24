function Test-PhportoWslExe {
    return ($null -ne (Get-Command -Name 'wsl.exe' -CommandType Application -ErrorAction SilentlyContinue))
}

function Invoke-PhportoWslExe {
    param([string[]]$Argumentos)

    $ErrorActionPreference = 'Continue'
    $env:WSL_UTF8 = '1'
    $saida = @(& wsl.exe @Argumentos 2>&1 | ForEach-Object { [string]$_ })
    return @{ ExitCode = $LASTEXITCODE; Output = ($saida -join "`n").Replace([string][char]0, '').Trim() }
}

function Invoke-Wsl {
    param([string]$SubAction)

    $options = 'shutdown, wake'
    $global:LASTEXITCODE = 1

    if (-not $SubAction) {
        Write-Status ERROR "No subaction specified."
        Write-Status INFO "Options: $options"
        return
    }

    $sub = $SubAction.ToLower()
    if ($sub -ne 'shutdown' -and $sub -ne 'wake') {
        Write-Status ERROR "Unknown subaction '$SubAction'."
        Write-Status INFO "Options: $options"
        return
    }

    if (-not (Test-PhportoWslExe)) {
        Write-Status ERROR "wsl.exe not found; WSL is not installed."
        return
    }

    if ($sub -eq 'shutdown') {
        $r = Invoke-PhportoWslExe -Argumentos @('--shutdown')
        if ($r.Output) { Write-Host $r.Output }
        if ($r.ExitCode -ne 0) {
            Write-Status ERROR "wsl --shutdown failed (exit $($r.ExitCode))."
            $global:LASTEXITCODE = 1
            return
        }
        Write-Status OK "WSL shut down: every distro and the WSL 2 VM stopped."
        $global:LASTEXITCODE = 0
        return
    }

    $r = Invoke-PhportoWslExe -Argumentos @('--exec', '/bin/true')
    if ($r.Output) { Write-Host $r.Output }
    if ($r.ExitCode -ne 0) {
        Write-Status ERROR "Starting the default distro failed (exit $($r.ExitCode))."
        $global:LASTEXITCODE = 1
        return
    }

    $lista = Invoke-PhportoWslExe -Argumentos @('--list', '--running', '--quiet')
    $rodando = @($lista.Output -split "`n" | ForEach-Object { $_.Trim() } | Where-Object { $_ })
    if ($rodando.Count -gt 0) {
        Write-Status OK "Default distro started. Running: $($rodando -join ', ')."
    } else {
        Write-Status OK "Default distro started."
    }
    Write-Status INFO "WSL may stop it again after the idle timeout when nothing is running inside."
    $global:LASTEXITCODE = 0
}

function Invoke-WslEnable {
    $global:LASTEXITCODE = 1

    if (-not (Test-PhportoWslExe)) {
        Write-Status ERROR "wsl.exe not found; this Windows cannot enable WSL from here."
        return
    }

    $r = Invoke-PhportoWslExe -Argumentos @('--install', '--no-distribution')
    if ($r.Output) { Write-Host $r.Output }
    if ($r.ExitCode -ne 0) {
        Write-Status ERROR "wsl --install --no-distribution failed (exit $($r.ExitCode))."
        $global:LASTEXITCODE = 1
        return
    }

    Write-Status OK "WSL enabled without installing a distro."
    Write-Status INFO "Restart Windows to finish enabling WSL."
    $global:LASTEXITCODE = 0
}
