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

<#
    Classifies a winget exit code.

    Receives the exit code of one winget install. Returns 'ok' for 0,
    'installed' when the package was already installed or had no update to
    apply, 'reboot' when winget says a restart finishes the install, and
    'error' for anything else, including a missing code.
#>
function Get-PhportoWingetOutcome {
    param($ExitCode)

    if ($null -eq $ExitCode) { return 'error' }

    switch ([int]$ExitCode) {
        0             { return 'ok' }
        -1978335189   { return 'installed' }   # 0x8A15002B UPDATE_NOT_APPLICABLE
        -1978335135   { return 'installed' }   # 0x8A150061 PACKAGE_ALREADY_INSTALLED
        -1978334967   { return 'reboot' }      # 0x8A150109 INSTALL_REBOOT_REQUIRED_TO_FINISH
        default       { return 'error' }
    }
}
