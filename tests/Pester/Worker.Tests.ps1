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

    # E as duas constantes que a Test-Job consulta. Vem do arquivo, e nao
    # copiadas para ca: uma copia viraria um segundo lugar para atualizar, e o
    # teste passaria a conferir a copia em vez da tranca.
    $ast.FindAll(
        {
            param($n)
            $n -is [System.Management.Automation.Language.AssignmentStatementAst] -and
            $n.Left -is [System.Management.Automation.Language.VariableExpressionAst] -and
            $n.Left.VariablePath.UserPath -in @('ALLOWLIST', 'MAX_PARAM_BYTES')
        },
        $false
    ) | ForEach-Object { Invoke-Expression $_.Extent.Text }

    $global:ALLOWLIST       = $ALLOWLIST
    $global:MAX_PARAM_BYTES = $MAX_PARAM_BYTES
    $global:Nonce           = 'nonce-de-teste'

    # O que o worker teria em escopo de script quando gera o arquivo.
    #
    # $BOOTSTRAP aponta para o bootstrap DE VERDADE, e nao para um dublê: os
    # testes que rodam o script gerado precisam do arquivo real na ponta, senao
    # provariam apenas que o texto foi escrito.
    $Script:Trabalho = Join-Path ([System.IO.Path]::GetTempPath()) ("phporto-worker-" + [guid]::NewGuid().ToString('N'))
    New-Item -ItemType Directory -Path $Script:Trabalho -Force | Out-Null

    $global:Dir       = $Script:Trabalho
    $global:BOOTSTRAP = Join-Path $Script:PastaWin 'bootstrap.ps1'
    $global:CONFIG_DIR = Join-Path $Script:PastaWin 'config'

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
# ENCODING DO PROPRIO WORKER
# ==============================================================
#
# ESTE TESTE NASCEU DE UM ACIDENTE, e vale registrar qual: um `sed -i` rodado
# sobre o worker.ps1 para ajustar comentario devolveu o arquivo em LF. O
# .gitattributes marca *.ps1 com -text, ou seja, grava os bytes como estao —
# entao o LF teria entrado no repositorio, e o diff de vinte linhas apareceu
# como mil e cento e setenta e cinco, escondendo a mudanca real na revisao.
#
# O Bootstrap.Tests.ps1 ja cobria o bootstrap.ps1. O worker nao tinha ninguem
# olhando, e e' o arquivo que roda elevado.
Describe 'worker - encoding' {

    It 'worker.ps1 tem BOM UTF-8 — sem ele o 5.1 le como ANSI' {
        $bytes = [System.IO.File]::ReadAllBytes($Script:Worker)
        $bytes[0] | Should -Be 0xEF
        $bytes[1] | Should -Be 0xBB
        $bytes[2] | Should -Be 0xBF
    }

    It 'worker.ps1 tem CRLF, sem nenhum LF solto' {
        $texto = [System.IO.File]::ReadAllText($Script:Worker)
        $texto | Should -Match "`r`n"
        [regex]::Matches($texto, "(?<!`r)`n").Count | Should -Be 0
    }

    It 'worker.ps1 nao tem erro de sintaxe' {
        $erros = $null
        [System.Management.Automation.Language.Parser]::ParseFile(
            $Script:Worker, [ref]$null, [ref]$erros
        ) | Out-Null
        $erros.Count | Should -Be 0
    }

    It 'o worker nao recebe mais caminho de projeto externo' {
        $texto = Get-Content -Path $Script:Worker -Raw

        # O parametro -Winutil saiu com a independencia. Se voltar, volta junto
        # a chave de .env que esta fatia removeu de ponta a ponta.
        $texto | Should -Not -Match '\$Winutil'
        $texto | Should -Not -Match 'PHPORTO_WINUTIL_PATH'
    }
}

