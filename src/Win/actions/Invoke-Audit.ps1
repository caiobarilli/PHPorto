function Invoke-Audit {
    param(
        [string]$SubAction = 'run'
    )

    switch ($SubAction) {
        'run' {
            $auditScript = Join-Path $root 'audit\audit.ps1'
            if (-not (Test-Path $auditScript)) {
                Write-Status ERROR "audit.ps1 not found at $auditScript"
                return
            }
            Write-Status INFO "Generating system audit..."
            & $auditScript
            Write-Status OK "Audit complete."
        }
        'open' {
            Open-PhportoAuditFolder
        }
        default {
            Write-Status ERROR "Unknown subaction '$SubAction'."
            Write-Status INFO  "Subactions: run, open"
        }
    }
}

<#
    Opens the audit log folder in the Explorer of the machine running the server.

    Receives nothing. Opens today's C:\log\DD.MM.YYYY when it exists, C:\log
    when only older audits exist, and writes an ERROR when there is no log
    yet. Returns nothing.

    The execution record of this subaction says exit 0 every time: explorer.exe
    hands the path to the shell that is already running and returns at once, so
    the exit code does not prove that any window opened.
#>
function Open-PhportoAuditFolder {
    $raiz = 'C:\log'
    $hoje = Join-Path $raiz (Get-Date -Format 'dd.MM.yyyy')

    if (Test-Path $hoje) {
        $alvo = $hoje
    } elseif (Test-Path $raiz) {
        $alvo = $raiz
        Write-Status INFO "No audit from today; opening $raiz instead."
    } else {
        Write-Status ERROR "No audit log yet: $raiz does not exist. Run the audit first."
        return
    }

    Start-Process -FilePath 'explorer.exe' -ArgumentList ('"' + $alvo + '"')
    Write-Status OK "Explorer asked to open $alvo."
}
