#Requires -Version 5.1
<#
.SYNOPSIS
    Testes do src/Win/bootstrap.ps1 — o ambiente que as acoes assumem pronto.

.DESCRIPTION
    ESTES TESTES RODAM SEM ELEVACAO, e e' de proposito: o bootstrap so checa
    Administrador no despachante, nao no carregamento, justamente para poder
    ser testado num terminal comum. A recusa por falta de elevacao e' um dos
    casos cobertos aqui.

    O QUE ELES COBREM: a arvore, o encoding de cada .ps1, o que veio do upstream
    intocado, o ambiente ($root, $sync.configs) e o despachante. O comportamento
    de cada acao e' testado nos arquivos ao lado, um por acao.
#>

BeforeAll {
    $Script:RaizProjeto = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $Script:PastaWin    = Join-Path $Script:RaizProjeto 'src\Win'
    $Script:Bootstrap   = Join-Path $Script:PastaWin 'bootstrap.ps1'

    . $Script:Bootstrap
}

# ==============================================================
# ARQUIVOS E ENCODING
# ==============================================================
Describe 'bootstrap - arvore e encoding' {

    It 'bootstrap.ps1 existe em src/Win' {
        Test-Path $Script:Bootstrap | Should -BeTrue
    }

    It 'bootstrap.ps1 nao tem erro de sintaxe' {
        $erros = $null
        [System.Management.Automation.Language.Parser]::ParseFile(
            $Script:Bootstrap, [ref]$null, [ref]$erros
        ) | Out-Null
        $erros.Count | Should -Be 0
    }

    It 'bootstrap.ps1 tem BOM UTF-8 — sem ele o 5.1 le como ANSI' {
        $bytes = [System.IO.File]::ReadAllBytes($Script:Bootstrap)
        $bytes[0] | Should -Be 0xEF
        $bytes[1] | Should -Be 0xBB
        $bytes[2] | Should -Be 0xBF
    }

    It 'bootstrap.ps1 tem CRLF' {
        $texto = [System.IO.File]::ReadAllText($Script:Bootstrap)
        $texto | Should -Match "`r`n"
        # Nenhum LF solto: todo \n precisa vir precedido de \r.
        [regex]::Matches($texto, "(?<!`r)`n").Count | Should -Be 0
    }

    It 'as pastas da carga existem: <_>' -ForEach @('lib', 'config', 'actions', 'audit', 'tools') {
        Test-Path (Join-Path $Script:PastaWin $_) | Should -BeTrue
    }

    It 'a licenca do upstream viajou junto' {
        $licenca = Join-Path $Script:PastaWin 'LICENSE.winutil'
        Test-Path $licenca | Should -BeTrue
        $texto = Get-Content -Path $licenca -Raw
        $texto | Should -Match 'MIT License'
        $texto | Should -Match 'CT Tech Group LLC'
    }

    It 'o THIRD-PARTY.md existe e aponta para a licenca' {
        $notice = Join-Path $Script:PastaWin 'THIRD-PARTY.md'
        Test-Path $notice | Should -BeTrue
        (Get-Content -Path $notice -Raw) | Should -Match 'LICENSE\.winutil'
    }
}

# ==============================================================
# ARQUIVOS COPIADOS — intocados
# ==============================================================
Describe 'bootstrap - o que veio do upstream' {

    It 'lib/ tem as nove primitivas vivas, e so elas' {
        $nomes = @(Get-ChildItem -Path (Join-Path $Script:PastaWin 'lib') -Filter '*.ps1' -File |
            Select-Object -ExpandProperty BaseName | Sort-Object)

        $nomes | Should -Be @(
            'Install-WinUtilProgramWinget'
            'Install-WinUtilWinget'
            'Invoke-WinUtilScript'
            'Invoke-WinUtilTweaks'
            'Remove-WinUtilAPPX'
            'Set-WinUtilDNS'
            'Set-WinUtilRegistry'
            'Set-WinUtilService'
            'Test-WinUtilPackageManager'
        )
    }

    It 'os arquivos copiados sao ASCII puro — por isso nao levam BOM' {
        $suspeitos = @()

        Get-ChildItem -Path (Join-Path $Script:PastaWin 'lib') -Filter '*.ps1' -File |
            ForEach-Object {
                $bytes = [System.IO.File]::ReadAllBytes($_.FullName)
                if ($bytes | Where-Object { $_ -gt 0x7F }) { $suspeitos += $_.Name }
            }

        $suspeitos | Should -BeNullOrEmpty -Because 'byte acima de 0x7F sem BOM vira lixo no PS 5.1'
    }

    It 'a funcao <_> foi carregada de lib/' -ForEach @(
        'Set-WinUtilService'
        'Set-WinUtilRegistry'
        'Invoke-WinUtilScript'
        'Invoke-WinUtilTweaks'
        'Remove-WinUtilAPPX'
        'Set-WinUtilDNS'
        'Install-WinUtilWinget'
        'Install-WinUtilProgramWinget'
        'Test-WinUtilPackageManager'
    ) {
        Get-Command -Name $_ -CommandType Function -ErrorAction SilentlyContinue |
            Should -Not -BeNullOrEmpty
    }
}

