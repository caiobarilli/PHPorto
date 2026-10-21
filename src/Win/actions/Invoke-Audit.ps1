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
            & ([scriptblock]::Create((Read-PhportoFonte 'audit/audit.ps1')))
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
