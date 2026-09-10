#Requires -Version 5.1
<#
.SYNOPSIS
    Testes do script que o worker gera para rodar uma acao.

.DESCRIPTION
    POR QUE POR AST: o worker.ps1 tem parametros obrigatorios e termina num
    laco infinito — carrega-lo com dot-source rodaria o worker. As funcoes de
    topo dele sao extraidas pelo parser e avaliadas aqui, que e' a mesma tecnica
    que a suite do winutil-cli usava para pegar o Write-Status do ponto de
    entrada sem executa-lo.

    O QUE ISSO COBRE: New-InvocationScript, que e' a peca que a fatia 3 troca —
    o script gerado deixou de chamar um winutil-cli externo e passou a carregar
    o bootstrap deste repositorio.

    E COBRE DE VERDADE: o ultimo Describe RODA o script gerado num powershell
    de verdade e confere o codigo de saida e o arquivo de codigo. Funciona
    porque a suite roda SEM elevacao — o despachante recusa, e recusa e'
    exatamente o caminho que precisa provar que devolve 1 em vez de nada.
#>

BeforeAll {
    $Script:RaizProjeto = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $Script:PastaWin    = Join-Path $Script:RaizProjeto 'src\Win'
    $Script:Worker      = Join-Path $Script:PastaWin 'worker.ps1'

    # As funcoes de topo do worker, sem executar o worker.
    $ast = [System.Management.Automation.Language.Parser]::ParseFile(
        $Script:Worker, [ref]$null, [ref]$null
    )
    $ast.FindAll(
        { param($n) $n -is [System.Management.Automation.Language.FunctionDefinitionAst] },
        $false
    ) | ForEach-Object { Invoke-Expression $_.Extent.Text }

    # O que o worker teria em escopo de script quando gera o arquivo.
    #
    # $BOOTSTRAP aponta para o bootstrap DE VERDADE, e nao para um dublê: os
    # testes que rodam o script gerado precisam do arquivo real na ponta, senao
    # provariam apenas que o texto foi escrito.
    $Script:Trabalho = Join-Path ([System.IO.Path]::GetTempPath()) ("phporto-worker-" + [guid]::NewGuid().ToString('N'))
    New-Item -ItemType Directory -Path $Script:Trabalho -Force | Out-Null

    $global:Dir       = $Script:Trabalho
    $global:BOOTSTRAP = Join-Path $Script:PastaWin 'bootstrap.ps1'

    function Get-ScriptGerado {
        param([string]$Acao, [hashtable]$Params = @{})

        $aceitos = [ordered]@{}
        foreach ($k in $Params.Keys) { $aceitos[$k] = $Params[$k] }

        $id      = [guid]::NewGuid().ToString('N').Substring(0, 12)
        $caminho = New-InvocationScript @{ acao = $Acao; params = $aceitos } $id

        return [PSCustomObject]@{
            Id       = $id
            Caminho  = $caminho
            Texto    = [System.IO.File]::ReadAllText($caminho)
            ArqExit  = Join-Path $Script:Trabalho ('win-exit-' + $id + '.txt')
        }
    }
}

AfterAll {
    if ($Script:Trabalho -and (Test-Path $Script:Trabalho)) {
        Remove-Item -Path $Script:Trabalho -Recurse -Force -ErrorAction SilentlyContinue
    }
}

# ==============================================================
# O ALVO
# ==============================================================
Describe 'worker - o script gerado aponta para o bootstrap' {

    It 'carrega o bootstrap.ps1 de src/Win, e nao um winutil externo' {
        $g = Get-ScriptGerado -Acao 'audit'
        $g.Texto | Should -Match ([regex]::Escape(". '" + (Join-Path $Script:PastaWin 'bootstrap.ps1') + "'"))
    }

    It 'nao chama mais nenhum winutil-cli.ps1' {
        $g = Get-ScriptGerado -Acao 'audit'
        $g.Texto | Should -Not -Match 'winutil-cli'
    }

    It 'chama o despachante com a acao' {
        $g = Get-ScriptGerado -Acao 'processes'
        $g.Texto | Should -Match "Invoke-PhportoWinAction -Action 'processes'"
    }

    It 'funde os fluxos com *>&1 — sem isso o Write-Host nao entra na captura' {
        $g = Get-ScriptGerado -Acao 'processes'
        $g.Texto | Should -Match '\*>&1'
    }

    It 'o script gerado tem BOM e CRLF' {
        $g     = Get-ScriptGerado -Acao 'audit'
        $bytes = [System.IO.File]::ReadAllBytes($g.Caminho)
        $bytes[0] | Should -Be 0xEF
        $bytes[1] | Should -Be 0xBB
        $bytes[2] | Should -Be 0xBF
        [regex]::Matches($g.Texto, "(?<!`r)`n").Count | Should -Be 0
    }

    It 'o script gerado parseia' {
        $g     = Get-ScriptGerado -Acao 'optimize' -Params @{ Preset = 'ssh'; Undo = $true }
        $erros = $null
        [System.Management.Automation.Language.Parser]::ParseFile($g.Caminho, [ref]$null, [ref]$erros) | Out-Null
        $erros.Count | Should -Be 0
    }
}

