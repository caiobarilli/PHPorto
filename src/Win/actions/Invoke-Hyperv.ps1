function Invoke-Hyperv {

    try {
        $vms = @(Get-VM -ErrorAction Stop)
    } catch {
        Write-Output (ConvertTo-Json -Compress -Depth 3 ([ordered]@{
            hyperv = $false
            motivo = (Get-PhportoHypervMotivo)
            erro   = [string]$_.Exception.Message
            vms    = @()
        }))
        return
    }

    $lista = New-Object System.Collections.Generic.List[object]

    foreach ($vm in $vms) {
        $rodando = ([string]$vm.State -eq 'Running')

        $ips = @()
        if ($rodando) {
            try {
                $ips = @(
                    Get-VMNetworkAdapter -VMName $vm.Name -ErrorAction Stop |
                        ForEach-Object { $_.IPAddresses } |
                        Where-Object { $_ }
                )
            } catch {
                $ips = @()
            }
        }

        $lista.Add([ordered]@{
            id            = [string]$vm.VMId
            name          = [string]$vm.Name
            state         = [string]$vm.State
            running       = $rodando
            memoryBytes   = [int64]$vm.MemoryAssigned
            uptimeSeconds = [int64][math]::Floor($vm.Uptime.TotalSeconds)
            ip            = @($ips)
        })
    }

    Write-Output (ConvertTo-Json -Compress -Depth 5 ([ordered]@{
        hyperv = $true
        vms    = @($lista.ToArray())
    }))
}

function Get-PhportoHypervMotivo {
    try {
        $f = Get-CimInstance -ClassName Win32_OptionalFeature -Filter "Name='Microsoft-Hyper-V'" -ErrorAction Stop
        if ($null -eq $f -or [int]$f.InstallState -ne 1) { return 'recurso-desligado' }
    } catch {
        return 'erro'
    }

    try {
        $s = Get-Service -Name 'vmms' -ErrorAction Stop
        if ([string]$s.Status -ne 'Running') { return 'servico-parado' }
    } catch {
        return 'erro'
    }

    return 'erro'
}
