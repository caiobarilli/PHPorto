function Invoke-GPU {
    param([string]$SubAction)

    $installDir = Join-Path $runtime 'nvidia_gpu_exporter'
    $exePath    = Join-Path $installDir 'nvidia_gpu_exporter.exe'
    $taskName   = 'nvidia_gpu_exporter'
    $port       = 9835

    function Start-GPUExporterProcess {
        $proc = Get-Process -Name 'nvidia_gpu_exporter' -ErrorAction SilentlyContinue
        if ($proc) {
            Write-Status INFO "nvidia_gpu_exporter is already running (PID: $($proc.Id))."
            return
        }
        if (-not (Test-Path $exePath)) {
            Write-Status ERROR "Executable not found: $exePath. Run the 'install' subaction."
            return
        }
        try {
            Start-Process -FilePath $exePath -WindowStyle Hidden
            Write-Status OK "nvidia_gpu_exporter started via Start-Process."
        } catch {
            Write-Status ERROR "Failed to start process: $($_.Exception.Message)"
        }
    }

    function Register-GPUExporterTask {
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

    function Set-GPUExporterFirewall {
        $ruleName = "WinUtil - nvidia_gpu_exporter $port"
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
                -LocalPort $port `
                -Action Allow `
                -Profile Any | Out-Null
            Write-Status OK "Firewall rule created: '$ruleName'."
        } catch {
            Write-Status ERROR "Failed to create firewall rule: $($_.Exception.Message)"
        }
    }

    function Install-GPUExporter {
        if (-not (Test-Path $installDir)) {
            New-Item -ItemType Directory -Path $installDir -Force | Out-Null
        }

        if (Test-Path $exePath) {
            Write-Status INFO "nvidia_gpu_exporter already installed at: $exePath"
            Start-GPUExporterProcess
            Register-GPUExporterTask
            Set-GPUExporterFirewall
            Write-Status OK "nvidia_gpu_exporter installed and running on :$port"
            return
        }

        Write-Status INFO "Fetching latest release of nvidia_gpu_exporter..."
        try {
            # TLS 1.2 required for download to work on PS 5.1
            [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
            $apiUrl  = 'https://api.github.com/repos/utkuozdemir/nvidia_gpu_exporter/releases/latest'
            $release = Invoke-RestMethod -Uri $apiUrl -UseBasicParsing
            $asset   = $release.assets |
                       Where-Object { $_.name -match 'windows_x86_64\.zip$' } |
                       Select-Object -First 1
            if (-not $asset) {
                Write-Status ERROR "Windows x86_64 zip not found in the latest release."
                return
            }
            Write-Status INFO "Version: $($release.tag_name) / $($asset.name)"
            $zipPath = Join-Path $installDir 'nvidia_gpu_exporter.zip'
            Invoke-WebRequest -Uri $asset.browser_download_url -OutFile $zipPath -UseBasicParsing
            Expand-Archive -Path $zipPath -DestinationPath $installDir -Force
            $exePath = Get-ChildItem -Path $installDir -Filter '*.exe' -Recurse |
                       Select-Object -First 1 -ExpandProperty FullName
            Remove-Item -Path $zipPath -Force
            Write-Status OK "Extraction complete: $exePath"
        } catch {
            Write-Status ERROR "Download failed: $($_.Exception.Message)"
            return
        }

        Start-GPUExporterProcess
        Register-GPUExporterTask
        Set-GPUExporterFirewall
        Write-Status OK "nvidia_gpu_exporter installed and running on :$port"
    }

    function Stop-GPUExporterProcess {
        $proc = Get-Process -Name 'nvidia_gpu_exporter' -ErrorAction SilentlyContinue
        if (-not $proc) {
            Write-Status WARNING "nvidia_gpu_exporter is not running."
            return
        }
        try {
            Stop-Process -Name 'nvidia_gpu_exporter' -Force
            Write-Status OK "nvidia_gpu_exporter stopped."
        } catch {
            Write-Status ERROR "Failed to stop process: $($_.Exception.Message)"
        }
    }

    function Show-GPUExporterStatus {
        $proc = Get-Process -Name 'nvidia_gpu_exporter' -ErrorAction SilentlyContinue
        $task = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue

        if ($proc) {
            Write-Status OK      "Process        : Running (PID: $($proc.Id))"
        } else {
            Write-Status WARNING "Process        : Stopped"
        }

        if ($task) {
            Write-Status INFO "Scheduled task : $($task.TaskName) / State: $($task.State)"
        } else {
            Write-Status WARNING "Scheduled task : Not found. Run 'install' to register."
        }
    }

    function Show-GPUMetrics {
        param([switch]$Filter)
        $url = "http://localhost:$port/metrics"
        Write-Status INFO "Metrics URL: $url"
        try {
            $resp  = Invoke-WebRequest -Uri $url -UseBasicParsing -TimeoutSec 5 -ErrorAction Stop
            $lines = ($resp.Content -split "`n") | Select-Object -First 100
            if ($Filter) {
                $lines = $lines | Where-Object { $_ -match 'nvidia_smi|nvidia_gpu_exporter_build' }
            }
            Write-Host ($lines -join "`n")
        } catch {
            Write-Status WARNING "Port $port did not respond: $($_.Exception.Message)"
        }
    }

    function Uninstall-GPUExporter {
        Stop-GPUExporterProcess

        $task = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
        if ($task) {
            try {
                Unregister-ScheduledTask -TaskName $taskName -Confirm:$false
                Write-Status OK "Scheduled task '$taskName' removed."
            } catch {
                Write-Status ERROR "Failed to remove scheduled task: $($_.Exception.Message)"
            }
        } else {
            Write-Status INFO "Scheduled task '$taskName' not found; nothing to remove."
        }

        $ruleName = "WinUtil - nvidia_gpu_exporter $port"
        $existing = Get-NetFirewallRule -DisplayName $ruleName -ErrorAction SilentlyContinue
        if ($existing) {
            try {
                Remove-NetFirewallRule -DisplayName $ruleName
                Write-Status OK "Firewall rule '$ruleName' removed."
            } catch {
                Write-Status ERROR "Failed to remove firewall rule: $($_.Exception.Message)"
            }
        } else {
            Write-Status INFO "Firewall rule '$ruleName' not found; nothing to remove."
        }

        if (Test-Path $installDir) {
            try {
                Remove-Item -Path $installDir -Recurse -Force
                Write-Status OK "Directory '$installDir' removed."
            } catch {
                Write-Status ERROR "Failed to remove directory: $($_.Exception.Message)"
            }
        } else {
            Write-Status INFO "Directory '$installDir' not found; nothing to remove."
        }
    }

    if ($SubAction) {
        switch ($SubAction.ToLower()) {
            'install'     { Install-GPUExporter }
            'status'      { Show-GPUExporterStatus }
            'start'       { Start-GPUExporterProcess }
            'stop'        { Stop-GPUExporterProcess }
            'metrics'     { Show-GPUMetrics }
            'metrics-gpu' { Show-GPUMetrics -Filter }
            'uninstall'   { Uninstall-GPUExporter }
            default {
                Write-Status ERROR "Unknown subaction: '$SubAction'."
                Write-Status INFO "Options: install, status, start, stop, metrics, metrics-gpu, uninstall"
            }
        }
        return
    }

    Write-Status ERROR "No subaction specified."
    Write-Status INFO "Options: install, status, start, stop, metrics, metrics-gpu, uninstall"
}
