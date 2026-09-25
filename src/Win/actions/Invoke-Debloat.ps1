function Invoke-Debloat {
    param(
        [string[]]$Packages
    )

    $appxToRemove = @($sync.configs.debloat)
    $falhas = @()

    if ($Packages) {
        $pedidos = @($Packages | ForEach-Object { $_ -split ',' } | ForEach-Object { $_.Trim() } | Where-Object { $_ })
        foreach ($name in $pedidos) {
            if ($name -notin $appxToRemove) {
                Write-Status ERROR "$name -> not in debloat.json, not removed"
                $falhas += $name
            }
        }
        $appxToRemove = @($pedidos | Where-Object { $_ -in $appxToRemove })
    }

    if ($appxToRemove.Count -eq 0 -and $falhas.Count -eq 0) {
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
            $falhas += $name
        }
    }
    if ($falhas.Count -gt 0) {
        Write-Status ERROR "Debloat finished with $($falhas.Count) error(s): $($falhas -join ', ')"
        return
    }
    Write-Status OK "Debloat complete."
}
