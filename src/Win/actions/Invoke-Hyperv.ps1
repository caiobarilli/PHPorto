function Invoke-Hyperv {
    # SO LEITURA nesta fatia: Get-VM e Get-VMNetworkAdapter nao mexem em VM
    # nenhuma. Nada de ligar, desligar, criar ou apagar. A saida e' um JSON
    # unico, que o lado PHP le e vira tabela.

    try {
        $vms = @(Get-VM -ErrorAction Stop)
    } catch {
        # Get-VM so chega aqui elevado (o worker recusa subir sem elevacao). O
        # que falhou nao e' sempre "Hyper-V desligado": o servico vmms parado,
        # o modulo quebrado ou um erro WMI caem no mesmo catch. Por isso a
        # saida leva um motivo fechado, e a tela so manda reiniciar quando o
        # recurso esta mesmo desligado.
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
        # Comparacao por texto, nao pelo enum VMState: o [string] da o mesmo
        # 'Running' venha do enum de verdade ou de um substituto de teste, e o
        # arquivo nao depende do tipo do modulo estar carregado.
        $rodando = ([string]$vm.State -eq 'Running')

        $ips = @()
        if ($rodando) {
            # So faz sentido perguntar o IP de quem esta rodando. Ler pode
            # falhar (adaptador sem servicos de integracao reportando), e ai a
            # lista fica vazia, e o lado PHP diz que nao deu, sem chutar.
            try {
                # Pelo NOME, nao pelo objeto VM: -VMName aceita string, e um
                # nome nao amarra este arquivo ao tipo VirtualMachine do modulo
                # Hyper-V, e ainda deixa a acao testavel com um substituto.
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
    # So leitura, e cada sonda pode falhar sozinha: na duvida o motivo e'
    # 'erro', que a tela mostra com a mensagem, sem mandar reiniciar nada.
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
