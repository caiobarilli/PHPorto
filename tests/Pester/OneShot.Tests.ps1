#Requires -Version 5.1
<#
.SYNOPSIS
    Testes do stub do uso unico: o -EncodedCommand que o OneShot.php monta.

.DESCRIPTION
    O STUB E' A PECA QUE RODA ELEVADA ANTES DE TUDO, e e' texto gerado pelo
    PHP. Estes testes rodam um stub com o MESMO texto (copiado de
    OneShot::stub, e o teste de PHP confere a forma dele) num powershell de
    verdade, SEM elevacao:

      hash errado          -> sai 97 sem rodar nada;
      hash certo           -> roda o worker.ps1, que recusa por falta de
                              Administrador (exit 1) e nao toca no pedido;
      sonda no lugar do    -> prova o que o worker precisa do stub: os dois
      worker                  parametros chegam, o $script: de dentro das
                              funcoes e' o mesmo escopo do codigo de cima
                              (por isso dot-source, e nao &), o BOM sai, e o
                              exit de dentro vira o codigo do processo.

    O QUE FICA DE FORA: o prompt de UAC e o worker elevado de verdade. Isso
    so se confere no Windows, a mao (ver docs/seguranca.md).
#>

BeforeAll {
    $Script:RaizProjeto = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $Script:Worker      = Join-Path $Script:RaizProjeto 'src\Win\worker.ps1'
    $Script:Exe         = (Get-Process -Id $PID).Path

    $Script:Trabalho = Join-Path ([System.IO.Path]::GetTempPath()) ('phporto-uma-' + [guid]::NewGuid().ToString('N'))
    New-Item -ItemType Directory -Path $Script:Trabalho -Force | Out-Null

    function ConvertTo-Lit([string]$s) { "'" + $s.Replace("'", "''") + "'" }

    # O mesmo texto de OneShot::stub. Se um mudar, o outro tem de mudar junto.
    function New-Stub([string]$worker, [string]$workerSha, [string]$pedido, [string]$pedidoSha) {
        '$b=[IO.File]::ReadAllBytes(' + (ConvertTo-Lit $worker) + ');' +
        'if((Get-FileHash -InputStream ([IO.MemoryStream]::new($b)) -Algorithm SHA256).Hash -ne ' +
        (ConvertTo-Lit $workerSha) + '){exit 97};' +
        '$i=if($b[0] -eq 239){3}else{0};' +
        '. ([scriptblock]::Create([Text.Encoding]::UTF8.GetString($b, $i, $b.Length - $i)))' +
        ' -Pedido ' + (ConvertTo-Lit $pedido) +
        ' -PedidoSha256 ' + (ConvertTo-Lit $pedidoSha)
    }

    function Invoke-Stub([string]$stub) {
        $b64 = [Convert]::ToBase64String([System.Text.Encoding]::Unicode.GetBytes($stub))
        $saida = & $Script:Exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -EncodedCommand $b64 2>&1
        return [PSCustomObject]@{ Exit = $LASTEXITCODE; Saida = ($saida | Out-String) }
    }

    function Get-Sha([string]$caminho) { (Get-FileHash -LiteralPath $caminho -Algorithm SHA256).Hash.ToLowerInvariant() }

    # Uma copia do worker numa pasta com espaco e apostrofo no nome: o stub
    # recebe o caminho como literal, e um caminho assim nao pode partir nada.
    $Script:PastaW = Join-Path $Script:Trabalho "src it's Win"
    New-Item -ItemType Directory -Path $Script:PastaW | Out-Null
    $Script:CopiaW = Join-Path $Script:PastaW 'worker.ps1'
    Copy-Item -LiteralPath $Script:Worker -Destination $Script:CopiaW

    $Script:Pedido = Join-Path $Script:Trabalho 'win-oneshot-abc000000001.json'
    [System.IO.File]::WriteAllText($Script:Pedido, '{"v":1}', [System.Text.UTF8Encoding]::new($false))
}

