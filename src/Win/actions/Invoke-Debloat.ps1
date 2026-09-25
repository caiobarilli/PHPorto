function Invoke-Debloat {
    $appxToRemove = @($sync.configs.debloat)

    if ($appxToRemove.Count -eq 0) {
        Write-Status WARNING "No packages defined for removal."
        return
    }

    Write-Status INFO "Removing $($appxToRemove.Count) APPX package(s)..."
    foreach ($name in $appxToRemove) {
        try {
            Remove-WinUtilAPPX -Name $name
            Write-Status OK $name
        } catch {
            Write-Status ERROR "$name -> $($_.Exception.Message)"
        }
    }
    Write-Status OK "Debloat complete."
}
