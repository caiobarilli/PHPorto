function Invoke-HypervVm {
    param(
        [string]$SubAction,
        [string]$VmId
    )

    # Liga, desliga ou reinicia UMA VM, achada pelo VMId (GUID) e nunca pelo
    # nome: um nome como '*' pegaria todas, e o Hyper-V aceita duas VMs com o
    # mesmo nome. O worker ja conferiu os dois parametros pela allowlist; a
    # conferencia aqui repete a dele para quem carregar a acao na mao.
    #
    # O estado e' lido de novo AQUI, na hora, antes de agir: a tela mostra o
    # que leu ha pouco, e a VM pode ter mudado no meio. O que nao cabe no
    # estado atual e' recusado com motivo, sem tocar na VM.
    #
    # A saida e' um JSON unico, como a do Invoke-Hyperv. Recusa e falha saem
    # com ok = false e exit 1.

    $resultado = [ordered]@{
        ok        = $false
        subAction = [string]$SubAction
        vmId      = [string]$VmId
        name      = ''
        antes     = ''
        depois    = ''
        metodo    = ''
        motivo    = ''
        mensagem  = ''
    }

    if ($SubAction -cnotin 'start', 'stop', 'restart') {
        $resultado.motivo   = 'subacao-invalida'
        $resultado.mensagem = 'Subacao invalida: use start, stop ou restart.'
        return (Write-PhportoHypervVmResultado $resultado)
    }

    if ($VmId -cnotmatch '\A[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\z') {
        $resultado.motivo   = 'id-invalido'
        $resultado.mensagem = 'O identificador da VM nao e um GUID valido.'
        return (Write-PhportoHypervVmResultado $resultado)
    }

    try {
        $vm = @(Get-VM -Id ([guid]$VmId) -ErrorAction Stop) | Select-Object -First 1
    } catch {
        $vm = $null
        $resultado.erro = [string]$_.Exception.Message
    }

    if ($null -eq $vm) {
        $resultado.motivo   = 'vm-nao-encontrada'
        $resultado.mensagem = 'Nenhuma VM com esse identificador. Atualize a lista e tente de novo.'
        return (Write-PhportoHypervVmResultado $resultado)
    }

    $resultado.name  = [string]$vm.Name
    $resultado.antes = [string]$vm.State

    # Comparacao por texto, como no Invoke-Hyperv: o [string] do enum VMState
    # e o de um substituto de teste dao o mesmo 'Running'.
    $plano = Get-PhportoHypervVmPlano $SubAction $resultado.antes

    if ($null -eq $plano.metodo) {
        $resultado.motivo   = $plano.motivo
        $resultado.mensagem = $plano.mensagem
        return (Write-PhportoHypervVmResultado $resultado)
    }

    $resultado.metodo = $plano.metodo

    try {
        # Sempre sem pergunta: o filho roda -NonInteractive, e um prompt de
        # confirmacao ali vira erro que nao explica nada.
        switch ($plano.metodo) {
            'Start-VM'  { Start-VM -VM $vm -Confirm:$false -ErrorAction Stop | Out-Null }
            'Resume-VM' { Resume-VM -VM $vm -Confirm:$false -ErrorAction Stop | Out-Null }

            # -Force pede o desligamento pelo servico de integracao e nao
            # pergunta. NAO e' o TurnOff, que seria tirar da tomada.
            'Stop-VM'   { Stop-VM -VM $vm -Force -Confirm:$false -ErrorAction Stop | Out-Null }

            # -Type Reboot reinicia pelo sistema da VM. Sem o parametro (Hyper-V
            # antigo), o Restart-VM so sabe reset, que e' o reinicio bruto.
            'Restart-VM -Type Reboot' { Restart-VM -VM $vm -Type Reboot -Force -Confirm:$false -ErrorAction Stop | Out-Null }
            'Restart-VM -Force'       { Restart-VM -VM $vm -Force -Confirm:$false -ErrorAction Stop | Out-Null }
        }
    } catch {
        $resultado.motivo   = 'falhou'
        $resultado.mensagem = 'O Hyper-V recusou: ' + [string]$_.Exception.Message
        $resultado.depois   = Get-PhportoHypervVmEstado $VmId
        return (Write-PhportoHypervVmResultado $resultado)
    }

    $resultado.ok       = $true
    $resultado.depois   = Get-PhportoHypervVmEstado $VmId
    $resultado.mensagem = $plano.mensagem
    return (Write-PhportoHypervVmResultado $resultado)
}