# ==============================================================
# PARAMETROS — hashtable literal, por splatting
# ==============================================================
Describe 'worker - os parametros viram hashtable literal' {

    It 'acao sem parametro sai com hashtable vazio' {
        $g = Get-ScriptGerado -Acao 'audit'
        $g.Texto | Should -Match '-Params @\{\}'
    }

    It 'texto sai como literal de apostrofo' {
        $g = Get-ScriptGerado -Acao 'dns' -Params @{ Provider = 'Cloudflare' }
        $g.Texto | Should -Match "@\{ 'Provider' = 'Cloudflare' \}"
    }

    It 'inteiro sai sem aspas' {
        $g = Get-ScriptGerado -Acao 'network' -Params @{ Duration = 60 }
        $g.Texto | Should -Match "'Duration' = 60"
    }

    It 'flag verdadeira vira $true' {
        $g = Get-ScriptGerado -Acao 'tweaks' -Params @{ Preset = 'standard'; Undo = $true }
        $g.Texto | Should -Match "'Undo' = \`$true"
    }

    It 'flag falsa nao entra na chamada' {
        $g = Get-ScriptGerado -Acao 'tweaks' -Params @{ Preset = 'standard'; Undo = $false }
        $g.Texto | Should -Not -Match "'Undo'"
    }

    <#
        A GUARDA CONTRA INJECAO. O apostrofo e' o unico caractere com escape
        dentro de um literal de apostrofo simples, e dobra-lo e' o que impede
        um valor de fechar a string e virar codigo. Um valor que tenta fechar a
        string e emendar um comando tem de sair como TEXTO, e o script gerado
        tem de continuar parseando.
    #>
    It 'valor com apostrofo e escapado, e o script continua valido' {
        $veneno = "x'; Remove-Item C:\ -Recurse; '"
        $g      = Get-ScriptGerado -Acao 'install' -Params @{ Apps = $veneno }

        $g.Texto | Should -Match "''; Remove-Item"
        $g.Texto | Should -Not -Match "'; Remove-Item C:\\ -Recurse; ';"

        $erros = $null
        [System.Management.Automation.Language.Parser]::ParseFile($g.Caminho, [ref]$null, [ref]$erros) | Out-Null
        $erros.Count | Should -Be 0
    }

    It 'o nome do parametro tambem sai como literal' {
        $g = Get-ScriptGerado -Acao 'optimize' -Params @{ KeepUser = 'caiob' }
        $g.Texto | Should -Match "'KeepUser' = 'caiob'"
    }
}

# ==============================================================
# CODIGO DE SAIDA — de verdade, rodando o script
# ==============================================================
Describe 'worker - o script gerado roda e devolve codigo' {

    <#
        Roda sem elevacao de proposito: o despachante lanca, o catch do script
        gerado transforma isso em 1, e o arquivo de codigo e' escrito. Antes do
        try/catch a excecao mataria o script antes dessas linhas, o worker nao
        acharia arquivo nenhum e registraria exit nulo — que na tela nao se
        distingue de "terminou sem dizer nada".
    #>
    It 'recusa por falta de elevacao vira exit 1, com o motivo na saida' {
        $g = Get-ScriptGerado -Acao 'processes'

        $saidaArq = Join-Path $Script:Trabalho ('out-' + $g.Id + '.txt')
        $p = Start-Process -FilePath 'powershell.exe' `
            -ArgumentList ('-NoProfile -NonInteractive -ExecutionPolicy Bypass -File "' + $g.Caminho + '"') `
            -RedirectStandardOutput $saidaArq `
            -WindowStyle Hidden -PassThru -Wait

        $p.ExitCode | Should -Be 1

        Test-Path $g.ArqExit | Should -BeTrue
        ([System.IO.File]::ReadAllText($g.ArqExit)).Trim() | Should -Be '1'

        $saida = [System.IO.File]::ReadAllText($saidaArq)
        $saida | Should -Match '\[ ERROR \].*nao esta elevado'
        $saida | Should -Match '\[phporto\] PHPorto: sem elevacao'
    }

    It 'acao fora do mapa tambem vira exit 1, sem executar nada' {
        # A allowlist do worker nunca deixaria chegar aqui; o teste prova que a
        # ultima barreira tambem responde com codigo, e nao com silencio.
        $g = Get-ScriptGerado -Acao 'rm-rf'

        $saidaArq = Join-Path $Script:Trabalho ('out-' + $g.Id + '.txt')
        $p = Start-Process -FilePath 'powershell.exe' `
            -ArgumentList ('-NoProfile -NonInteractive -ExecutionPolicy Bypass -File "' + $g.Caminho + '"') `
            -RedirectStandardOutput $saidaArq `
            -WindowStyle Hidden -PassThru -Wait

        $p.ExitCode | Should -Be 1
        ([System.IO.File]::ReadAllText($g.ArqExit)).Trim() | Should -Be '1'
    }
}
