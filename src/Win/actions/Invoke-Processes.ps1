function Invoke-Processes {
    Write-Host ""
    Write-Host "================================================================================"
    Write-Host "PROCESSES (Top 30 by RAM)"
    Write-Host "================================================================================"
    Get-Process | Sort-Object WorkingSet64 -Descending | Select-Object -First 30 |
        ForEach-Object {
            "{0,-40} CPU: {1,8}   RAM: {2,8} MB" -f $_.Name, [math]::Round($_.CPU,2), [math]::Round($_.WorkingSet64/1MB,1)
        }
    Write-Host ""
}