# ==============================================================
# ACTIONS — as treze, e o tripwire de encoding
# ==============================================================
Describe 'bootstrap - as acoes migradas' {

    It 'actions/ tem as treze, e so elas' {
        $nomes = @(Get-ChildItem -Path (Join-Path $Script:PastaWin 'actions') -Filter 'Invoke-*.ps1' -File |
            Select-Object -ExpandProperty BaseName | Sort-Object)

        $nomes.Count | Should -Be 13
        # Ordem do Sort-Object, que ignora caixa: Debloat antes de DNS, Gdid
        # antes de GPU.
        $nomes | Should -Be @(
            'Invoke-Audit', 'Invoke-Debloat', 'Invoke-DNS', 'Invoke-Exporter',
            'Invoke-Gdid', 'Invoke-GPU', 'Invoke-Install', 'Invoke-Memory',
            'Invoke-Network', 'Invoke-Optimize', 'Invoke-Performance',
            'Invoke-Processes', 'Invoke-Tweaks'
        )
    }

    <#
        O TRIPWIRE DA REGRA DE ENCODING.

        Invoke-Gdid.ps1 e Invoke-Optimize.ps1 tem bytes acima de 0x7F e nao tem
        BOM (ver THIRD-PARTY.md). Hoje isso e' inofensivo: os bytes estao so em
        comentario. Se alguem escrever um travessao dentro de string num
        arquivo sem BOM, o 5.1 le o 0x94 como aspa de fechamento e o parser
        quebra — e ESTE teste e' quem avisa, apontando o arquivo.
    #>
    It 'todo Invoke-*.ps1 de actions/ parseia sem erro' {
        $ruins = @()

        Get-ChildItem -Path (Join-Path $Script:PastaWin 'actions') -Filter 'Invoke-*.ps1' -File |
            ForEach-Object {
                $erros = $null
                [System.Management.Automation.Language.Parser]::ParseFile(
                    $_.FullName, [ref]$null, [ref]$erros
                ) | Out-Null
                if ($erros.Count -gt 0) { $ruins += "$($_.Name): $($erros[0].Message)" }
            }

        $ruins | Should -BeNullOrEmpty
    }

    It 'so dois arquivos de actions/ carregam byte alto sem BOM — e sao os conhecidos' {
        $semBom = @()

        Get-ChildItem -Path (Join-Path $Script:PastaWin 'actions') -Filter 'Invoke-*.ps1' -File |
            ForEach-Object {
                $bytes = [System.IO.File]::ReadAllBytes($_.FullName)
                $temBom = ($bytes.Length -ge 3 -and $bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF)
                $temAlto = [bool]($bytes | Where-Object { $_ -gt 0x7F })
                if ($temAlto -and -not $temBom) { $semBom += $_.BaseName }
            }

        @($semBom | Sort-Object) | Should -Be @('Invoke-Gdid', 'Invoke-Optimize')
    }

    It 'nenhuma acao pergunta: Read-Host nao existe mais em actions/' {
        $culpados = @()

        Get-ChildItem -Path (Join-Path $Script:PastaWin 'actions') -Filter 'Invoke-*.ps1' -File |
            ForEach-Object {
                if ((Get-Content -Path $_.FullName -Raw) -match 'Read-Host') { $culpados += $_.Name }
            }

        $culpados | Should -BeNullOrEmpty -Because 'no worker -NonInteractive nao ha quem digite'
    }
}

