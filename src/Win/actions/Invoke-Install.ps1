function Invoke-Install {
    param([string]$Apps)

    if (-not $Apps) {
        Write-Status ERROR "Specify apps with -Apps (e.g.: 'Git.Git,Microsoft.VSCode')."
        return
    }

    $list = @($Apps -split ',' | ForEach-Object { $_.Trim() } | Where-Object { $_ })
    if ($list.Count -eq 0) {
        Write-Status ERROR "No valid app in the provided list."
        return
    }

    Write-Status INFO "Ensuring winget is available..."
    try {
        Install-WinUtilWinget
    } catch {
        Write-Status ERROR "Failed to prepare winget: $($_.Exception.Message)"
        return
    }

    Write-Status INFO "Installing: $($list -join ', ')"
    try {
        $resultados = @(Install-WinUtilProgramWinget -Action Install -Programs $list)
    } catch {
        Write-Status ERROR $_.Exception.Message
        return
    }

    $falhas    = @()
    $reiniciar = @()
    foreach ($r in $resultados) {
        switch (Get-PhportoWingetOutcome -ExitCode $r.ExitCode) {
            'ok'        { Write-Status OK "$($r.Program) installed." }
            'installed' { Write-Status OK "$($r.Program) was already installed." }
            'reboot'    { Write-Status WARNING "$($r.Program) installed; Windows must restart to finish."; $reiniciar += $r.Program }
            default     { Write-Status ERROR ("{0} -> winget exit code 0x{1:X8}" -f $r.Program, $r.ExitCode); $falhas += $r.Program }
        }
    }

    if ($falhas.Count -gt 0) {
        Write-Status ERROR "Installation finished with $($falhas.Count) error(s): $($falhas -join ', ')"
        return
    }
    if ($reiniciar.Count -gt 0) {
        Write-Status WARNING "Installation complete; restart Windows to finish: $($reiniciar -join ', ')"
        return
    }
    Write-Status OK "Installation complete."
}

function Get-PhportoWingetOutcome {
    param($ExitCode)

    if ($null -eq $ExitCode) { return 'error' }

    switch ([int]$ExitCode) {
        0             { return 'ok' }
        -1978335189   { return 'installed' }
        -1978335135   { return 'installed' }
        -1978334967   { return 'reboot' }
        default       { return 'error' }
    }
}
