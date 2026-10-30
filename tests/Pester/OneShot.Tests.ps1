#Requires -Version 5.1

BeforeAll {
    $Script:RaizProjeto = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $Script:Worker      = Join-Path $Script:RaizProjeto 'src\Win\worker.ps1'
    $Script:Exe         = (Get-Process -Id $PID).Path

    $Script:Trabalho = Join-Path ([System.IO.Path]::GetTempPath()) ('phporto-uma-' + [guid]::NewGuid().ToString('N'))
    New-Item -ItemType Directory -Path $Script:Trabalho -Force | Out-Null

    function ConvertTo-Lit([string]$s) { "'" + $s.Replace("'", "''") + "'" }

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
        if ($env:OS -eq 'Windows_NT' -and ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
            Set-ItResult -Skipped -Because 'o host ja roda como Administrador'
            return
        }
        $r = Invoke-Stub (New-Stub $Script:CopiaW (Get-Sha $Script:CopiaW) $Script:Pedido (Get-Sha $Script:Pedido))
        $r.Exit | Should -Be 1
        Test-Path -LiteralPath $Script:Pedido | Should -BeTrue
        $r.Saida | Should -Not -Match 'ParameterBinding|parameter set|conjunto de par'
    }

    Context 'com uma sonda no lugar do worker' {

        BeforeAll {
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
