function Invoke-Network {
    param(
        [string]$Interface,
        [int]$Duration = 30
    )

    $tsharkCmd = $null
    $candidatos = @('tshark', 'C:\Program Files\Wireshark\tshark.exe')
    foreach ($c in $candidatos) {
        try {
            $null = & $c --version 2>&1
            if ($LASTEXITCODE -eq 0) { $tsharkCmd = $c; break }
        } catch {}
    }

    if (-not $tsharkCmd) {
        Write-Status WARNING "TShark not found. Installing Wireshark via winget..."
        try {
            winget install WiresharkFoundation.Wireshark --silent --accept-package-agreements
            Write-Status OK "Wireshark installed."
            # Update PATH without restarting the process
            $env:PATH = [System.Environment]::GetEnvironmentVariable('PATH', 'Machine') + ';' +
                        [System.Environment]::GetEnvironmentVariable('PATH', 'User')
        } catch {
            Write-Status ERROR "Failed to install Wireshark: $($_.Exception.Message)"
            return
        }
        $tsharkCmd = 'C:\Program Files\Wireshark\tshark.exe'
        if (-not (Test-Path $tsharkCmd)) {
            Write-Status ERROR "tshark not found after installation. Please restart the terminal."
            return
        }
    }

    Write-Status INFO "Available network interfaces:"
    try {
        & $tsharkCmd -D 2>&1 | ForEach-Object { Write-Host "  $_" }
    } catch {
        Write-Status ERROR "Failed to list interfaces: $($_.Exception.Message)"
        return
    }

    if (-not $Interface) {
        Write-Status ERROR "No interface specified. Aborting."
        return
    }

    $capturesDir = Join-Path $runtime 'Captures'
    $reportsDir  = Join-Path $runtime 'Reports'
    foreach ($d in @($capturesDir, $reportsDir)) {
        if (-not (Test-Path $d)) {
            New-Item -ItemType Directory -Path $d -Force | Out-Null
        }
    }

    $ts       = Get-Date -Format 'dd.MM.yyyy_HH.mm.ss'
    $pcapFile = Join-Path $capturesDir "$ts.pcapng"
    $rptFile  = Join-Path $reportsDir  "$ts.txt"

    Write-Status INFO "Capturing for $Duration second(s) on interface '$Interface'..."
    Write-Status INFO "Destination: $pcapFile"
    try {
        & $tsharkCmd -i $Interface -a "duration:$Duration" -w $pcapFile 2>&1 | Out-Null
        if (-not (Test-Path $pcapFile)) {
            Write-Status ERROR "Capture file not generated. Check interface and permissions."
            return
        }
        Write-Status OK "Capture complete."
    } catch {
        Write-Status ERROR "Capture failed: $($_.Exception.Message)"
        return
    }

    Write-Status INFO "Generating report..."
    try {
        $sb = [System.Text.StringBuilder]::new()
        [void]$sb.AppendLine("=== WinUtil-CLI Network Report ===")
        [void]$sb.AppendLine("Capture  : $pcapFile")
        [void]$sb.AppendLine("Date     : $(Get-Date -Format 'dd/MM/yyyy HH:mm:ss')")
        [void]$sb.AppendLine("Interface: $Interface")
        [void]$sb.AppendLine("Duration : $Duration second(s)")
        [void]$sb.AppendLine("")

        [void]$sb.AppendLine("--- General Statistics ---")
        $stats = & $tsharkCmd -r $pcapFile -qz io,stat,0 2>&1
        [void]$sb.AppendLine(($stats -join [Environment]::NewLine))
        [void]$sb.AppendLine("")

        [void]$sb.AppendLine("--- Top 15 Destination IPs ---")
        $ips = & $tsharkCmd -r $pcapFile -T fields -e ip.dst 2>&1 |
               Where-Object { $_ -match '^\d{1,3}\.' }
        if ($ips) {
            $ips | Group-Object | Sort-Object Count -Descending | Select-Object -First 15 |
            ForEach-Object { [void]$sb.AppendLine("  $($_.Count.ToString().PadLeft(6))  $($_.Name)") }
        } else {
            [void]$sb.AppendLine("  (no IP captured)")
        }
        [void]$sb.AppendLine("")

        [void]$sb.AppendLine("--- TCP Conversations ---")
        $conv = & $tsharkCmd -r $pcapFile -qz conv,tcp 2>&1
        [void]$sb.AppendLine(($conv -join [Environment]::NewLine))
        [void]$sb.AppendLine("")

        [void]$sb.AppendLine("--- Top Protocols ---")
        $phs = & $tsharkCmd -r $pcapFile -qz io,phs 2>&1
        [void]$sb.AppendLine(($phs -join [Environment]::NewLine))

        $sb.ToString() | Set-Content -Path $rptFile -Encoding UTF8
        Write-Status OK "Report: $rptFile"
    } catch {
        Write-Status ERROR "Failed to generate report: $($_.Exception.Message)"
    }

    Write-Host ""
    Write-Host "=== Summary ===" -ForegroundColor Cyan
    Write-Host "  Capture  : $pcapFile" -ForegroundColor White
    Write-Host "  Report   : $rptFile"  -ForegroundColor White
}
