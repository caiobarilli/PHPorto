function Invoke-Memory {
    $toolsDir = Join-Path $root 'tools'
    $exePath  = Join-Path $toolsDir 'WinMemoryCleaner.exe'
    $url      = 'https://github.com/IgorMundstein/WinMemoryCleaner/releases/download/3.0.8/WinMemoryCleaner.exe'

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