function Get-PhportoHypervVmPlano([string]$SubAction, [string]$Estado) {
    # Os estados parados que o Start-VM liga. Salva, rapida ou hibernada, a VM
    # volta de onde parou; o Stop jogaria esse estado fora, e por isso fica
    # recusado nelas.
    $parados = @('Off', 'Saved', 'FastSaved', 'Hibernated')

    switch ($SubAction) {
        'start' {
            if ($Estado -in $parados)    { return @{ metodo = 'Start-VM';  motivo = ''; mensagem = 'VM ligando.' } }
            if ($Estado -eq 'Paused')    { return @{ metodo = 'Resume-VM'; motivo = ''; mensagem = 'VM retomada.' } }
            if ($Estado -eq 'Running')   { return @{ metodo = $null; motivo = 'ja-ligada'; mensagem = 'A VM ja esta ligada.' } }
        }
        'stop' {
            if ($Estado -eq 'Running')   { return @{ metodo = 'Stop-VM'; motivo = ''; mensagem = 'Desligamento pedido ao sistema da VM.' } }
            if ($Estado -eq 'Off')       { return @{ metodo = $null; motivo = 'ja-desligada'; mensagem = 'A VM ja esta desligada.' } }
            if ($Estado -in $parados)    { return @{ metodo = $null; motivo = 'estado-salvo'; mensagem = "A VM esta com o estado salvo ($Estado). Desligar jogaria esse estado fora: ligue antes." } }
            if ($Estado -eq 'Paused')    { return @{ metodo = $null; motivo = 'pausada'; mensagem = 'A VM esta pausada. Retome antes de desligar.' } }
        }
        'restart' {
            if ($Estado -eq 'Running') {
                if (Test-PhportoRestartVmTemTipo) {
                    return @{ metodo = 'Restart-VM -Type Reboot'; motivo = ''; mensagem = 'Reinicio pedido ao sistema da VM.' }
                }
                return @{ metodo = 'Restart-VM -Force'; motivo = ''; mensagem = 'Reinicio por reset: este Hyper-V nao tem o reinicio pelo sistema da VM.' }
            }
            if ($Estado -in $parados -or $Estado -eq 'Paused') { return @{ metodo = $null; motivo = 'nao-ligada'; mensagem = "A VM nao esta ligada ($Estado). So da para reiniciar uma VM ligada." } }
        }
    }

    # O que sobra e' estado de passagem (Starting, Stopping, Saving, Pausing,
    # Resuming, Reset...) ou um que esta acao nao conhece. Mexer no meio de
    # uma transicao e' pedir erro do Hyper-V, entao a resposta e' esperar.
    return @{ metodo = $null; motivo = 'em-transicao'; mensagem = "A VM esta mudando de estado ($Estado). Espere terminar e atualize a lista." }
}

function Test-PhportoRestartVmTemTipo {
    $cmd = Get-Command -Name 'Restart-VM' -ErrorAction SilentlyContinue
    if ($null -eq $cmd -or $null -eq $cmd.Parameters) { return $false }
    return [bool]$cmd.Parameters.ContainsKey('Type')
}

function Get-PhportoHypervVmEstado([string]$VmId) {
    # Leitura de depois, so informativa: falhar aqui nao muda o resultado da
    # acao, que ja aconteceu ou ja foi recusada.
    try {
        $vm = @(Get-VM -Id ([guid]$VmId) -ErrorAction Stop) | Select-Object -First 1
        if ($null -ne $vm) { return [string]$vm.State }
    } catch {
    }
    return ''
}

function Write-PhportoHypervVmResultado($Resultado) {
    $global:LASTEXITCODE = if ($Resultado.ok) { 0 } else { 1 }
    Write-Output (ConvertTo-Json -Compress -Depth 3 $Resultado)
}
