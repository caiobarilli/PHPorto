function Invoke-Audit {
    $auditScript = Join-Path $root 'audit\audit.ps1'
    if (-not (Test-Path $auditScript)) {
        Write-Status ERROR "audit.ps1 not found at $auditScript"
        return
    }
    Write-Status INFO "Generating system audit..."
    & $auditScript
    Write-Status OK "Audit complete."
}
