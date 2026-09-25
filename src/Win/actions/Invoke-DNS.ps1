function Invoke-DNS {
    param(
        [string]$Provider,
        [string]$PrimaryDNS,
        [string]$SecondaryDNS
    )

    if (-not $Provider) {
        Write-Status ERROR "Specify the provider with -Provider (e.g.: cloudflare, google, quad9)."
        return
    }

    # access is case-insensitive
    $valid = @($sync.configs.dns.PSObject.Properties.Name)
    if ($Provider -notin $valid -and $Provider -notin @('Default', 'DHCP')) {
        Write-Status ERROR "Provider '$Provider' does not exist in dns.json."
        Write-Status INFO  "Available: $($valid -join ', ')"
        return
    }

    if ($Provider -ieq 'custom') {
        Set-PhportoCustomDNS -PrimaryDNS $PrimaryDNS -SecondaryDNS $SecondaryDNS
        return
    }

    Write-Status INFO "Applying DNS '$Provider'..."
    try {
        Set-WinUtilDNS -DNSProvider $Provider
        Write-Status OK "DNS '$Provider' applied."
    } catch {
        Write-Status ERROR $_.Exception.Message
    }
}

<#
    Sets the given DNS servers on every network adapter that is up.

    Receives the primary server (required) and the secondary (optional).
    Returns nothing; writes one OK or ERROR line per adapter and a summary.
#>
function Set-PhportoCustomDNS {
    param(
        [string]$PrimaryDNS,
        [string]$SecondaryDNS
    )

    if (-not $PrimaryDNS) {
        Write-Status ERROR "Provider 'custom' requires -PrimaryDNS to be specified."
        return
    }

    $servidores = @(@($PrimaryDNS, $SecondaryDNS) | Where-Object { $_ })
    $adaptadores = @(Get-NetAdapter | Where-Object { $_.Status -eq 'Up' })

    if ($adaptadores.Count -eq 0) {
        Write-Status ERROR "No network adapter is up; nothing changed."
        return
    }

    Write-Status INFO "Applying custom DNS ($($servidores -join ', ')) to $($adaptadores.Count) adapter(s)..."
    $falhas = @()
    foreach ($adaptador in $adaptadores) {
        try {
            Set-DnsClientServerAddress -InterfaceIndex $adaptador.ifIndex -ServerAddresses $servidores -ErrorAction Stop
            Write-Status OK $adaptador.Name
        } catch {
            Write-Status ERROR "$($adaptador.Name) -> $($_.Exception.Message)"
            $falhas += $adaptador.Name
        }
    }

    if ($falhas.Count -gt 0) {
        Write-Status ERROR "Custom DNS finished with $($falhas.Count) error(s): $($falhas -join ', ')"
        return
    }
    Write-Status OK "DNS 'Custom' applied."
}
