function Invoke-Sunshine {
    param([string]$SubAction)

    $wingetId = 'LizardByte.Sunshine'
    $port     = 47990
    $fwRule   = 'PHPorto - Sunshine 47990'
    $webUrl   = 'https://localhost:47990'

    function Get-SunshineService {
        return Get-Service -ErrorAction SilentlyContinue |
            Where-Object { $_.Name -like '*unshine*' -or $_.DisplayName -like '*unshine*' } |
            Select-Object -First 1
    }

    function Test-SunshinePort {
        # PS 5.1 has no -SkipCertificateCheck, and 47990 is HTTPS with a
        # self-signed cert; a plain TCP connect answers "is anything listening".
        $client = New-Object System.Net.Sockets.TcpClient
        try {
            $iar = $client.BeginConnect('127.0.0.1', $port, $null, $null)
            $ok  = $iar.AsyncWaitHandle.WaitOne(1500)
            return ($ok -and $client.Connected)
        } catch {
            return $false
        } finally {
            $client.Close()
        }
    }

    function Get-SunshinePairedCount {
        $candidates = @(
            (Join-Path $env:ProgramData 'Sunshine\config\sunshine_state.json'),
            (Join-Path $env:ProgramFiles 'Sunshine\config\sunshine_state.json')
        )
        if (${env:ProgramFiles(x86)}) {
            $candidates += (Join-Path ${env:ProgramFiles(x86)} 'Sunshine\config\sunshine_state.json')
        }

        $file = $candidates | Where-Object { Test-Path $_ } | Select-Object -First 1
        if (-not $file) { return $null }

        try {
            $state = Get-Content -Path $file -Raw -Encoding UTF8 | ConvertFrom-Json
        } catch {
            return $null
        }

        # Only count when the shape is the one we know; an unrecognised file is
        # reported as "cannot read" instead of a guessed number.
        if ($state.root -and $null -ne $state.root.devices) {
            return @($state.root.devices).Count
        }
        return $null
    }

    function Show-SunshineStatus {
        $svc = Get-SunshineService
        if ($svc) {
            Write-Status OK "Service: $($svc.Name) / Status: $($svc.Status) / StartType: $($svc.StartType)."
        } else {
            Write-Status WARNING "Service: not found. Sunshine may not be installed; run the 'install' subaction."
        }

        try {
            $listed = winget list --id $wingetId --exact --accept-source-agreements 2>$null | Out-String
            if ($listed -match [regex]::Escape($wingetId)) {
                $ver = ($listed -split "`r?`n" | Where-Object { $_ -match [regex]::Escape($wingetId) } | Select-Object -First 1)
                Write-Status OK "Installed (winget: $($ver.Trim()))."
            } else {
                Write-Status INFO "Not installed according to winget."
            }
        } catch {
            Write-Status WARNING "Could not query winget: $($_.Exception.Message)"
        }

        if (Test-SunshinePort) {
            Write-Status OK "Port $port is listening."
        } else {
            Write-Status INFO "Port $port is not listening (service stopped or not installed)."
        }

        $paired = Get-SunshinePairedCount
        if ($null -eq $paired) {
            Write-Status INFO "Paired clients: cannot be read without touching Sunshine (state file absent or unrecognised)."
        } else {
            Write-Status OK "Paired clients: $paired."
        }
    }

    function Install-Sunshine {
        Write-Status INFO "Ensuring winget is available..."
        try {
            Install-WinUtilWinget
        } catch {
            Write-Status ERROR "Failed to prepare winget: $($_.Exception.Message)"
            return
        }

        Write-Status INFO "Installing $wingetId via winget..."
        try {
            $resultados = @(Install-WinUtilProgramWinget -Action Install -Programs @($wingetId))
        } catch {
            Write-Status ERROR $_.Exception.Message
            return
        }

        foreach ($r in $resultados) {
            switch (Get-PhportoWingetOutcome -ExitCode $r.ExitCode) {
                'ok'        { Write-Status OK "$($r.Program) installed." }
                'installed' { Write-Status OK "$($r.Program) was already installed." }
                'reboot'    { Write-Status WARNING "$($r.Program) installed; Windows must restart to finish." }
                default     { Write-Status ERROR ("{0} -> winget exit code 0x{1:X8}" -f $r.Program, $r.ExitCode) }
            }
        }

        Write-Status INFO "Start the service, then open $webUrl to pair."
        Write-Status INFO "Pairing uses a PIN typed at $webUrl; PHPorto does not do that step and stores no PIN."
    }

    function Start-SunshineService {
        $svc = Get-SunshineService
        if (-not $svc) {
            Write-Status ERROR "Sunshine service not found. Run the 'install' subaction first."
            return
        }
        if ($svc.Status -eq 'Running') {
            Write-Status INFO "Sunshine service already running ($($svc.Name))."
        } else {
            try {
                Start-Service -Name $svc.Name -ErrorAction Stop
                Write-Status OK "Sunshine service started ($($svc.Name))."
            } catch {
                Write-Status ERROR "Failed to start Sunshine service: $($_.Exception.Message)"
                return
            }
        }
        Write-Status INFO "Open $webUrl to pair. Pairing uses a PIN typed there; PHPorto does not do that step."
    }

    function Stop-SunshineService {
        $svc = Get-SunshineService
        if (-not $svc) {
            Write-Status ERROR "Sunshine service not found."
            return
        }
        if ($svc.Status -ne 'Running') {
            Write-Status INFO "Sunshine service already stopped ($($svc.Name))."
            return
        }
        try {
            Stop-Service -Name $svc.Name -Force -ErrorAction Stop
            Write-Status OK "Sunshine service stopped ($($svc.Name))."
        } catch {
            Write-Status ERROR "Failed to stop Sunshine service: $($_.Exception.Message)"
        }
    }

    function Open-SunshineFirewall {
        $existing = Get-NetFirewallRule -DisplayName $fwRule -ErrorAction SilentlyContinue
        if ($existing) {
            Write-Status INFO "Firewall rule already exists: '$fwRule'."
            return
        }
        try {
            New-NetFirewallRule -DisplayName $fwRule -Direction Inbound -Protocol TCP -LocalPort $port -Action Allow -Profile Any | Out-Null
            Write-Status OK "Firewall rule created: '$fwRule' (TCP $port inbound)."
        } catch {
            Write-Status ERROR "Failed to create firewall rule: $($_.Exception.Message)"
        }
    }

    function Close-SunshineFirewall {
        $existing = Get-NetFirewallRule -DisplayName $fwRule -ErrorAction SilentlyContinue
        if (-not $existing) {
            Write-Status INFO "No firewall rule '$fwRule' to remove."
            return
        }
        try {
            Remove-NetFirewallRule -DisplayName $fwRule -ErrorAction Stop
            Write-Status OK "Firewall rule removed: '$fwRule'."
        } catch {
            Write-Status ERROR "Failed to remove firewall rule: $($_.Exception.Message)"
        }
    }

    if (-not $SubAction) {
        Write-Status ERROR "No subaction specified."
        Write-Status INFO "Options: status, install, start, stop, firewall-open, firewall-close"
        return
    }

    switch ($SubAction.ToLower()) {
        'status'         { Show-SunshineStatus }
        'install'        { Install-Sunshine }
        'start'          { Start-SunshineService }
        'stop'           { Stop-SunshineService }
        'firewall-open'  { Open-SunshineFirewall }
        'firewall-close' { Close-SunshineFirewall }
        default {
            Write-Status ERROR "Unknown subaction: '$SubAction'."
            Write-Status INFO "Options: status, install, start, stop, firewall-open, firewall-close"
        }
    }
}
