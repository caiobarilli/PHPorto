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

    if (@(Get-NetAdapter | Where-Object { $_.Status -eq 'Up' }).Count -eq 0) {
        Write-Status ERROR "No network adapter is up; nothing changed."
        return
    }

    Write-Status INFO "Applying DNS '$Provider'..."
    $falhas = @(Invoke-PhportoCapturingProblems { Set-WinUtilDNS -DNSProvider $Provider })

    if ($falhas.Count -gt 0) {
        foreach ($falha in $falhas) { Write-Status ERROR $falha }
        Write-Status ERROR "DNS '$Provider' finished with $($falhas.Count) problem(s); it may be applied to some adapters only."
        return
    }
    Write-Status OK "DNS '$Provider' applied."
}

<#
    Runs a script block and collects every warning and error it writes.

    Receives the script block. Returns the text of each warning, each
    non-terminating error and the terminating one, if any, in order;
    everything else it writes passes through unchanged.
#>
function Invoke-PhportoCapturingProblems {
    param([scriptblock]$Bloco)

    $problemas = @()
    try {
        & $Bloco 3>&1 2>&1 | ForEach-Object {
            if ($_ -is [System.Management.Automation.WarningRecord]) {
                if ($_.Message) { $problemas += $_.Message }
            } elseif ($_ -is [System.Management.Automation.ErrorRecord]) {
                $problemas += $_.Exception.Message
            } else {
                $_ | Out-Host
            }
        }
    } catch {
        $problemas += $_.Exception.Message
    }
    return $problemas
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
