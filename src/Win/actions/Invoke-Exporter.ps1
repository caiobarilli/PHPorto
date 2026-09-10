function Invoke-Exporter {
    param([string]$SubAction)

    $exePath  = 'C:\Program Files\windows_exporter\windows_exporter.exe'
    $taskName = 'windows_exporter'

    function Start-ExporterProcess {
        $proc = Get-Process -Name 'windows_exporter' -ErrorAction SilentlyContinue
        if ($proc) {
            Write-Status INFO "windows_exporter is already running (PID: $($proc.Id))."
            return
        }
        if (-not (Test-Path $exePath)) {
            Write-Status ERROR "Executable not found: $exePath. Run the 'install' subaction."
            return
        }
        try {
            # Uses Start-Process instead of Windows service (fails with "Incorrect function" on Win11)
            Start-Process -FilePath $exePath -WindowStyle Hidden
            Write-Status OK "windows_exporter started via Start-Process."
        } catch {
            Write-Status ERROR "Failed to start process: $($_.Exception.Message)"
        }
    }

    function Register-ExporterTask {
        try {
            $action    = New-ScheduledTaskAction -Execute $exePath
            $trigger   = New-ScheduledTaskTrigger -AtStartup
            $settings  = New-ScheduledTaskSettingsSet -ExecutionTimeLimit 0 -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1)
            $principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
            Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger `
                -Settings $settings -Principal $principal -Force | Out-Null
            Write-Status OK "Scheduled task '$taskName' registered (boot/SYSTEM)."
        } catch {
            Write-Status ERROR "Failed to register scheduled task: $($_.Exception.Message)"
        }
    }

    function Install-WindowsExporter {
        if (Test-Path $exePath) {
            Write-Status INFO "windows_exporter already installed at: $exePath"
            Start-ExporterProcess
            Register-ExporterTask
            Show-ExporterMetrics
            Set-ExporterFirewall
            return
        }

        $winUtilDir = 'C:\WinUtil'
        $msiPath    = Join-Path $winUtilDir 'windows_exporter.msi'
        if (-not (Test-Path $winUtilDir)) {
            New-Item -ItemType Directory -Path $winUtilDir -Force | Out-Null
        }

        Write-Status INFO "Fetching latest release of windows_exporter..."
        try {
            # TLS 1.2 required for download to work on PS 5.1
            [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
            $apiUrl  = 'https://api.github.com/repos/prometheus-community/windows_exporter/releases/latest'
            $release = Invoke-RestMethod -Uri $apiUrl -UseBasicParsing
            $asset   = $release.assets |
                       Where-Object { $_.name -match 'amd64\.msi$' } |
                       Select-Object -First 1
            if (-not $asset) {
                Write-Status ERROR "amd64 MSI not found in the latest release."
                return
            }
            Write-Status INFO "Version: $($release.tag_name) / $($asset.name)"
            Invoke-WebRequest -Uri $asset.browser_download_url -OutFile $msiPath -UseBasicParsing
            Write-Status OK "Download complete: $msiPath"
        } catch {
            Write-Status ERROR "Download failed: $($_.Exception.Message)"
            return
        }

        Write-Status INFO "Installing windows_exporter..."
        try {
            $collectors = 'cpu,cs,logical_disk,net,os,process,service,hyperv'
            $install = Start-Process msiexec `
                -ArgumentList "/i `"$msiPath`" /quiet ENABLED_COLLECTORS=`"$collectors`"" `
                -Wait -PassThru
            if ($install.ExitCode -ne 0) {
                Write-Status ERROR "msiexec exited with code $($install.ExitCode)."
                return
            }
            Write-Status OK "windows_exporter installed."
        } catch {
            Write-Status ERROR "Installation failed: $($_.Exception.Message)"
            return
        }

        Start-ExporterProcess
        Register-ExporterTask
        Show-ExporterMetrics
        Set-ExporterFirewall
    }

    function Stop-ExporterProcess {
        $proc = Get-Process -Name 'windows_exporter' -ErrorAction SilentlyContinue
        if (-not $proc) {
            Write-Status WARNING "windows_exporter is not running."
            return
        }
        try {
            Stop-Process -Name 'windows_exporter' -Force
            Write-Status OK "windows_exporter stopped."
        } catch {
            Write-Status ERROR "Failed to stop process: $($_.Exception.Message)"
        }
    }

    function Show-ExporterStatus {
        $proc = Get-Process -Name 'windows_exporter' -ErrorAction SilentlyContinue
        $task = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue

        if ($proc) {
            Write-Status OK    "Process        : Running (PID: $($proc.Id))"
        } else {
            Write-Status WARNING "Process        : Stopped"
        }

        if ($task) {
            Write-Status INFO "Scheduled task : $($task.TaskName) / State: $($task.State)"
        } else {
            Write-Status WARNING "Scheduled task : Not found. Run 'install' to register."
        }
    }

    function Show-ExporterMetrics {
        $hostname = $env:COMPUTERNAME
        $url      = "http://${hostname}:9182/metrics"
        Write-Status INFO "Metrics URL: $url"
        try {
            $resp = Invoke-WebRequest -Uri $url -UseBasicParsing -TimeoutSec 5 -ErrorAction Stop
            Write-Status OK "Port 9182 accessible (HTTP $($resp.StatusCode))."
        } catch {
            Write-Status WARNING "Port 9182 did not respond: $($_.Exception.Message)"
        }
    }

    function Set-ExporterFirewall {
        $ruleName = 'WinUtil - windows_exporter 9182'
        $existing = Get-NetFirewallRule -DisplayName $ruleName -ErrorAction SilentlyContinue
        if ($existing) {
            Write-Status INFO "Firewall rule already exists: '$ruleName'."
            return
        }
        try {
            New-NetFirewallRule `
                -DisplayName $ruleName `
                -Direction Inbound `
                -Protocol TCP `
                -LocalPort 9182 `
                -Action Allow `
                -Profile Any | Out-Null
            Write-Status OK "Firewall rule created: '$ruleName'."
        } catch {
            Write-Status ERROR "Failed to create firewall rule: $($_.Exception.Message)"
        }
    }

    if ($SubAction) {
        switch ($SubAction.ToLower()) {
            'install'  { Install-WindowsExporter }
            'status'   { Show-ExporterStatus }
            'start'    { Start-ExporterProcess }
            'stop'     { Stop-ExporterProcess }
            'metrics'  { Show-ExporterMetrics }
            'firewall' { Set-ExporterFirewall }
            default    {
                Write-Status ERROR "Unknown subaction: '$SubAction'."
                Write-Status INFO "Options: install, status, start, stop, metrics, firewall"
            }
        }
        return
    }

    Write-Status ERROR "No subaction specified."
    Write-Status INFO "Options: install, status, start, stop, metrics, firewall"
}
