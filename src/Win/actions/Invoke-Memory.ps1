function Invoke-Memory {
    $toolsDir = Join-Path $root 'tools'
    $exePath  = Join-Path $toolsDir 'WinMemoryCleaner.exe'
    $url      = 'https://github.com/IgorMundstein/WinMemoryCleaner/releases/download/3.0.8/WinMemoryCleaner.exe'
    # SHA-256 do WinMemoryCleaner 3.0.8, o mesmo do digest do release no
    # GitHub. O executavel roda como Administrador: trocar a versao na URL
    # exige trocar este hash junto.
    $sha256   = '8B68D56C6EE28740F76513F21B21F5A1018F0EF291467B3F3D39D43DAF0C0F2F'

    if (-not (Test-Path $exePath)) {
        Write-Status INFO "WinMemoryCleaner.exe not found. Downloading..."
        try {
            if (-not (Test-Path $toolsDir)) {
                New-Item -ItemType Directory -Path $toolsDir -Force | Out-Null
            }
            # TLS 1.2 required for download to work on PS 5.1
            [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
            Invoke-WebRequest -Uri $url -OutFile $exePath -UseBasicParsing
            Write-Status OK "Download complete."
        } catch {
            Write-Status ERROR "Download failed: $($_.Exception.Message)"
            return
        }
    }

    # Confere tambem o arquivo que ja estava em tools/: a pasta e' gravavel
    # pelo usuario, e quem trocar o exe ali ganharia uma execucao elevada.
    $hash = (Get-FileHash -LiteralPath $exePath -Algorithm SHA256 -ErrorAction SilentlyContinue).Hash
    if ($hash -ne $sha256) {
        Write-Status ERROR "WinMemoryCleaner.exe SHA-256 mismatch (expected $sha256, got $hash). File removed, nothing was run."
        Remove-Item -LiteralPath $exePath -Force -ErrorAction SilentlyContinue
        return
    }

    $ramAntes = [math]::Round((Get-CimInstance Win32_OperatingSystem).FreePhysicalMemory / 1MB, 2)
    Write-Status INFO "Free RAM before: $ramAntes GB"

    Write-Status INFO "Cleaning memory..."
    try {
        $cleanerArgs = '/CombinedPageList', '/ModifiedPageList', '/ProcessesWorkingSet', '/StandbyList', '/SystemWorkingSet'
        Start-Process -FilePath $exePath -ArgumentList $cleanerArgs -Wait -NoNewWindow
        Write-Status OK "Cleanup complete."
    } catch {
        Write-Status ERROR "Execution failed: $($_.Exception.Message)"
        return
    }

    $ramDepois = [math]::Round((Get-CimInstance Win32_OperatingSystem).FreePhysicalMemory / 1MB, 2)
    Write-Status INFO "Free RAM after: $ramDepois GB"
}