# ==============================================================
# O ARQUIVO DE CONCLUSAO — o unico sinal de fim
# ==============================================================
#
# Ele carrega mais do que o PHP que espera precisa, e por um motivo medido:
# quem espera ja sabe o que pediu, quem RECOLHE depois nao sabe de nada. Se o
# php -S sair entre o filho terminar e a linha ser gravada, este arquivo e' tudo
# o que resta da execucao.
Describe 'worker - o arquivo de conclusao' {

    BeforeEach {
        Get-ChildItem -Path $Script:Trabalho -Filter 'win-done-*.json' -File -ErrorAction SilentlyContinue |
            Remove-Item -Force -ErrorAction SilentlyContinue
    }

    It 'carrega acao, parametros e hora de fim, alem do que o PHP que espera usa' {
        Write-Done 'teste01' 0 1234 '' 'gdid' ([ordered]@{ SubAction = 'status' })

        $j = Get-Content (Join-Path $Script:Trabalho 'win-done-teste01.json') -Raw | ConvertFrom-Json

        $j.id                | Should -Be 'teste01'
        $j.exit              | Should -Be 0
        $j.ms                | Should -Be 1234
        $j.acao              | Should -Be 'gdid'
        $j.params.SubAction  | Should -Be 'status'
        $j.fim               | Should -Not -BeNullOrEmpty
    }

    It 'a hora de fim sai no formato do CURRENT_TIMESTAMP do banco, em UTC' {
        # 'yyyy-MM-dd HH:mm:ss'. Sem isso o recolhimento gravaria um carimbo
        # que o banco nao entende, e a ordenacao por created_at iria para o
        # espaco.
        Write-Done 'teste02' 0 1 '' 'audit' $null

        $j = Get-Content (Join-Path $Script:Trabalho 'win-done-teste02.json') -Raw | ConvertFrom-Json

        $j.fim | Should -Match '^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$'

        # E e' UTC, nao hora local: comparado com o agora em UTC, a diferenca
        # tem de ser de segundos.
        $agora = [DateTime]::UtcNow
        $lido  = [DateTime]::ParseExact($j.fim, 'yyyy-MM-dd HH:mm:ss', [System.Globalization.CultureInfo]::InvariantCulture)
        [Math]::Abs(($agora - $lido).TotalMinutes) | Should -BeLessThan 2
    }

    It 'params sai como OBJETO JSON, e nao como texto de tipo .NET' {
        # Com o -Depth padrao (2) o hashtable aninhado sairia serializado como
        # o nome do tipo, e o PHP receberia uma string onde espera objeto.
        Write-Done 'teste03' 0 1 '' 'tweaks' ([ordered]@{ Preset = 'advanced'; Undo = $true })

        $bruto = Get-Content (Join-Path $Script:Trabalho 'win-done-teste03.json') -Raw

        $bruto | Should -Match '"params":\{"Preset":"advanced","Undo":true\}'
        $bruto | Should -Not -Match 'System\.Collections'
    }

    It 'acao sem parametro sai com params vazio, e nao ausente' {
        Write-Done 'teste04' 0 1 '' 'audit' $null

        $bruto = Get-Content (Join-Path $Script:Trabalho 'win-done-teste04.json') -Raw
        $bruto | Should -Match '"params":\{\}'
    }

    It 'a recusa da allowlist sai sem acao: o worker nao inventa o que recusou' {
        Write-Done 'teste05' 126 0 'recusado'

        $j = Get-Content (Join-Path $Script:Trabalho 'win-done-teste05.json') -Raw | ConvertFrom-Json

        $j.acao | Should -Be ''
        $j.nota | Should -Be 'recusado'
        $j.exit | Should -Be 126
    }

    It 'o JSON sai sem BOM — com BOM o json_decode do PHP devolve null' {
        Write-Done 'teste06' 0 1 '' 'audit' $null

        $bytes = [System.IO.File]::ReadAllBytes((Join-Path $Script:Trabalho 'win-done-teste06.json'))
        $bytes[0] | Should -Not -Be 0xEF
    }

    It 'a interrupcao existe como caminho proprio, e deixa sinal de fim' {
        # A funcao que fecha o buraco medido: antes, o laco saia por
        # "pai desapareceu" matando o filho e sem escrever conclusao nenhuma —
        # entao nao havia o que recolher, e a execucao desaparecia.
        Get-Command -Name 'Stop-FilhoComSinal' -CommandType Function -ErrorAction SilentlyContinue |
            Should -Not -BeNullOrEmpty

        $texto = Get-Content -Path $Script:Worker -Raw

        # Os dois caminhos de saida do laco passam por ela.
        $texto | Should -Match "Stop-FilhoComSinal 'pai desapareceu'"
        $texto | Should -Match "Stop-FilhoComSinal 'desligando'"
        $texto | Should -Match "Write-Done .*'interrompido'"
    }
}

