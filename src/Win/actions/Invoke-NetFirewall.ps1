function Invoke-NetFirewall {
    param(
        [string]$SubAction,
        [string]$BindIp,
        [int]$Port,
        [string]$Allow,
        [string]$Program
    )

    $ruleName = 'PHPorto Rede'
    $group    = 'PHPorto Rede'
    $opcoes   = 'add, remove'

    function Test-NetFirewallBind {
        param([string]$Ip)
        $partes = $Ip -split '\.'
        if ($partes.Count -ne 4) { return $false }
        foreach ($p in $partes) {
            if ($p -notmatch '\A[0-9]{1,3}\z' -or [int]$p -gt 255) { return $false }
        }
        $a = [int]$partes[0]
        $b = [int]$partes[1]
        return ($a -eq 10) -or ($a -eq 172 -and $b -ge 16 -and $b -le 31) -or ($a -eq 192 -and $b -eq 168)
    }

    function Add-NetFirewallRegra {
        if (-not (Test-NetFirewallBind $BindIp)) {
            Write-Status ERROR "BindIp is not a private IPv4 address: '$BindIp'."
            return
        }
        if ($Port -lt 1 -or $Port -gt 65535) {
            Write-Status ERROR "Port out of range: $Port."
            return
        }
        if ([string]::IsNullOrWhiteSpace($Allow)) {
            Write-Status ERROR 'Allow is empty. Nothing was created.'
            return
        }
        if ([string]::IsNullOrWhiteSpace($Program) -or [IO.Path]::GetFileName($Program) -ne 'php.exe' -or -not (Test-Path -LiteralPath $Program -PathType Leaf)) {
            Write-Status ERROR "Program is not an existing php.exe: '$Program'."
            return
        }

        $remotos = @($Allow -split ',' | ForEach-Object { $_.Trim() } | Where-Object { $_ -ne '' })

        try {
            Get-NetFirewallRule -Group $group -ErrorAction SilentlyContinue | Remove-NetFirewallRule -ErrorAction Stop
            New-NetFirewallRule -DisplayName $ruleName -Group $group -Direction Inbound -Action Allow -Protocol TCP `
                -Profile Private -LocalAddress $BindIp -LocalPort $Port -RemoteAddress $remotos -Program $Program | Out-Null
            Write-Status OK "Firewall rule created: '$ruleName' (TCP $Port inbound, Private profile, local $BindIp)."
        } catch {
            Write-Status ERROR "Failed to create firewall rule: $($_.Exception.Message)"
        }
    }

    function Remove-NetFirewallRegra {
        try {
            $existentes = @(Get-NetFirewallRule -Group $group -ErrorAction SilentlyContinue)
            if ($existentes.Count -eq 0) {
                Write-Status OK "No firewall rule '$ruleName' to remove."
                return
            }
            $existentes | Remove-NetFirewallRule -ErrorAction Stop
            Write-Status OK "Firewall rule removed: '$ruleName'."
        } catch {
            Write-Status ERROR "Failed to remove firewall rule: $($_.Exception.Message)"
        }
    }

    if (-not $SubAction) {
        Write-Status ERROR "SubAction required. Options: $opcoes"
        return
    }

    switch ($SubAction) {
        'add'    { Add-NetFirewallRegra }
        'remove' { Remove-NetFirewallRegra }
        default  { Write-Status ERROR "Unknown subaction '$SubAction'. Options: $opcoes" }
    }
}
