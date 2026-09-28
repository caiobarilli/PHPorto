function Invoke-Gdid {
    param([string]$SubAction)

    $stateDir  = $runtime
    $stateFile = Join-Path $stateDir 'gdid-state.json'
    $hostsBak  = Join-Path $stateDir 'gdid-hosts.backup'
    $hostsFile = Join-Path $env:WINDIR 'System32\drivers\etc\hosts'
    $cacheDir  = Join-Path $env:LOCALAPPDATA 'ConnectedDevicesPlatform'

    $markStart = '#GDID-BLOCKER START'
    $markEnd   = '#GDID-BLOCKER END'

    # CDPUserSvc is a per-user service: one template plus a CDPUserSvc_<luid> instance per session.
    $coreService = 'CDPSvc'
    $userService = 'CDPUserSvc'

    $policyKey  = 'HKLM:\SOFTWARE\Policies\Microsoft\Windows\System'
    $policyVals = @('EnableActivityFeed', 'PublishUserActivities', 'UploadUserActivities')

    $domains = @(
        # Connected Devices Platform — device discovery and cross-device handoff
        'dds.microsoft.com'
        'fd.dds.microsoft.com'
        'cs.dds.microsoft.com'
        'aad.cs.dds.microsoft.com'
        'continuum.dds.microsoft.com'
        'cdpcs.access.microsoft.com'
        # Activity History (Timeline)
        'activity.windows.com'
        'activity.microsoft.com'
        'assets.activity.windows.com'
        'ppe.activity.windows.com'
        # WNS — carries GDID traffic and also Store/system push notifications
        'client.wns.windows.com'
        'global.notify.windows.com'
        'sinnc-df.notify.windows.com'
        'bn2-df.notify.windows.com'
        'bn3p.notify.windows.com'
        'db3p.notify.windows.com'
    )

    # ── HOSTS FILE ───────────────────────────────────────────────────────────
    function Read-GdidHostsFile {
        # Reads hosts preserving its on-disk encoding. Rewriting the whole file as
        # ASCII (or as UTF-8 when it is ANSI) would corrupt accented lines already there.
        if (-not (Test-Path $hostsFile)) {
            return [PSCustomObject]@{ Lines = @(); Encoding = [System.Text.UTF8Encoding]::new($false) }
        }

        $bytes  = [System.IO.File]::ReadAllBytes($hostsFile)
        $hasBom = ($bytes.Length -ge 3 -and $bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF)
        $body   = if ($hasBom) { [byte[]]@($bytes | Select-Object -Skip 3) } else { $bytes }

        try {
            # Strict UTF-8 throws on invalid sequences, which means a legacy ANSI file.
            $text     = [System.Text.UTF8Encoding]::new($false, $true).GetString($body)
            $encoding = [System.Text.UTF8Encoding]::new($hasBom)
        } catch {
            $encoding = [System.Text.Encoding]::GetEncoding(
                [System.Globalization.CultureInfo]::CurrentCulture.TextInfo.ANSICodePage
            )
            $text = $encoding.GetString($bytes)
        }

        $lines = @($text -split "`r`n|`n|`r")
        # Drop the empty element produced by the file's final newline.
        if ($lines.Count -gt 0 -and $lines[-1] -eq '') {
            $lines = @($lines | Select-Object -First ($lines.Count - 1))
        }

        return [PSCustomObject]@{ Lines = $lines; Encoding = $encoding }
    }

    function Write-GdidHostsFile {
        param([string[]]$Lines, [System.Text.Encoding]$Encoding)
        [System.IO.File]::WriteAllLines($hostsFile, [string[]]@($Lines), $Encoding)
    }

    function Remove-GdidHostsBlock {
        param([string[]]$Lines)
        $kept   = [System.Collections.Generic.List[string]]::new()
        $inside = $false
        foreach ($line in $Lines) {
            if ($line.Trim() -eq $markStart) { $inside = $true;  continue }
            if ($line.Trim() -eq $markEnd)   { $inside = $false; continue }
            if (-not $inside) { $kept.Add($line) }
        }
        return $kept.ToArray()
    }

    function Measure-GdidHostsBlock {
        param([string[]]$Lines)
        $present = $false
        $entries = 0
        $inside  = $false
        foreach ($line in $Lines) {
            if ($line.Trim() -eq $markStart) { $inside = $true; $present = $true; continue }
            if ($line.Trim() -eq $markEnd)   { $inside = $false; continue }
            if ($inside -and $line.Trim()) { $entries++ }
        }
        return [PSCustomObject]@{ Present = $present; Entries = $entries }
    }

    function Block-GdidDomains {
        $hostsData = Read-GdidHostsFile

        if (-not (Test-Path $stateDir)) {
            New-Item -ItemType Directory -Path $stateDir -Force | Out-Null
        }
        if (Test-Path $hostsBak) {
            Write-Status INFO "hosts backup already exists: $hostsBak"
        } else {
            try {
                Copy-Item -Path $hostsFile -Destination $hostsBak -Force -ErrorAction Stop
                Write-Status OK "hosts backup saved: $hostsBak"
            } catch {
                Write-Status WARNING "Could not back up hosts: $($_.Exception.Message)"
            }
        }

        $clean = Remove-GdidHostsBlock -Lines $hostsData.Lines
        $block = @($markStart) + @($domains | ForEach-Object { "0.0.0.0 $_" }) + @($markEnd)
        try {
            Write-GdidHostsFile -Lines (@($clean) + $block) -Encoding $hostsData.Encoding
            Write-Status OK "hosts block written: $($domains.Count) domain(s) sent to 0.0.0.0."
        } catch {
            Write-Status ERROR "Failed to write hosts: $($_.Exception.Message)"
            return
        }

        ipconfig /flushdns | Out-Null
        Write-Status OK "DNS resolver cache flushed."
    }

    function Unblock-GdidDomains {
        $hostsData = Read-GdidHostsFile
        $block     = Measure-GdidHostsBlock -Lines $hostsData.Lines

        if (-not $block.Present) {
            Write-Status INFO "No GDID block found in hosts; nothing to remove."
        } else {
            try {
                Write-GdidHostsFile -Lines (Remove-GdidHostsBlock -Lines $hostsData.Lines) -Encoding $hostsData.Encoding
                Write-Status OK "hosts block removed ($($block.Entries) entry/entries)."
            } catch {
                Write-Status ERROR "Failed to write hosts: $($_.Exception.Message)"
                return
            }
        }

        ipconfig /flushdns | Out-Null
        Write-Status OK "DNS resolver cache flushed."
    }

    # ── SERVICES ─────────────────────────────────────────────────────────────
    function Get-GdidUserServices {
        # Get-Service enumeration leaves the per-user service template out, so it is
        # looked up by name and merged with the CDPUserSvc_<luid> session instances.
        $found    = [System.Collections.Generic.List[object]]::new()
        $template = @(Get-Service -Name $userService -ErrorAction SilentlyContinue) | Select-Object -First 1
        if ($template) { $found.Add($template) }

        foreach ($svc in @(Get-Service -ErrorAction SilentlyContinue | Where-Object { $_.Name -like "$userService*" })) {
            if (-not ($found | Where-Object { $_.Name -eq $svc.Name })) { $found.Add($svc) }
        }
        return $found.ToArray()
    }

    function Save-GdidServiceState {
        $saved = @{}
        if (Test-Path $stateFile) {
            try {
                $existing = Get-Content -Path $stateFile -Raw | ConvertFrom-Json
                if ($existing.services) {
                    $existing.services.PSObject.Properties | ForEach-Object { $saved[$_.Name] = $_.Value }
                }
            } catch {
                Write-Status WARNING "Unreadable state file, it will be rewritten: $($_.Exception.Message)"
            }
        }

        # Services already recorded keep their first reading, so running 'disable'
        # twice never overwrites the original startup type with 'Disabled'.
        foreach ($name in @($coreService, $userService)) {
            if ($saved.ContainsKey($name)) { continue }
            $svc = Get-Service -Name $name -ErrorAction SilentlyContinue
            if ($svc) {
                $saved[$name] = $svc.StartType.ToString()
            } else {
                Write-Status WARNING "Service $name was not found; nothing to record."
            }
        }

        if (-not (Test-Path $stateDir)) {
            New-Item -ItemType Directory -Path $stateDir -Force | Out-Null
        }
        [ordered]@{ services = $saved } |
            ConvertTo-Json -Depth 3 | Set-Content -Path $stateFile -Encoding UTF8
        Write-Status OK "Service state saved: $stateFile"
    }

    function Stop-GdidServices {
        foreach ($svc in Get-GdidUserServices) {
            if ($svc.Status -eq 'Running') {
                Stop-Service -Name $svc.Name -Force -ErrorAction SilentlyContinue
                Write-Status OK "Stopped: $($svc.Name)"
            } else {
                Write-Status INFO "Already stopped: $($svc.Name)"
            }
        }

        $core = Get-Service -Name $coreService -ErrorAction SilentlyContinue
        if (-not $core) {
            Write-Status WARNING "Service $coreService was not found."
        } elseif ($core.Status -eq 'Running') {
            Stop-Service -Name $coreService -Force -ErrorAction SilentlyContinue
            Write-Status OK "Stopped: $coreService"
        } else {
            Write-Status INFO "Already stopped: $coreService"
        }
    }

    # ── CACHE ────────────────────────────────────────────────────────────────
    function Clear-GdidCache {
        if (-not (Test-Path $cacheDir)) {
            Write-Status INFO "CDP cache not found: $cacheDir"
            return
        }
        try {
            Remove-Item -Path $cacheDir -Recurse -Force -ErrorAction Stop
            Write-Status OK "CDP cache removed: $cacheDir"
        } catch {
            # Files still held open by a running CDP instance stay behind; harmless.
            Write-Status WARNING "CDP cache only partially removed ($cacheDir): $($_.Exception.Message)"
        }
    }

    # ── STATUS ───────────────────────────────────────────────────────────────
    function Show-GdidStatus {
        $core = Get-Service -Name $coreService -ErrorAction SilentlyContinue
        if ($core) {
            Write-Status INFO "$coreService : StartType=$($core.StartType) / Status=$($core.Status)"
        } else {
            Write-Status WARNING "$coreService : not found"
        }

        $instances = Get-GdidUserServices
        if ($instances.Count -eq 0) {
            Write-Status WARNING "$userService* : no instance found"
        } else {
            foreach ($svc in $instances) {
                Write-Status INFO "$($svc.Name) : StartType=$($svc.StartType) / Status=$($svc.Status)"
            }
        }

        foreach ($name in $policyVals) {
            $entry = Get-ItemProperty -Path $policyKey -Name $name -ErrorAction SilentlyContinue
            if ($entry) {
                Write-Status INFO "Policy $name : $($entry.$name)"
            } else {
                Write-Status WARNING "Policy $name : not set (Windows default applies)"
            }
        }

        $block = Measure-GdidHostsBlock -Lines (Read-GdidHostsFile).Lines
        if ($block.Present) {
            Write-Status OK "hosts block : present ($($block.Entries) entry/entries)"
        } else {
            Write-Status WARNING "hosts block : absent"
        }

        if (Test-Path $cacheDir) {
            Write-Status INFO "CDP cache : present ($cacheDir)"
        } else {
            Write-Status INFO "CDP cache : absent ($cacheDir)"
        }

        if (Test-Path $stateFile) {
            Write-Status INFO "State file : $stateFile"
        } else {
            Write-Status INFO "State file : none - nothing disabled by this action"
        }
    }

    # ── DISABLE ──────────────────────────────────────────────────────────────
    function Disable-GdidPipeline {
        Save-GdidServiceState

        # Disable before stopping so the SCM does not bring the services straight back.
        Set-WinUtilService -Name $coreService -StartupType 'Disabled'
        Set-WinUtilService -Name $userService -StartupType 'Disabled'
        Stop-GdidServices

        foreach ($name in $policyVals) {
            Set-WinUtilRegistry -Name $name -Path $policyKey -Type 'DWord' -Value '0'
        }
        Write-Status OK "Activity History policy disabled: $($policyVals -join ', ')"

        Block-GdidDomains
        Clear-GdidCache

        Write-Status WARNING "The WNS domains in this block also carry Windows push notifications: Store apps and some system toasts will stop arriving."
        Write-Status WARNING "Run '-Action gdid -SubAction enable' to put them back."
        Write-Status OK "GDID pipeline disabled."
    }

    # ── ENABLE ───────────────────────────────────────────────────────────────
    function Enable-GdidPipeline {
        $saved = @{}
        if (Test-Path $stateFile) {
            try {
                $state = Get-Content -Path $stateFile -Raw | ConvertFrom-Json
                if ($state.services) {
                    $state.services.PSObject.Properties | ForEach-Object { $saved[$_.Name] = $_.Value }
                }
            } catch {
                Write-Status WARNING "Unreadable state file ($stateFile): $($_.Exception.Message)"
            }
        } else {
            Write-Status WARNING "State file not found: $stateFile. Falling back to 'Manual' startup."
        }

        foreach ($name in @($coreService, $userService)) {
            $startupType = if ($saved.ContainsKey($name) -and $saved[$name]) { $saved[$name] } else { 'Manual' }
            if ($startupType -eq 'Disabled') {
                Write-Status WARNING "$name was already Disabled before 'disable' ran; restoring it as Manual."
                $startupType = 'Manual'
            }
            Set-WinUtilService -Name $name -StartupType $startupType
        }

        $core = Get-Service -Name $coreService -ErrorAction SilentlyContinue
        if (-not $core) {
            Write-Status WARNING "Service $coreService was not found; nothing to start."
        } else {
            try {
                Start-Service -Name $coreService -ErrorAction Stop
                Write-Status OK "Started: $coreService"
            } catch {
                Write-Status WARNING "Could not start $coreService : $($_.Exception.Message)"
            }
        }

        foreach ($name in $policyVals) {
            Set-WinUtilRegistry -Name $name -Path $policyKey -Type 'DWord' -Value '<RemoveEntry>'
        }
        Write-Status OK "Activity History policy values removed: $($policyVals -join ', ')"

        Unblock-GdidDomains

        if (Test-Path $hostsBak) {
            Write-Status INFO "Pre-block hosts backup kept at: $hostsBak"
        }

        Write-Status INFO "The ConnectedDevicesPlatform cache rebuilds itself; nothing to restore."

        if (Test-Path $stateFile) {
            Remove-Item -Path $stateFile -Force -ErrorAction SilentlyContinue
            Write-Status OK "State file removed: $stateFile"
        }

        Write-Status WARNING "CDPUserSvc instances are per-session: sign out and back in, or reboot, for them to come up again."
        Write-Status OK "GDID pipeline enabled."
    }

    # ── DISPATCH ─────────────────────────────────────────────────────────────
    if (-not $SubAction) {
        Write-Status ERROR "No subaction specified."
        Write-Status INFO "Options: status, disable, enable"
        return
    }

    switch ($SubAction.ToLower()) {
        'status'  { Show-GdidStatus }
        'disable' { Disable-GdidPipeline }
        'enable'  { Enable-GdidPipeline }
        default {
            Write-Status ERROR "Unknown subaction: '$SubAction'."
            Write-Status INFO "Options: status, disable, enable"
        }
    }
}
