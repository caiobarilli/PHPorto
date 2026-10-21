function Invoke-OpenSsh {
    param([string]$SubAction)

    $capability   = 'OpenSSH.Server~~~~0.0.1.0'
    $service      = 'sshd'
    $ruleName     = 'PHPorto-OpenSSH-In-TCP-22'
    $builtinRule  = 'OpenSSH-Server-In-TCP'
    $port         = 22
    $options      = 'status, install, enable, disable'

    function Test-OpenSshInstalled {
        $cap = Get-WindowsCapability -Online -Name $capability -ErrorAction SilentlyContinue
        return ($null -ne $cap -and [string]$cap.State -eq 'Installed')
    }

    function Disable-OpenSshBuiltinRule {
        $rule = Get-NetFirewallRule -Name $builtinRule -ErrorAction SilentlyContinue
        if ($null -ne $rule -and [string]$rule.Enabled -eq 'True') {
            Disable-NetFirewallRule -Name $builtinRule -ErrorAction Stop
            Write-Status OK "Firewall rule '$builtinRule' (all profiles) disabled; only '$ruleName' (Private) allows port $port."
        }
    }

    function Show-OpenSshStatus {
        if (Test-OpenSshInstalled) {
            Write-Status OK "OpenSSH Server: installed."
        } else {
            Write-Status INFO "OpenSSH Server: not installed."
        }

        $svc = Get-Service -Name $service -ErrorAction SilentlyContinue
        if ($null -eq $svc) {
            Write-Status INFO "Service '$service': absent."
        } else {
            Write-Status INFO "Service '$service': $($svc.Status), startup $($svc.StartType)."
        }

        $rule = Get-NetFirewallRule -Name $ruleName -ErrorAction SilentlyContinue
        if ($null -eq $rule) {
            Write-Status INFO "Firewall rule '$ruleName': absent (port $port closed by this screen)."
        } else {
            Write-Status OK "Firewall rule '$ruleName': present (TCP $port inbound, Private profile only)."
        }

        $builtin = Get-NetFirewallRule -Name $builtinRule -ErrorAction SilentlyContinue
        if ($null -ne $builtin -and [string]$builtin.Enabled -eq 'True') {
            Write-Status WARNING "Firewall rule '$builtinRule' is enabled and may allow port $port on every profile."
        }
    }

    function Install-OpenSsh {
        if (Test-OpenSshInstalled) {
            Write-Status OK "OpenSSH Server is already installed."
        } else {
            try {
                Add-WindowsCapability -Online -Name $capability -ErrorAction Stop | Out-Null
                Write-Status OK "OpenSSH Server installed ($capability)."
            } catch {
                Write-Status ERROR "Could not install OpenSSH Server: $($_.Exception.Message)"
                return
            }
        }
        try {
            Disable-OpenSshBuiltinRule
        } catch {
            Write-Status WARNING "Could not disable firewall rule '$builtinRule': $($_.Exception.Message)"
        }
        Write-Status INFO "The service is not started; use enable."
    }

    function Enable-OpenSsh {
        if ($null -eq (Get-Service -Name $service -ErrorAction SilentlyContinue)) {
            Write-Status ERROR "Service '$service' not found; run install first."
            return
        }
        try {
            Set-Service -Name $service -StartupType Automatic -ErrorAction Stop
            Start-Service -Name $service -ErrorAction Stop
            Write-Status OK "Service '$service' running, startup Automatic."
        } catch {
            Write-Status ERROR "Could not start '$service': $($_.Exception.Message)"
            return
        }
        try {
            if ($null -ne (Get-NetFirewallRule -Name $ruleName -ErrorAction SilentlyContinue)) {
                Set-NetFirewallRule -Name $ruleName -Enabled True -Direction Inbound -Action Allow -Protocol TCP -LocalPort $port -Profile Private -ErrorAction Stop
                Write-Status OK "Firewall rule '$ruleName' already present; kept at TCP $port, Private only."
            } else {
                New-NetFirewallRule -Name $ruleName -DisplayName $ruleName -Enabled True -Direction Inbound -Action Allow -Protocol TCP -LocalPort $port -Profile Private -ErrorAction Stop | Out-Null
                Write-Status OK "Firewall rule '$ruleName' created: TCP $port inbound, Private profile only."
            }
            Disable-OpenSshBuiltinRule
        } catch {
            Write-Status ERROR "Could not set the firewall rule: $($_.Exception.Message)"
            return
        }
        Write-Status OK "SSH is on for the private network."
    }

    function Disable-OpenSsh {
        $svc = Get-Service -Name $service -ErrorAction SilentlyContinue
        if ($null -ne $svc) {
            try {
                Stop-Service -Name $service -ErrorAction Stop
                Set-Service -Name $service -StartupType Manual -ErrorAction Stop
                Write-Status OK "Service '$service' stopped, startup Manual."
            } catch {
                Write-Status ERROR "Could not stop '$service': $($_.Exception.Message)"
            }
        } else {
            Write-Status INFO "Service '$service' not found; nothing to stop."
        }
        if ($null -ne (Get-NetFirewallRule -Name $ruleName -ErrorAction SilentlyContinue)) {
            try {
                Remove-NetFirewallRule -Name $ruleName -ErrorAction Stop
                Write-Status OK "Firewall rule '$ruleName' removed."
            } catch {
                Write-Status ERROR "Could not remove firewall rule '$ruleName': $($_.Exception.Message)"
            }
        } else {
            Write-Status INFO "Firewall rule '$ruleName' already absent."
        }
    }

    if (-not $SubAction) {
        Write-Status ERROR "No subaction specified."
        Write-Status INFO "Options: $options"
        return
    }

    switch ($SubAction.ToLower()) {
        'status'  { Show-OpenSshStatus }
        'install' { Install-OpenSsh }
        'enable'  { Enable-OpenSsh }
        'disable' { Disable-OpenSsh }
        default {
            Write-Status ERROR "Unknown subaction: '$SubAction'."
            Write-Status INFO "Options: $options"
        }
    }
}