# ==============================================================
# A TRANCA — Test-Job contra a allowlist
# ==============================================================
#
# Esta e' a allowlist que importa: a do lado elevado, a ultima a validar antes
# de executar. A do PHP recusa mais cedo e com mensagem melhor, mas quem
# escrever direto no arquivo de trabalho passa por cima dela — e nao por cima
# desta.
Describe 'worker - a allowlist do lado elevado' {

    It 'conhece as treze acoes' {
        @($global:ALLOWLIST.Keys | Sort-Object) | Should -Be @(
            'audit', 'debloat', 'dns', 'exporter', 'gdid', 'gpu', 'install',
            'memory', 'network', 'optimize', 'performance', 'processes', 'tweaks'
        )
    }

    It 'aceita gdid com <_>' -ForEach @('status', 'disable', 'enable') {
        $job = [PSCustomObject]@{
            nonce  = $global:Nonce
            acao   = 'gdid'
            params = [PSCustomObject]@{ SubAction = $_ }
        }

        $ok = Test-Job $job
        $ok.acao              | Should -Be 'gdid'
        $ok.params['SubAction'] | Should -Be $_
    }

    It 'guarda a grafia da LISTA, e nao a que veio no arquivo' {
        $job = [PSCustomObject]@{
            nonce  = $global:Nonce
            acao   = 'gdid'
            params = [PSCustomObject]@{ SubAction = 'DISABLE' }
        }

        (Test-Job $job).params['SubAction'] | Should -Be 'disable'
    }

    It 'recusa subacao que o gdid nao tem' {
        $job = [PSCustomObject]@{
            nonce  = $global:Nonce
            acao   = 'gdid'
            params = [PSCustomObject]@{ SubAction = 'uninstall' }
        }

        { Test-Job $job } | Should -Throw -ExpectedMessage "valor fora do conjunto em 'SubAction'"
    }

    It 'recusa parametro que o gdid nao aceita' {
        $job = [PSCustomObject]@{
            nonce  = $global:Nonce
            acao   = 'gdid'
            params = [PSCustomObject]@{ SubAction = 'status'; Kill = 'explorer' }
        }

        { Test-Job $job } | Should -Throw -ExpectedMessage "parametro fora da allowlist para 'gdid': 'Kill'"
    }

    It 'recusa gdid vindo de outra execucao do servidor' {
        $job = [PSCustomObject]@{
            nonce  = 'nonce-velho'
            acao   = 'gdid'
            params = [PSCustomObject]@{ SubAction = 'status' }
        }

        { Test-Job $job } | Should -Throw -ExpectedMessage '*obsoleto*'
    }

    It 'recusa acao que nao esta na lista' {
        $job = [PSCustomObject]@{ nonce = $global:Nonce; acao = 'rm-rf'; params = $null }

        { Test-Job $job } | Should -Throw -ExpectedMessage "acao fora da allowlist: 'rm-rf'"
    }
}

# ==============================================================
# O ALVO
# ==============================================================
# ==============================================================
# A REGRA 'lista' — tweaks -Items e debloat -Packages
# ==============================================================
Describe 'worker - as regras lista' {

    It 'a lista de tweaks tem as 62 chaves, sem os quatro Button/Combobox' {
        $chaves = @(Get-PhportoListaPermitida 'tweaks')
        $chaves.Count | Should -Be 62
        foreach ($fora in 'WPFOOSUbutton', 'WPFchangedns', 'WPFAddUltPerf', 'WPFRemoveUltPerf') {
            $chaves | Should -Not -Contain $fora
        }
    }

    It 'a lista de debloat tem os 22 pacotes do arquivo' {
        @(Get-PhportoListaPermitida 'debloat').Count | Should -Be 22
    }

    It 'fonte desconhecida devolve lista vazia' {
        @(Get-PhportoListaPermitida 'nada').Count | Should -Be 0
    }

    It 'aceita tweaks por lista, na grafia do arquivo, como array' {
        $job = [PSCustomObject]@{
            nonce  = $global:Nonce
            acao   = 'tweaks'
            params = [PSCustomObject]@{ Items = 'wpftweakstelemetry, WPFTweaksServices' }
        }

        $itens = (Test-Job $job).params['Items']
        ,$itens | Should -BeOfType [string[]]
        $itens | Should -Be @('WPFTweaksTelemetry', 'WPFTweaksServices')
    }

    It 'recusa um tweak que e controle da janela do WinUtil (Type Button)' {
        $job = [PSCustomObject]@{
            nonce  = $global:Nonce
            acao   = 'tweaks'
            params = [PSCustomObject]@{ Items = 'WPFTweaksTelemetry,WPFAddUltPerf' }
        }

        { Test-Job $job } | Should -Throw -ExpectedMessage "item fora da lista em 'Items': 'WPFAddUltPerf'"
    }

    It 'recusa item repetido' {
        $job = [PSCustomObject]@{
            nonce  = $global:Nonce
            acao   = 'tweaks'
            params = [PSCustomObject]@{ Items = 'WPFTweaksTelemetry,wpftweakstelemetry' }
        }

        { Test-Job $job } | Should -Throw -ExpectedMessage "item repetido em 'Items': 'WPFTweaksTelemetry'"
    }

    It 'recusa lista so de virgulas' {
        $job = [PSCustomObject]@{
            nonce  = $global:Nonce
            acao   = 'debloat'
            params = [PSCustomObject]@{ Packages = ' , ,' }
        }

        { Test-Job $job } | Should -Throw -ExpectedMessage "'Packages' sem nenhum item"
    }

    It 'recusa pacote fora do debloat.json' {
        $job = [PSCustomObject]@{
            nonce  = $global:Nonce
            acao   = 'debloat'
            params = [PSCustomObject]@{ Packages = 'Microsoft.BingNews,Microsoft.WindowsCalculator' }
        }

        { Test-Job $job } | Should -Throw -ExpectedMessage "item fora da lista em 'Packages': 'Microsoft.WindowsCalculator'"
    }

    It 'debloat sem parametro continua aceito: remove o arquivo inteiro' {
        $job = [PSCustomObject]@{ nonce = $global:Nonce; acao = 'debloat'; params = [PSCustomObject]@{} }

        (Test-Job $job).params.Count | Should -Be 0
    }

    It 'lista acima do teto de bytes e recusada antes de ler o config' {
        $job = [PSCustomObject]@{
            nonce  = $global:Nonce
            acao   = 'tweaks'
            params = [PSCustomObject]@{ Items = ('a' * ($global:MAX_PARAM_BYTES + 1)) }
        }

        { Test-Job $job } | Should -Throw -ExpectedMessage "'Items' passou do teto de bytes"
    }
}

