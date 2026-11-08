function Invoke-Autostart {
    param(
        [string]$SubAction,
        [string]$UserName,
        [string]$Program,
        [string]$Script
    )

    $taskName = 'PHPorto'
    $opcoes   = 'on, off, stop'

    function Test-AutostartPaths {
        if ([string]::IsNullOrWhiteSpace($Program) -or [IO.Path]::GetFileName($Program) -ne 'php.exe' -or -not (Test-Path -LiteralPath $Program -PathType Leaf)) {
            Write-Status ERROR "Program is not an existing php.exe: '$Program'."
            return $false
        }
        if ([string]::IsNullOrWhiteSpace($Script) -or [IO.Path]::GetFileName($Script) -ne 'phporto' -or -not (Test-Path -LiteralPath $Script -PathType Leaf)) {
            Write-Status ERROR "Script is not an existing phporto file: '$Script'."
            return $false
        }
        return $true
    }

    function Test-AutostartUser {
        $atual = [Security.Principal.WindowsIdentity]::GetCurrent().Name
        if ([string]::IsNullOrWhiteSpace($UserName) -or $UserName -ne $atual) {
            Write-Status ERROR "UserName does not match the account of this process. Nothing was created."
            return $false
        }
        return $true
    }

    function Enable-AutostartTask {
        if (-not (Test-AutostartPaths)) { return }
        if (-not (Test-AutostartUser)) { return }

        try {
            $action    = New-ScheduledTaskAction -Execute $Program -Argument ('"' + $Script + '" serve') -WorkingDirectory (Split-Path -Parent $Script)
            $trigger   = New-ScheduledTaskTrigger -AtStartup
            $settings  = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1) `
                -ExecutionTimeLimit ([TimeSpan]::Zero) -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
            $principal = New-ScheduledTaskPrincipal -UserId $UserName -LogonType S4U -RunLevel Limited
            Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Settings $settings -Principal $principal -Force | Out-Null
            Write-Status OK "Scheduled task '$taskName' registered (at startup, $UserName, S4U, RunLevel Limited, no password stored)."
        } catch {
            Write-Status ERROR "Failed to register scheduled task: $($_.Exception.Message)"
        }
    }

    function Disable-AutostartTask {
        try {
            $task = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
            if (-not $task) {
                Write-Status OK "No scheduled task '$taskName' to remove."
                return
            }
            Unregister-ScheduledTask -TaskName $taskName -Confirm:$false -ErrorAction Stop
            Write-Status OK "Scheduled task '$taskName' removed. A running server is not stopped."
        } catch {
            Write-Status ERROR "Failed to remove scheduled task: $($_.Exception.Message)"
        }
    }

    function Stop-AutostartTask {
        if ([string]::IsNullOrWhiteSpace($Script) -or [IO.Path]::GetFileName($Script) -ne 'phporto') {
            Write-Status ERROR "Script is not a phporto file: '$Script'."
            return
        }

        try {
            $alvo = '*"' + $Script + '" serve*'
            $vivos = @(Get-CimInstance -ClassName Win32_Process -Filter "Name='php.exe'" -ErrorAction Stop |
                Where-Object { $_.CommandLine -like $alvo })
            foreach ($p in $vivos) {
                & taskkill.exe /T /F /PID $p.ProcessId | Out-Null
            }
            $task = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
            if ($task) {
                Stop-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
            }
            Write-Status OK "Server stopped ($($vivos.Count) process tree(s))."
        } catch {
            Write-Status ERROR "Failed to stop the server: $($_.Exception.Message)"
        }
    }

    if (-not $SubAction) {
        Write-Status ERROR "SubAction required. Options: $opcoes"
        return
    }

    switch ($SubAction) {
        'on'    { Enable-AutostartTask }
        'off'   { Disable-AutostartTask }
        'stop'  { Stop-AutostartTask }
        default { Write-Status ERROR "Unknown subaction '$SubAction'. Options: $opcoes" }
    }
}
