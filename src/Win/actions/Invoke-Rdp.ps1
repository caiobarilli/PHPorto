function Invoke-Rdp {
    param([string]$SubAction)

    $tsControl   = 'HKLM:\SYSTEM\CurrentControlSet\Control\Terminal Server'
    $rdpTcp      = 'HKLM:\SYSTEM\CurrentControlSet\Control\Terminal Server\WinStations\RDP-Tcp'
    $tsPolicy    = 'HKLM:\SOFTWARE\Policies\Microsoft\Windows NT\Terminal Services'
    $fwGroup     = 'Remote Desktop'
    $defaultPort = 3389

    function Get-RdpValue {
        param([string]$Path, [string]$Name)
        try {
            return (Get-ItemProperty -Path $Path -Name $Name -ErrorAction Stop).$Name
        } catch {
            return $null
        }
    }

    function Show-RdpStatus {
        $deny = Get-RdpValue $tsControl 'fDenyTSConnections'
        if ($null -eq $deny) {
            Write-Status WARNING "Remote Desktop: not set (fDenyTSConnections absent; Windows default is off)."
        } elseif ([int]$deny -eq 0) {
            Write-Status OK "Remote Desktop: ON (fDenyTSConnections=0)."
        } else {
            Write-Status INFO "Remote Desktop: OFF (fDenyTSConnections=$deny)."
        }

        $nla = Get-RdpValue $rdpTcp 'UserAuthentication'
        if ($null -eq $nla) {
            Write-Status WARNING "Network Level Authentication: not set (UserAuthentication absent)."
        } elseif ([int]$nla -eq 1) {
            Write-Status OK "Network Level Authentication: required (UserAuthentication=1)."
        } else {
            Write-Status WARNING "Network Level Authentication: NOT required (UserAuthentication=$nla)."
        }

        $port = Get-RdpValue $rdpTcp 'PortNumber'
        if ($null -eq $port) {
            Write-Status WARNING "Listening port: not set (PortNumber absent)."
        } elseif ([int]$port -eq $defaultPort) {
            Write-Status OK "Listening port: default ($defaultPort)."
        } else {
            Write-Status INFO "Listening port: $port (default is $defaultPort)."
        }

        $avc = Get-RdpValue $tsPolicy 'AVC444ModePreferred'
        if ($null -eq $avc) {
            Write-Status INFO "H.264/AVC 444 video: off (AVC444ModePreferred not set)."
        } elseif ([int]$avc -eq 1) {
            Write-Status OK "H.264/AVC 444 video: on (AVC444ModePreferred=1)."
        } else {
            Write-Status INFO "H.264/AVC 444 video: off (AVC444ModePreferred=$avc)."
        }

        # The message named fClientDisableUDP for UDP, but that value is a client
        # lever under \Client and is absent on this host; the server transport
        # lives in SelectTransport (0 both, 1 TCP only, 2 either).
        $transport = Get-RdpValue $tsPolicy 'SelectTransport'
        if ($null -eq $transport) {
            Write-Status INFO "UDP transport: Windows default (SelectTransport not set; UDP available)."
        } else {
            switch ([int]$transport) {
                0       { Write-Status OK "UDP transport: both UDP and TCP (SelectTransport=0)." }
                2       { Write-Status OK "UDP transport: UDP or TCP (SelectTransport=2)." }
                1       { Write-Status WARNING "UDP transport: TCP only (SelectTransport=1)." }
                default { Write-Status INFO "UDP transport: SelectTransport=$transport." }
            }
        }
    }

    function Enable-Rdp {
        Set-WinUtilRegistry -Name 'fDenyTSConnections' -Path $tsControl -Type 'DWord' -Value '0'
        Write-Status OK "Remote Desktop enabled (fDenyTSConnections=0)."
        try {
            Enable-NetFirewallRule -DisplayGroup $fwGroup -ErrorAction Stop
            Write-Status OK "Firewall group '$fwGroup' enabled."
        } catch {
            Write-Status WARNING "Could not enable firewall group '$fwGroup': $($_.Exception.Message)"
        }
        Write-Status OK "Remote Desktop is on; it takes effect immediately."
    }

    function Disable-Rdp {
        Set-WinUtilRegistry -Name 'fDenyTSConnections' -Path $tsControl -Type 'DWord' -Value '1'
        Write-Status OK "Remote Desktop disabled (fDenyTSConnections=1); it takes effect immediately."
        Write-Status INFO "The firewall group '$fwGroup' is left as-is: fDenyTSConnections is the switch."
    }

    function Enable-RdpH264 {
        Set-WinUtilRegistry -Name 'AVC444ModePreferred' -Path $tsPolicy -Type 'DWord' -Value '1'
        Set-WinUtilRegistry -Name 'SelectTransport' -Path $tsPolicy -Type 'DWord' -Value '0'
        Write-Status OK "H.264/AVC 444 preferred (AVC444ModePreferred=1)."
        Write-Status OK "UDP transport set to both UDP and TCP (SelectTransport=0)."
        Write-Status WARNING "H.264/UDP takes effect only after Windows restarts."
    }

    function Disable-RdpH264 {
        Set-WinUtilRegistry -Name 'AVC444ModePreferred' -Path $tsPolicy -Type 'DWord' -Value '<RemoveEntry>'
        Set-WinUtilRegistry -Name 'SelectTransport' -Path $tsPolicy -Type 'DWord' -Value '<RemoveEntry>'
        Write-Status OK "H.264/AVC 444 preference removed (AVC444ModePreferred deleted; Windows default)."
        Write-Status OK "UDP transport policy removed (SelectTransport deleted; Windows default)."
        Write-Status WARNING "The revert takes effect only after Windows restarts."
    }

    if (-not $SubAction) {
        Write-Status ERROR "No subaction specified."
        Write-Status INFO "Options: status, on, off, h264-on, h264-off"
        return
    }

    switch ($SubAction.ToLower()) {
        'status'   { Show-RdpStatus }
        'on'       { Enable-Rdp }
        'off'      { Disable-Rdp }
        'h264-on'  { Enable-RdpH264 }
        'h264-off' { Disable-RdpH264 }
        default {
            Write-Status ERROR "Unknown subaction: '$SubAction'."
            Write-Status INFO "Options: status, on, off, h264-on, h264-off"
        }
    }
}