# ==============================================================
# WRITE-STATUS
# ==============================================================
Describe 'bootstrap - Write-Status' {

    It 'Write-Status esta disponivel' {
        Get-Command -Name 'Write-Status' -CommandType Function -ErrorAction SilentlyContinue |
            Should -Not -BeNullOrEmpty
    }

    It 'escreve a etiqueta de <Nivel>' -ForEach @(
        @{ Nivel = 'OK';      Etiqueta = '\[ OK \]' }
        @{ Nivel = 'ERROR';   Etiqueta = '\[ ERROR \]' }
        @{ Nivel = 'WARNING'; Etiqueta = '\[ WARNING \]' }
        @{ Nivel = 'INFO';    Etiqueta = '\[ INFO \]' }
    ) {
        $saida = (Write-Status $Nivel 'mensagem') 6>&1 | Out-String
        $saida | Should -Match $Etiqueta
        $saida | Should -Match 'mensagem'
    }

    It 'recusa nivel fora do conjunto' {
        { Write-Status 'FATAL' 'x' } | Should -Throw
    }
}

# ==============================================================
# AMBIENTE — $root e $sync
# ==============================================================
Describe 'bootstrap - o ambiente que as acoes assumem' {

    It '$root aponta para src/Win — e o Invoke-Audit depende disso' {
        $global:root | Should -Be $Script:PastaWin
        Test-Path (Join-Path $global:root 'audit\audit.ps1') | Should -BeTrue
    }

    It '$root tem a pasta tools — e o Invoke-Memory depende disso' {
        Test-Path (Join-Path $global:root 'tools') | Should -BeTrue
    }

    It '$sync.configs tem as tres chaves, e nao as cinco do winutil-cli' {
        $chaves = @($global:sync.configs.Keys | Sort-Object)
        $chaves | Should -Be @('dns', 'preset', 'tweaks')
    }

    It 'dns.json carregou com os providers que a WinAction espelha' {
        $global:sync.configs.dns.Cloudflare.Primary | Should -Not -BeNullOrEmpty
        $global:sync.configs.dns.Google.Primary     | Should -Not -BeNullOrEmpty
    }

    It 'preset.json carregou com os tres presets do menu' {
        foreach ($p in 'Standard', 'Minimal', 'Advanced') {
            $global:sync.configs.preset.$p | Should -Not -BeNullOrEmpty
        }
    }

    <#
        BURACO CONHECIDO NOS DADOS DO UPSTREAM, achado ao migrar e deixado como
        estava: 'WPFTweaksDVR' e' citado pelos presets Standard e Advanced e NAO
        EXISTE em tweaks.json. Conferido no winutil-cli antes da copia — chegou
        assim, os arquivos aqui sao byte a byte iguais (hashes no
        THIRD-PARTY.md).

        O efeito em producao e' silencioso e por isso merece teste: o
        Invoke-WinUtilTweaks nao acha service, registry nem script, pula as tres
        pontas sem reclamar, e o Invoke-Tweaks escreve "[ OK ] WPFTweaksDVR" —
        um sucesso relatado para um tweak que nao aconteceu.

        Nao foi corrigido aqui de proposito: consertar dado do upstream dentro
        do commit que so muda endereco e' misturar duas mudancas, e quebraria a
        comparacao byte a byte que da sentido ao THIRD-PARTY.md.

        Os dois testes abaixo se sustentam: um garante que TODO o resto resolve,
        o outro fixa o buraco. Se o dado for corrigido um dia, o segundo falha e
        avisa que a excecao do primeiro pode sair.
    #>

    It 'todo tweak citado pelos presets existe em tweaks.json, fora o buraco conhecido' {
        $conhecido = @('WPFTweaksDVR')
        $faltando  = @()

        foreach ($preset in 'Standard', 'Minimal', 'Advanced') {
            foreach ($tweak in $global:sync.configs.preset.$preset) {
                if ($tweak -in $conhecido) { continue }
                if (-not $global:sync.configs.tweaks.$tweak) {
                    $faltando += "$preset -> $tweak"
                }
            }
        }

        $faltando | Should -BeNullOrEmpty
    }

    It 'WPFTweaksDVR continua ausente de tweaks.json — o buraco herdado' {
        $global:sync.configs.preset.Standard | Should -Contain 'WPFTweaksDVR'
        $global:sync.configs.tweaks.WPFTweaksDVR |
            Should -BeNullOrEmpty -Because 'se isto passar a existir, tire a excecao do teste acima'
    }
}