AfterAll {
    Remove-Item -LiteralPath $Script:Trabalho -Recurse -Force -ErrorAction SilentlyContinue
}

Describe 'uso unico - o stub' {

    It 'worker.ps1 trocado depois do clique: sai 97 e nao roda nada' {
        $r = Invoke-Stub (New-Stub $Script:CopiaW ('00' * 32) $Script:Pedido (Get-Sha $Script:Pedido))
        $r.Exit | Should -Be 97
        Test-Path -LiteralPath $Script:Pedido | Should -BeTrue
    }

    It 'hash certo: roda o worker, que recusa sem Administrador e nao toca no pedido' {
        $r = Invoke-Stub (New-Stub $Script:CopiaW (Get-Sha $Script:CopiaW) $Script:Pedido (Get-Sha $Script:Pedido))
        $r.Exit | Should -Be 1
        Test-Path -LiteralPath $Script:Pedido | Should -BeTrue
        $r.Saida | Should -Not -Match 'ParameterBinding|parameter set|conjunto de par'
    }

    Context 'com uma sonda no lugar do worker' {

        BeforeAll {
            # A sonda imita o que o worker faz de delicado: CmdletBinding com
            # dois conjuntos, $PSCmdlet, $Dir tirado do pedido, uma funcao
            # gravando $script:filho e o codigo de cima lendo $filho, e o exit
            # do resultado de uma funcao.
            $Script:Resultado = Join-Path $Script:Trabalho 'sonda.txt'
            $sonda = @(
                "[CmdletBinding(DefaultParameterSetName = 'Laco')]"
                'param('
                "    [Parameter(Mandatory, ParameterSetName = 'Laco')] [string]`$Nonce,"
                "    [Parameter(Mandatory, ParameterSetName = 'UmaVez')] [string]`$Pedido,"
                "    [Parameter(Mandatory, ParameterSetName = 'UmaVez')] [string]`$PedidoSha256"
                ')'
                '$ErrorActionPreference = ''Stop'''
                'if ($PSCmdlet.ParameterSetName -eq ''UmaVez'') { $Dir = Split-Path -Parent $Pedido }'
                '$filho = $null'
                'function Set-Filho { $script:filho = ''X'' }'
                'function Invoke-UmaVez { Set-Filho; if ($null -ne $script:filho) { return 42 } return 1 }'
                ('[System.IO.File]::WriteAllText(' + (ConvertTo-Lit $Script:Resultado) + ', ($PSCmdlet.ParameterSetName + ''|'' + $Pedido + ''|'' + $PedidoSha256 + ''|'' + $Dir))')
                'Set-Filho'
                ('Add-Content -LiteralPath ' + (ConvertTo-Lit $Script:Resultado) + ' -Value (''|filho='' + $filho)')
                'exit (Invoke-UmaVez)'
            ) -join "`r`n"
            $Script:Sonda = Join-Path $Script:PastaW 'sonda.ps1'
            # Com BOM, como todo .ps1 do repositorio: o stub tem de tira-lo.
            [System.IO.File]::WriteAllText($Script:Sonda, $sonda, [System.Text.UTF8Encoding]::new($true))
            $Script:R = Invoke-Stub (New-Stub $Script:Sonda (Get-Sha $Script:Sonda) $Script:Pedido ('ab' * 32))
            $Script:Lido = if (Test-Path -LiteralPath $Script:Resultado) { Get-Content -Raw -LiteralPath $Script:Resultado } else { '' }
        }

        It 'o exit de dentro vira o codigo do processo' {
            $Script:R.Exit | Should -Be 42 -Because $Script:R.Saida
        }

        It 'os dois parametros chegam, no conjunto do uso unico, com $Dir tirado do pedido' {
            $Script:Lido | Should -Match ([regex]::Escape('UmaVez|' + $Script:Pedido + '|' + ('ab' * 32) + '|' + $Script:Trabalho))
        }

        It 'o $script: das funcoes e o mesmo escopo do codigo de cima (dot-source)' {
            $Script:Lido | Should -Match '\|filho=X'
        }
    }
}