Describe 'worker - audit e performance ganham parametro' {

    It 'aceita audit com <_>' -ForEach @('run', 'open') {
        $job = [PSCustomObject]@{ nonce = $global:Nonce; acao = 'audit'; params = [PSCustomObject]@{ SubAction = $_ } }
        (Test-Job $job).params['SubAction'] | Should -Be $_
    }

    It 'audit sem parametro continua aceito' {
        $job = [PSCustomObject]@{ nonce = $global:Nonce; acao = 'audit'; params = [PSCustomObject]@{} }
        (Test-Job $job).params.Count | Should -Be 0
    }

    It 'recusa subacao de audit que nao existe' {
        $job = [PSCustomObject]@{ nonce = $global:Nonce; acao = 'audit'; params = [PSCustomObject]@{ SubAction = 'delete' } }
        { Test-Job $job } | Should -Throw -ExpectedMessage "valor fora do conjunto em 'SubAction'"
    }

    It 'aceita performance com State <_>' -ForEach @('on', 'off') {
        $job = [PSCustomObject]@{ nonce = $global:Nonce; acao = 'performance'; params = [PSCustomObject]@{ State = $_ } }
        (Test-Job $job).params['State'] | Should -Be $_
    }

    It 'recusa State fora de on e off' {
        $job = [PSCustomObject]@{ nonce = $global:Nonce; acao = 'performance'; params = [PSCustomObject]@{ State = 'turbo' } }
        { Test-Job $job } | Should -Throw -ExpectedMessage "valor fora do conjunto em 'State'"
    }
}

Describe 'worker - o provider do dns vem do dns.json' {

    It 'aceita <_>' -ForEach @('Google', 'Custom', 'AdGuard_Ads_Trackers_Malware_Adult', 'DHCP') {
        $job = [PSCustomObject]@{ nonce = $global:Nonce; acao = 'dns'; params = [PSCustomObject]@{ Provider = $_ } }
        (Test-Job $job).params['Provider'] | Should -Be $_
    }

    It 'guarda a grafia do arquivo' {
        $job = [PSCustomObject]@{ nonce = $global:Nonce; acao = 'dns'; params = [PSCustomObject]@{ Provider = 'open_dns' } }
        (Test-Job $job).params['Provider'] | Should -Be 'Open_DNS'
    }

    It 'recusa o Default, que nao faz nada' {
        $job = [PSCustomObject]@{ nonce = $global:Nonce; acao = 'dns'; params = [PSCustomObject]@{ Provider = 'Default' } }
        { Test-Job $job } | Should -Throw -ExpectedMessage "valor fora do conjunto em 'Provider'"
    }
}

# ==============================================================
# LISTA NO SCRIPT GERADO — vira array literal
# ==============================================================
Describe 'worker - lista vira array no script gerado' {

    It 'o array sai como @(literal, literal)' {
        $g = Get-ScriptGerado 'tweaks' @{ Items = [string[]]@('WPFTweaksTelemetry', 'WPFTweaksServices') }
        $g.Texto | Should -Match ([regex]::Escape("@{ 'Items' = @('WPFTweaksTelemetry', 'WPFTweaksServices') }"))
    }

    It 'item com apostrofo e escapado, e o script continua valido' {
        $g = Get-ScriptGerado 'debloat' @{ Packages = [string[]]@("a'b") }
        $g.Texto | Should -Match ([regex]::Escape("@('a''b')"))

        $erros = $null
        [System.Management.Automation.Language.Parser]::ParseInput($g.Texto, [ref]$null, [ref]$erros) | Out-Null
        $erros.Count | Should -Be 0
    }
}
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