# ==============================================================
# DESPACHANTE
# ==============================================================
Describe 'bootstrap - despachante' {

    It 'Invoke-PhportoWinAction esta disponivel' {
        Get-Command -Name 'Invoke-PhportoWinAction' -CommandType Function -ErrorAction SilentlyContinue |
            Should -Not -BeNullOrEmpty
    }

    It 'o mapa conhece as treze acoes' {
        $chaves = @($global:PhportoWinActions.Keys | Sort-Object)
        $chaves | Should -Be @(
            'audit', 'debloat', 'dns', 'exporter', 'gdid', 'gpu', 'install',
            'memory', 'network', 'optimize', 'performance', 'processes', 'tweaks'
        )
    }

    It 'o mapa nao e transformacao de texto: <Acao> vira <Funcao>' -ForEach @(
        @{ Acao = 'dns';  Funcao = 'Invoke-DNS' }
        @{ Acao = 'gpu';  Funcao = 'Invoke-GPU' }
        @{ Acao = 'gdid'; Funcao = 'Invoke-Gdid' }
    ) {
        $global:PhportoWinActions[$Acao] | Should -Be $Funcao
    }

    It 'recusa acao fora do mapa, dizendo quais existem' {
        Mock Test-PhportoElevado { $true }
        $saida = { Invoke-PhportoWinAction -Action 'rm-rf' } | Should -Throw -PassThru
        $saida.Exception.Message | Should -Match 'acao desconhecida'
    }

    It 'toda acao do mapa tem funcao carregada de actions/' {
        $orfas = @()

        foreach ($acao in $global:PhportoWinActions.Keys) {
            $funcao = $global:PhportoWinActions[$acao]
            if (-not (Get-Command -Name $funcao -CommandType Function -ErrorAction SilentlyContinue)) {
                $orfas += "$acao -> $funcao"
            }
        }

        $orfas | Should -BeNullOrEmpty
    }

    It 'recusa acao mapeada cuja funcao nao foi carregada' {
        Mock Test-PhportoElevado { $true }

        # Entrada fantasma: com actions/ cheia, este e' o unico jeito de
        # exercitar o ramo — e ele existe para o dia em que um arquivo de
        # actions/ sumir sem o mapa saber.
        $global:PhportoWinActions['fantasma'] = 'Invoke-Fantasma'
        try {
            { Invoke-PhportoWinAction -Action 'fantasma' } |
                Should -Throw -ExpectedMessage 'PHPorto: funcao ausente*'
        } finally {
            $global:PhportoWinActions.Remove('fantasma')
        }
    }

    It 'despacha de verdade: processes escreve a tabela' {
        Mock Test-PhportoElevado { $true }
        $saida = (Invoke-PhportoWinAction -Action 'processes') 6>&1 | Out-String
        $saida | Should -Match 'PROCESSES \(Top 30 by RAM\)'
    }

    It 'os parametros entram por splatting, switch inclusive' {
        Mock Test-PhportoElevado { $true }
        Mock Invoke-Optimize { }

        Invoke-PhportoWinAction -Action 'optimize' -Params @{ Preset = 'ssh'; Kill = 'notepad'; Undo = $true }

        Should -Invoke -CommandName Invoke-Optimize -Times 1 -ParameterFilter {
            $Preset -eq 'ssh' -and $Kill -eq 'notepad' -and $Undo
        }
    }

    It 'recusa sem elevacao ANTES de olhar a acao' {
        Mock Test-PhportoElevado { $false }
        { Invoke-PhportoWinAction -Action 'processes' } |
            Should -Throw -ExpectedMessage 'PHPorto: sem elevacao'
    }

    It 'recusa sem elevacao mesmo com acao desconhecida — a elevacao vem primeiro' {
        Mock Test-PhportoElevado { $false }
        { Invoke-PhportoWinAction -Action 'rm-rf' } |
            Should -Throw -ExpectedMessage 'PHPorto: sem elevacao'
    }

    It 'exige o parametro -Action' {
        (Get-Command Invoke-PhportoWinAction).Parameters['Action'].Attributes |
            Where-Object { $_ -is [System.Management.Automation.ParameterAttribute] -and $_.Mandatory } |
            Should -Not -BeNullOrEmpty
    }
}
