function Invoke-Optimize {
    param(
        [string]$Preset,
        [string]$Kill,
        [switch]$Undo,
        [string]$KeepUser
    )

    $stateFile = Join-Path $runtime 'optimize-state.json'
    $stateDir  = $runtime

    $sshProcesses = @(
        'LogonUI', 'SearchHost', 'StartMenuExperienceHost',
        'ShellExperienceHost', 'ShellHost', 'TextInputHost',
        'msedgewebview2', 'OfficeClickToRun',
        'LDSvc', 'WslService', 'cowork-svc'
    )

    $killRdpProcesses = @(
        'explorer', 'SearchHost', 'StartMenuExperienceHost',
        'ShellExperienceHost', 'ShellHost', 'TextInputHost',
        'msedgewebview2', 'dwm', 'sihost', 'RuntimeBroker',
        'backgroundTaskHost', 'CrossDeviceResume'
    )

    # Service-backed processes: disable before stopping to prevent SCM auto-restart.
    # Entries absent from this map fall back to Stop-Process.
    $serviceMap = @{
        'SearchHost'       = 'WSearch'
        'TextInputHost'    = 'TextInputManagementService'
        'OfficeClickToRun' = 'ClickToRunSvc'
        'LDSvc'            = 'PaceLicenseDServices'
        'WslService'       = 'WslService'
    }

    # ── UNDO ─────────────────────────────────────────────────────────────────
    if ($Undo) {
        if (-not (Test-Path $stateFile)) {
            Write-Status ERROR "State file not found: $stateFile. Nothing to undo."
            return
        }
        $raw   = Get-Content -Path $stateFile -Raw
        $state = $raw | ConvertFrom-Json

        if ($state.services) {
            $state.services.PSObject.Properties | ForEach-Object {
                $svcName  = $_.Name
                $origType = $_.Value
                Set-Service  -Name $svcName -StartupType $origType -ErrorAction SilentlyContinue
                Start-Service -Name $svcName -ErrorAction SilentlyContinue
                Write-Status OK "Restored: $svcName"
            }
        }

        Remove-Item -Path $stateFile -Force -ErrorAction SilentlyContinue
        Write-Status OK "Optimize undo complete."
        return
    }

    # ── APPLY ─────────────────────────────────────────────────────────────────
    $targets = [System.Collections.Generic.List[string]]::new()

    if ($Preset) {
        switch ($Preset.ToLower()) {
            'ssh' {
                Write-Status INFO "Preset 'ssh': stopping headless-incompatible processes..."
                foreach ($p in $sshProcesses) { $targets.Add($p) }
            }
            'kill-rdp' {
                # ── SESSION LOGOFF ─────────────────────────────────────────────
                $currentProc      = Get-Process -Id $PID -ErrorAction SilentlyContinue
                $currentSessionId = if ($currentProc) { $currentProc.SessionId } else { -1 }

                $disconnectedStates = @(
                    'Disc',                                                                                                   # en
                    'Disco',                                                                                                  # pt-BR/PT
                    'Descon',                                                                                                 # es
                    "D$([char]0x00E9)co",                                                                                    # fr: Déco
                    'Getrennt',                                                                                               # de
                    'Disconn',                                                                                                # it
                    "$([char]0x5207)$([char]0x65AD)",                                                                        # ja: 切断
                    "$([char]0x5DF2)$([char]0x65AD)$([char]0x5F00)",                                                         # zh-CN: 已断开
                    "$([char]0xC5F0)$([char]0xACB0) $([char]0xB04A)$([char]0xAE40)",                                        # ko: 연결 끊김
                    "$([char]0x041E)$([char]0x0442)$([char]0x043A)$([char]0x043B)"                                          # ru: Откл
                )

                $rawSessions  = @(query session)
                $discSessions = [System.Collections.Generic.List[object]]::new()

                if ($rawSessions.Count -gt 1) {
                    foreach ($line in $rawSessions | Select-Object -Skip 1) {
                        $clean  = $line.TrimStart('>', ' ')
                        $tokens = ($clean -split '\s{2,}') | Where-Object { $_ -ne '' }
                        if ($tokens.Count -lt 2) { continue }

                        $idIdx = -1
                        for ($i = 0; $i -lt $tokens.Count; $i++) {
                            if ($tokens[$i] -match '^\d+$') { $idIdx = $i; break }
                        }
                        if ($idIdx -lt 0 -or ($idIdx + 1) -ge $tokens.Count) { continue }

                        $sid   = [int]$tokens[$idIdx]
                        $state = $tokens[$idIdx + 1]
                        $uname = if ($idIdx -ge 1) { $tokens[$idIdx - 1] } else { '' }

                        if ($state -notmatch ($disconnectedStates -join '|')) { continue }
                        if ($sid  -eq 0)                 { continue }
                        if ($sid  -eq $currentSessionId) { continue }

                        $discSessions.Add([PSCustomObject]@{ Id = $sid; Username = $uname })
                    }
                }

                $logoffCount = 0
                if ($discSessions.Count -eq 0) {
                    Write-Status INFO "No disconnected RDP sessions found."
                } else {
                    Write-Status INFO "Found $($discSessions.Count) disconnected RDP session(s)..."
                    foreach ($sess in $discSessions) {
                        if ($KeepUser -and ($sess.Username -ieq $KeepUser)) {
                            Write-Status WARNING "Skipped session $($sess.Id) ($($sess.Username)) - protected by -KeepUser"
                            continue
                        }
                        logoff $sess.Id
                        Write-Status OK "Logged off session $($sess.Id) ($($sess.Username))"
                        $logoffCount++
                    }

                    if ($logoffCount -gt 0) {
                        Write-Status INFO "Waiting for session processes to exit..."
                        $deadline = (Get-Date).AddSeconds(10)
                        while ((Get-Date) -lt $deadline) {
                            $remaining = Get-Process -Name 'rdpclip', 'userinit' -ErrorAction SilentlyContinue
                            if (-not $remaining) { break }
                            Start-Sleep -Seconds 2
                        }
                        Write-Status OK "Session cleanup complete."
                    }
                }

                # ── PROCESS CLEANUP ────────────────────────────────────────────
                Write-Status INFO "Preset 'kill-rdp': stopping RDP session remnants..."
                foreach ($p in $killRdpProcesses) { $targets.Add($p) }
            }
            default {
                Write-Status ERROR "Unknown preset '$Preset'. Valid presets: ssh, kill-rdp"
                return
            }
        }
    }

    if ($Kill) {
        $custom = $Kill -split ',' | ForEach-Object { $_.Trim() } | Where-Object { $_ }
        if ($custom.Count -gt 0) {
            Write-Status INFO "Custom kill list: $($custom -join ', ')"
            foreach ($p in $custom) { $targets.Add($p) }
        }
    }

    if ($targets.Count -eq 0) {
        Write-Status ERROR "No processes specified. Use -Preset <ssh|kill-rdp> and/or -Kill 'proc1,proc2'."
        return
    }

    $unique     = $targets | Select-Object -Unique
    $savedState = @{}

    foreach ($proc in $unique) {
        $svcName = $serviceMap[$proc]

        if ($svcName) {
            $svc = Get-Service -Name $svcName -ErrorAction SilentlyContinue
            if ($svc -and $svc.Status -eq 'Running') {
                $savedState[$svcName] = $svc.StartType.ToString()
                Set-Service  -Name $svcName -StartupType Disabled -ErrorAction SilentlyContinue
                Stop-Service -Name $svcName -Force -ErrorAction SilentlyContinue
                Write-Status OK "Disabled: $proc"
            } else {
                Write-Status WARNING "Not running: $proc"
            }
        } else {
            $running = Get-Process -Name $proc -ErrorAction SilentlyContinue
            if ($running) {
                Stop-Process -Name $proc -Force -ErrorAction SilentlyContinue
                Write-Status OK "Stopped: $proc"
            } else {
                Write-Status WARNING "Not running: $proc"
            }
        }
    }

    if ($savedState.Count -gt 0) {
        if (-not (Test-Path $stateDir)) {
            New-Item -ItemType Directory -Path $stateDir -Force | Out-Null
        }
        $mergedServices = @{}
        if (Test-Path $stateFile) {
            $existingRaw = Get-Content -Path $stateFile -Raw -ErrorAction SilentlyContinue
            if ($existingRaw) {
                try {
                    $existing = $existingRaw | ConvertFrom-Json
                    if ($existing.services) {
                        $existing.services.PSObject.Properties | ForEach-Object { $mergedServices[$_.Name] = $_.Value }
                    }
                } catch { }
            }
        }
        foreach ($k in $savedState.Keys) { $mergedServices[$k] = $savedState[$k] }
        [ordered]@{ services = $mergedServices } |
            ConvertTo-Json -Depth 3 | Set-Content -Path $stateFile -Encoding UTF8
    }

    Write-Status OK "Optimize complete."
}
