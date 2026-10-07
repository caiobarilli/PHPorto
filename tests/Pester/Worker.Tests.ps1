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
            $n.Left.VariablePath.UserPath -in @('ALLOWLIST', 'MAX_PARAM_BYTES', 'SID_ADMINS', 'SID_SYSTEM', 'SID_DONO')
        },
        $false
    ) | ForEach-Object { Invoke-Expression $_.Extent.Text }

    $global:ALLOWLIST       = $ALLOWLIST
    $global:MAX_PARAM_BYTES = $MAX_PARAM_BYTES
    $global:SID_ADMINS      = $SID_ADMINS
    $global:SID_SYSTEM      = $SID_SYSTEM
    $global:SID_DONO        = $SID_DONO

    # O manifesto que o PHP gravaria ao ligar, tirado aqui com as mesmas
    # fontes do WinManifest::FONTES. O teste de PHP confere que essas fontes
    # cobrem a arvore; aqui so importa ter o mapa de uma arvore real.
    function New-ManifestoDeTeste([string]$raiz) {
        $m = @{}
        foreach ($fonte in 'bootstrap.ps1', 'audit/audit.ps1', 'actions/*.ps1', 'lib/*.ps1', 'config/*.json') {
            Get-ChildItem -Path (Join-Path $raiz $fonte) -File -ErrorAction SilentlyContinue | ForEach-Object {
                $pasta = if ($fonte -like '*/*') { $fonte.Split('/')[0] + '/' } else { '' }
                $m[$pasta + $_.Name] = (Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash
            }
        }
        return $m
    }
    $global:Nonce           = 'nonce-de-teste'

    # O que o worker teria em escopo de script quando gera o arquivo.
    #
    # $RAIZ_WIN aponta para o src/Win DE VERDADE, com o manifesto dele, e nao
    # para um dublê: os testes que rodam o script gerado precisam do arquivo
    # real na ponta, senao provariam apenas que o texto foi escrito. A pasta
    # protegida, aqui, e' a de trabalho: a ACL e' coberta a parte.
    $Script:Trabalho = Join-Path ([System.IO.Path]::GetTempPath()) ("phporto-worker-" + [guid]::NewGuid().ToString('N'))
    New-Item -ItemType Directory -Path $Script:Trabalho -Force | Out-Null

    $global:Dir       = $Script:Trabalho
    $global:PROTEGIDA = $Script:Trabalho
    $global:RAIZ_WIN  = $Script:PastaWin
    $global:MANIFESTO = New-ManifestoDeTeste $Script:PastaWin

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

    # Um duble do bootstrap, para ver o que CHEGA na acao. Os parametros
    # viajam como dado codificado, entao o texto do script nao diz mais nada
    # sobre eles: a prova tem de ser rodar o script e olhar o outro lado.
    # O duble so registra o que recebeu, e nao pede elevacao. Mora numa raiz
    # propria, com manifesto proprio, porque o script gerado so carrega
    # bootstrap conferido.
    $Script:RaizDuble = Join-Path $Script:Trabalho 'raiz-duble'
    New-Item -ItemType Directory -Path $Script:RaizDuble -Force | Out-Null
    $Script:Duble    = Join-Path $Script:RaizDuble 'bootstrap.ps1'
    $Script:Recebido = Join-Path $Script:Trabalho 'recebido.json'
    $Script:Exe      = (Get-Process -Id $PID).Path

    $duble = @(
        'function Invoke-PhportoWinAction {'
        '    param([string]$Action, [hashtable]$Params = @{})'
        '    $tipos = [ordered]@{}'
        '    foreach ($k in $Params.Keys) { $tipos[$k] = $Params[$k].GetType().FullName }'
        '    $dados = [ordered]@{ acao = $Action; params = $Params; tipos = $tipos }'
        ('    [System.IO.File]::WriteAllText(' + (ConvertTo-PsLiteral $Script:Recebido) + ', (ConvertTo-Json -InputObject $dados -Compress -Depth 5), [System.Text.UTF8Encoding]::new($false))')
        '}'
    ) -join "`r`n"
    [System.IO.File]::WriteAllText($Script:Duble, $duble, [System.Text.UTF8Encoding]::new($true))
    $Script:ManifestoDuble = @{ 'bootstrap.ps1' = (Get-FileHash -LiteralPath $Script:Duble -Algorithm SHA256).Hash }

    # Gera o script apontando para o duble e roda num PowerShell de verdade, o
    # mesmo que roda a suite: powershell.exe no Windows, pwsh fora dele.
    function Invoke-ScriptGerado {
        param([string]$Acao, [hashtable]$Params = @{})

        if (Test-Path $Script:Recebido) { Remove-Item $Script:Recebido -Force }

        $raiz = $global:RAIZ_WIN
        $mapa = $global:MANIFESTO
        $global:RAIZ_WIN  = $Script:RaizDuble
        $global:MANIFESTO = $Script:ManifestoDuble
        try {
            $g = Get-ScriptGerado -Acao $Acao -Params $Params
        } finally {
            $global:RAIZ_WIN  = $raiz
            $global:MANIFESTO = $mapa
        }

        & $Script:Exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -File $g.Caminho | Out-Null
        $code = $LASTEXITCODE

        $recebido = $null
        if (Test-Path $Script:Recebido) {
            $recebido = Get-Content -Path $Script:Recebido -Raw -Encoding UTF8 | ConvertFrom-Json
        }

        return [PSCustomObject]@{
            Gerado   = $g
            Exit     = $code
            Recebido = $recebido
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

    It 'conhece as dezesseis acoes' {
        @($global:ALLOWLIST.Keys | Sort-Object) | Should -Be @(
            'audit', 'debloat', 'dns', 'exporter', 'gdid', 'gpu', 'hyperv', 'install',
            'memory', 'network', 'optimize', 'performance', 'processes', 'rdp', 'sunshine', 'tweaks'
        )
    }

    It 'aceita sunshine com <_>' -ForEach @('status', 'install', 'start', 'stop', 'firewall-open', 'firewall-close') {
        $job = [PSCustomObject]@{
            nonce  = $global:Nonce
            acao   = 'sunshine'
            params = [PSCustomObject]@{ SubAction = $_ }
        }

        (Test-Job $job).params['SubAction'] | Should -Be $_
    }

    It 'recusa uma subacao que o sunshine nao tem (pareamento inclusive)' {
        $job = [PSCustomObject]@{
            nonce  = $global:Nonce
            acao   = 'sunshine'
            params = [PSCustomObject]@{ SubAction = 'pair' }
        }

        { Test-Job $job } | Should -Throw -ExpectedMessage "valor fora do conjunto em 'SubAction'"
    }

    It 'aceita rdp com <_>' -ForEach @('status', 'on', 'off', 'h264-on', 'h264-off') {
        $job = [PSCustomObject]@{
            nonce  = $global:Nonce
            acao   = 'rdp'
            params = [PSCustomObject]@{ SubAction = $_ }
        }

        (Test-Job $job).params['SubAction'] | Should -Be $_
    }

    It 'recusa subacao que o rdp nao tem' {
        $job = [PSCustomObject]@{
            nonce  = $global:Nonce
            acao   = 'rdp'
            params = [PSCustomObject]@{ SubAction = 'reboot' }
        }

        { Test-Job $job } | Should -Throw -ExpectedMessage "valor fora do conjunto em 'SubAction'"
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
# LISTA NO SCRIPT GERADO — chega na acao como array
# ==============================================================
# O worker aberto aceita job de quem souber o nonce, e o nonce e' legivel no
# marcador. Sair sozinho quando ninguem usa encurta essa janela. A conta e'
# pura e fica numa funcao para dar para testar sem esperar dez minutos.
Describe 'worker - sai sozinho quando fica ocioso' {

    BeforeAll {
        $ast = [System.Management.Automation.Language.Parser]::ParseFile(
            $Script:Worker, [ref]$null, [ref]$null
        )
        $Script:Idle = $ast.FindAll(
            {
                param($n)
                $n -is [System.Management.Automation.Language.AssignmentStatementAst] -and
                $n.Left -is [System.Management.Automation.Language.VariableExpressionAst] -and
                $n.Left.VariablePath.UserPath -eq 'IDLE_TIMEOUT_S'
            },
            $false
        ) | ForEach-Object { [int]$_.Right.Extent.Text }
        $Script:T0 = [datetime]'2026-01-01T00:00:00'
    }

    It 'o limite vem de uma constante so, de dez minutos' {
        @($Script:Idle).Count | Should -Be 1
        $Script:Idle | Should -Be 600
    }

    It 'antes do limite nao sai' {
        Test-WorkerOcioso $Script:T0 $Script:T0.AddSeconds(599) 600 $false | Should -BeFalse
    }

    It 'no limite sai' {
        Test-WorkerOcioso $Script:T0 $Script:T0.AddSeconds(600) 600 $false | Should -BeTrue
    }

    It 'com acao em andamento nunca sai, por mais longa que seja' {
        Test-WorkerOcioso $Script:T0 $Script:T0.AddHours(5) 600 $true | Should -BeFalse
    }

    It 'o laco usa a conta e sai com break' {
        $texto = Get-Content -Raw $Script:Worker
        $texto | Should -Match 'Test-WorkerOcioso \$ultimaAtividade \(Get-Date\) \$IDLE_TIMEOUT_S'
        # Job lido conta como atividade, aceito ou recusado.
        $texto | Should -Match '(?s)if \(\$null -ne \$bruto\) \{\s+\$ultimaAtividade = Get-Date'
    }
}

Describe 'worker - lista chega na acao como array' {

    It 'a lista chega como string[], na ordem' {
        $r = Invoke-ScriptGerado 'tweaks' @{ Items = [string[]]@('WPFTweaksTelemetry', 'WPFTweaksServices') }
        $r.Recebido.tipos.Items   | Should -Be 'System.String[]'
        @($r.Recebido.params.Items) | Should -Be @('WPFTweaksTelemetry', 'WPFTweaksServices')
    }

    It 'lista de um item so continua array' {
        # O ConvertFrom-Json devolve Object[]; sem a conversao a mao, um item
        # so poderia chegar como string solta num parametro [string[]].
        $r = Invoke-ScriptGerado 'debloat' @{ Packages = [string[]]@('Microsoft.BingNews') }
        $r.Recebido.tipos.Packages     | Should -Be 'System.String[]'
        @($r.Recebido.params.Packages) | Should -Be @('Microsoft.BingNews')
    }

    It 'item com apostrofo chega igual, e o script continua valido' {
        $r = Invoke-ScriptGerado 'debloat' @{ Packages = [string[]]@("a'b") }
        @($r.Recebido.params.Packages) | Should -Be @("a'b")

        $erros = $null
        [System.Management.Automation.Language.Parser]::ParseInput($r.Gerado.Texto, [ref]$null, [ref]$erros) | Out-Null
        $erros.Count | Should -Be 0
    }
}
Describe 'worker - o script gerado aponta para o bootstrap' {

    It 'carrega o bootstrap.ps1 de src/Win, e nao um winutil externo' {
        $g = Get-ScriptGerado -Acao 'audit'
        $g.Texto | Should -Match ([regex]::Escape('$global:root = ' + "'" + $Script:PastaWin + "'"))
        $g.Texto | Should -Match ([regex]::Escape("Read-PhportoConferido `$global:root 'bootstrap.ps1' `$global:PhportoManifesto"))
    }

    It 'nunca carrega o bootstrap pelo caminho, so pelo texto conferido' {
        # Dot-source pelo caminho leria o arquivo de novo, depois da
        # conferencia, e quem o trocasse em laco acertaria a janela.
        $g = Get-ScriptGerado -Acao 'audit'
        $g.Texto | Should -Not -Match "\. '[^']*bootstrap\.ps1'"
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
# PARAMETROS — dado decodificado, por splatting
# ==============================================================
Describe 'worker - os parametros chegam na acao como dado' {

    It 'acao sem parametro chega com hashtable vazio' {
        $r = Invoke-ScriptGerado -Acao 'audit'
        $r.Recebido.acao | Should -Be 'audit'
        @($r.Recebido.params.PSObject.Properties).Count | Should -Be 0
        $r.Gerado.Texto | Should -Match '-Params \$phportoParams'
    }

    It 'texto chega como string, igual' {
        $r = Invoke-ScriptGerado -Acao 'dns' -Params @{ Provider = 'Cloudflare' }
        $r.Recebido.params.Provider | Should -BeExactly 'Cloudflare'
        $r.Recebido.tipos.Provider  | Should -Be 'System.String'
    }

    It 'inteiro chega como inteiro' {
        # Int32 no 5.1, Int64 no 7: os dois ligam num parametro [int].
        $r = Invoke-ScriptGerado -Acao 'network' -Params @{ Duration = 60 }
        $r.Recebido.params.Duration | Should -Be 60
        $r.Recebido.tipos.Duration  | Should -Match '^System\.Int(32|64)$'
    }

    It 'flag verdadeira chega como $true' {
        $r = Invoke-ScriptGerado -Acao 'tweaks' -Params @{ Preset = 'standard'; Undo = $true }
        $r.Recebido.params.Undo | Should -BeTrue
        $r.Recebido.tipos.Undo  | Should -Be 'System.Boolean'
    }

    It 'flag falsa nao entra na chamada' {
        $r = Invoke-ScriptGerado -Acao 'tweaks' -Params @{ Preset = 'standard'; Undo = $false }
        @($r.Recebido.params.PSObject.Properties.Name) | Should -Not -Contain 'Undo'
        $r.Recebido.params.Preset | Should -Be 'standard'
    }

    <#
        A GUARDA CONTRA INJECAO. Um valor que tenta fechar a string e emendar
        um comando nem aparece no texto do script: ele viaja codificado, e o
        script gerado continua parseando.
    #>
    It 'valor com apostrofo nao aparece no script, e o script continua valido' {
        $veneno = "x'; Remove-Item C:\ -Recurse; '"
        $g      = Get-ScriptGerado -Acao 'install' -Params @{ Apps = $veneno }

        $g.Texto | Should -Not -Match 'Remove-Item'

        $erros = $null
        [System.Management.Automation.Language.Parser]::ParseFile($g.Caminho, [ref]$null, [ref]$erros) | Out-Null
        $erros.Count | Should -Be 0
    }

    It 'nem o nome nem o valor do parametro aparecem no script, e chegam iguais' {
        $r = Invoke-ScriptGerado -Acao 'optimize' -Params @{ KeepUser = 'caiob' }
        $r.Gerado.Texto | Should -Not -Match 'KeepUser'
        $r.Gerado.Texto | Should -Not -Match 'caiob'
        $r.Recebido.params.KeepUser | Should -BeExactly 'caiob'
    }
}

# ==============================================================
# ASPA CURVA — o PowerShell fecha literal de apostrofo com ela
# ==============================================================
#
# U+2018, U+2019, U+201A e U+201B fecham um literal de apostrofo como o '.
# O escape antigo so dobrava o ', entao um -Apps com uma curva fechava a
# string, e o $(...) seguinte rodava no script gerado, em integridade Alta.
# O veneno daqui grava um arquivo-marca se virar codigo.
Describe 'worker - aspa curva nao vira codigo' {

    It 'valor com <Nome> chega como dado, e nao executa' -ForEach @(
        @{ Nome = 'U+0027'; Codigo = 0x0027 }
        @{ Nome = 'U+2018'; Codigo = 0x2018 }
        @{ Nome = 'U+2019'; Codigo = 0x2019 }
        @{ Nome = 'U+201A'; Codigo = 0x201A }
        @{ Nome = 'U+201B'; Codigo = 0x201B }
    ) {
        $aspa   = [string][char]$Codigo
        $marca  = Join-Path $Script:Trabalho ('marca-' + [guid]::NewGuid().ToString('N'))
        $veneno = 'x' + $aspa + '+$(Set-Content -Path "' + $marca + '" -Value 1)+' + $aspa

        $r = Invoke-ScriptGerado -Acao 'install' -Params @{ Apps = $veneno }

        Test-Path $marca | Should -BeFalse
        $r.Gerado.Texto | Should -Not -Match 'Set-Content -Path'
        $r.Recebido.params.Apps | Should -BeExactly $veneno
        $r.Exit | Should -Be 0
    }

    It 'as cinco aspas juntas chegam iguais' {
        $veneno = "a'" + [char]0x2018 + [char]0x2019 + [char]0x201A + [char]0x201B + 'b'
        $r = Invoke-ScriptGerado -Acao 'network' -Params @{ Interface = $veneno }
        $r.Recebido.params.Interface | Should -BeExactly $veneno
    }

    It 'ConvertTo-PsLiteral com <Nome> devolve um literal so, igual ao valor' -ForEach @(
        @{ Nome = 'U+0027'; Codigo = 0x0027 }
        @{ Nome = 'U+2018'; Codigo = 0x2018 }
        @{ Nome = 'U+2019'; Codigo = 0x2019 }
        @{ Nome = 'U+201A'; Codigo = 0x201A }
        @{ Nome = 'U+201B'; Codigo = 0x201B }
    ) {
        # A segunda camada: mesmo onde um valor ainda vira literal (caminhos,
        # nome da acao), nenhuma aspa fecha a string.
        $aspa  = [string][char]$Codigo
        $valor = 'a' + $aspa + '; Get-Process; ' + $aspa + 'b'

        $erros = $null
        $ast   = [System.Management.Automation.Language.Parser]::ParseInput((ConvertTo-PsLiteral $valor), [ref]$null, [ref]$erros)
        $erros.Count | Should -Be 0

        $textos = @($ast.FindAll({ param($n) $n -is [System.Management.Automation.Language.StringConstantExpressionAst] }, $true))
        $textos.Count    | Should -Be 1
        $textos[0].Value | Should -BeExactly $valor
        @($ast.FindAll({ param($n) $n -is [System.Management.Automation.Language.CommandAst] }, $true)).Count | Should -Be 0
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

# ==============================================================
# MANIFESTO — o que entra elevado e' o que estava la ao ligar
# ==============================================================
#
# src/Win e' gravavel por qualquer processo do usuario. O PHP tira o SHA-256 de
# cada arquivo ao ligar, e o lado elevado confere logo antes de carregar. A
# conferencia e' sobre os MESMOS bytes que entram: ler, conferir e depois
# carregar pelo caminho deixaria uma janela para quem troca o arquivo em laco.
Describe 'worker - Read-PhportoConferido' {

    BeforeAll {
        $Script:Raiz = Join-Path $Script:Trabalho ('conferido-' + [guid]::NewGuid().ToString('N'))
        New-Item -ItemType Directory -Path (Join-Path $Script:Raiz 'lib') -Force | Out-Null
        $Script:Lib = Join-Path $Script:Raiz 'lib/a.ps1'
    }

    BeforeEach {
        [System.IO.File]::WriteAllText($Script:Lib, "function Get-A { 'a' }`r`n", [System.Text.UTF8Encoding]::new($true))
        $Script:Mapa = @{ 'lib/a.ps1' = (Get-FileHash -LiteralPath $Script:Lib -Algorithm SHA256).Hash }
    }

    It 'hash que bate devolve o texto, sem o BOM' {
        $texto = Read-PhportoConferido $Script:Raiz 'lib/a.ps1' $Script:Mapa
        $texto | Should -BeExactly "function Get-A { 'a' }`r`n"
    }

    It 'aceita o caminho com barra invertida, como o do Windows' {
        Read-PhportoConferido $Script:Raiz 'lib\a.ps1' $Script:Mapa | Should -Match 'Get-A'
    }

    It 'o hash em minusculas, como o PHP grava, tambem bate' {
        $mapa = @{ 'lib/a.ps1' = $Script:Mapa['lib/a.ps1'].ToLowerInvariant() }
        Read-PhportoConferido $Script:Raiz 'lib/a.ps1' $mapa | Should -Match 'Get-A'
    }

    It 'arquivo alterado e recusado, com o que fazer' {
        [System.IO.File]::AppendAllText($Script:Lib, "Remove-Item C:\ -Recurse`r`n")
        { Read-PhportoConferido $Script:Raiz 'lib/a.ps1' $Script:Mapa } |
            Should -Throw -ExpectedMessage '*lib/a.ps1 mudou depois que o PowerShell elevado foi ligado*desligue e ligue de novo*'
    }

    It 'arquivo que sumiu e recusado' {
        Remove-Item -LiteralPath $Script:Lib -Force
        { Read-PhportoConferido $Script:Raiz 'lib/a.ps1' $Script:Mapa } |
            Should -Throw -ExpectedMessage '*lib/a.ps1 sumiu*'
    }

    It 'arquivo que nao estava no manifesto e recusado, mesmo existindo' {
        Set-Content -LiteralPath (Join-Path $Script:Raiz 'lib/novo.ps1') -Value "'novo'"
        { Read-PhportoConferido $Script:Raiz 'lib/novo.ps1' $Script:Mapa } |
            Should -Throw -ExpectedMessage '*lib/novo.ps1 nao estava em src/Win*'
    }

    It 'todo arquivo do manifesto real decodifica e parseia' {
        # Os sem BOM saem como UTF-8, e nao como ANSI: dois deles tem acento,
        # so em comentario. Este teste acusa se um dia houver acento fora dele.
        foreach ($chave in $global:MANIFESTO.Keys) {
            $texto = Read-PhportoConferido $Script:PastaWin $chave $global:MANIFESTO
            if ($chave -like '*.json') {
                { $texto | ConvertFrom-Json } | Should -Not -Throw -Because $chave
            } else {
                $erros = $null
                [System.Management.Automation.Language.Parser]::ParseInput($texto, [ref]$null, [ref]$erros) | Out-Null
                $erros.Count | Should -Be 0 -Because $chave
            }
        }
    }
}

Describe 'worker - o manifesto vem do arquivo cujo hash veio na linha de comando' {

    BeforeEach {
        $Script:Arq = Join-Path $Script:Trabalho ('manifesto-' + [guid]::NewGuid().ToString('N') + '.json')
        $json = '{"bootstrap.ps1":"' + ('ab' * 32) + '","lib/x.ps1":"' + ('CD' * 32) + '"}'
        [System.IO.File]::WriteAllText($Script:Arq, $json, [System.Text.UTF8Encoding]::new($false))
        $Script:Hash = (Get-FileHash -LiteralPath $Script:Arq -Algorithm SHA256).Hash
    }

    It 'com o hash certo, o mapa vira hashtable' {
        $m = Read-PhportoManifesto $Script:Arq $Script:Hash
        $m | Should -BeOfType [hashtable]
        $m.Count | Should -Be 2
        $m['bootstrap.ps1'] | Should -Be ('ab' * 32)
        $m['LIB/X.PS1'] | Should -Be ('CD' * 32) -Because 'o Windows nao distingue maiuscula em caminho'
    }

    It 'manifesto trocado depois de o PHP gravar e recusado' {
        [System.IO.File]::WriteAllText($Script:Arq, '{"bootstrap.ps1":"' + ('ef' * 32) + '"}')
        { Read-PhportoManifesto $Script:Arq $Script:Hash } | Should -Throw -ExpectedMessage "*nao e' o que o PHP gravou*"
    }

    It 'valor que nao e SHA-256 e recusado' {
        [System.IO.File]::WriteAllText($Script:Arq, '{"bootstrap.ps1":"x"}')
        $h = (Get-FileHash -LiteralPath $Script:Arq -Algorithm SHA256).Hash
        { Read-PhportoManifesto $Script:Arq $h } | Should -Throw -ExpectedMessage '*SHA-256 invalido*'
    }

    It 'manifesto sem o bootstrap e recusado' {
        [System.IO.File]::WriteAllText($Script:Arq, '{}')
        $h = (Get-FileHash -LiteralPath $Script:Arq -Algorithm SHA256).Hash
        { Read-PhportoManifesto $Script:Arq $h } | Should -Throw -ExpectedMessage '*sem o bootstrap*'
    }

    It 'o worker exige o hash na linha de comando' {
        $ast = [System.Management.Automation.Language.Parser]::ParseFile($Script:Worker, [ref]$null, [ref]$null)
        $p = $ast.ParamBlock.Parameters | Where-Object { $_.Name.VariablePath.UserPath -eq 'ManifestoSha256' }
        $p | Should -Not -BeNullOrEmpty
        $p.Attributes.Extent.Text | Should -Contain '[Parameter(Mandatory)]'
    }

    It 'sobe so depois de ler o manifesto e preparar a pasta, e o motivo da recusa vai na prova' {
        $texto = Get-Content -Raw $Script:Worker
        $texto | Should -Match '(?s)Read-PhportoManifesto \$F_MANIFESTO \$ManifestoSha256.*Initialize-PastaProtegida \$PROTEGIDA.*''ERRO='' \+.*exit 1.*"PID=\$PID;ADMIN=True'
    }
}

Describe 'worker - o script gerado so roda bootstrap conferido' {

    BeforeAll {
        function Invoke-Gerado([scriptblock]$Antes) {
            $raiz = $global:RAIZ_WIN; $mapa = $global:MANIFESTO
            $global:RAIZ_WIN = $Script:RaizDuble; $global:MANIFESTO = $Script:ManifestoDuble
            try { $g = Get-ScriptGerado -Acao 'audit' } finally { $global:RAIZ_WIN = $raiz; $global:MANIFESTO = $mapa }

            $original = [System.IO.File]::ReadAllBytes($Script:Duble)
            try {
                & $Antes
                if (Test-Path $Script:Recebido) { Remove-Item $Script:Recebido -Force }
                $saida = & $Script:Exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -File $g.Caminho 2>&1 | Out-String
                return [PSCustomObject]@{ Exit = $LASTEXITCODE; Saida = $saida; Rodou = (Test-Path $Script:Recebido) }
            } finally {
                [System.IO.File]::WriteAllBytes($Script:Duble, $original)
            }
        }
    }

    It 'bootstrap igual ao do manifesto roda a acao' {
        $r = Invoke-Gerado { }
        $r.Exit  | Should -Be 0
        $r.Rodou | Should -BeTrue
    }

    It 'bootstrap trocado depois de gerar e recusado com exit 1, e nada roda' {
        $r = Invoke-Gerado { [System.IO.File]::AppendAllText($Script:Duble, "`r`n# trocado`r`n") }
        $r.Exit  | Should -Be 1
        $r.Rodou | Should -BeFalse
        $r.Saida | Should -Match '\[phporto\] PHPorto: bootstrap\.ps1 mudou depois que o PowerShell elevado foi ligado'
    }

    It 'bootstrap que sumiu e recusado com exit 1' {
        $r = Invoke-Gerado { Remove-Item -LiteralPath $Script:Duble -Force }
        $r.Exit  | Should -Be 1
        $r.Rodou | Should -BeFalse
        $r.Saida | Should -Match 'bootstrap\.ps1 sumiu'
    }

    It 'o manifesto viaja no script como dado em base64, nao como codigo' {
        $g = Get-ScriptGerado -Acao 'audit'
        $g.Texto | Should -Not -Match ([regex]::Escape($global:MANIFESTO['lib/Set-WinUtilDNS.ps1']))
        $g.Texto | Should -Match '\$global:PhportoManifesto\[\$phportoPar\.Name\]'
    }
}

# O bootstrap e' quem confere lib/, actions/ e config/. Aqui ele roda numa
# copia de src/Win, para o teste poder estragar arquivo sem tocar no real.
Describe 'bootstrap - com manifesto, so carrega o que bate' {

    BeforeAll {
        $Script:Copia = Join-Path $Script:Trabalho ('win-' + [guid]::NewGuid().ToString('N'))
        Copy-Item -LiteralPath $Script:PastaWin -Destination $Script:Copia -Recurse
        $Script:BootCopia = Join-Path $Script:Copia 'bootstrap.ps1'
    }

    AfterEach {
        Remove-Variable -Name PhportoManifesto -Scope Global -ErrorAction SilentlyContinue
    }

    It 'arvore igual ao manifesto carrega' {
        $global:PhportoManifesto = New-ManifestoDeTeste $Script:Copia
        # Fora de { }: dentro dele as funcoes do bootstrap morreriam com o bloco.
        Remove-Item function:Invoke-PhportoWinAction -ErrorAction SilentlyContinue
        . $Script:BootCopia
        Get-Command Invoke-PhportoWinAction -ErrorAction SilentlyContinue | Should -Not -BeNullOrEmpty
        $global:sync.configs.dns | Should -Not -BeNullOrEmpty
    }

    It 'lib alterado depois do manifesto bloqueia a carga' {
        $global:PhportoManifesto = New-ManifestoDeTeste $Script:Copia
        $alvo = Join-Path $Script:Copia 'lib/Set-WinUtilDNS.ps1'
        $antes = [System.IO.File]::ReadAllBytes($alvo)
        try {
            [System.IO.File]::AppendAllText($alvo, "`r`n# trocado`r`n")
            { . $Script:BootCopia } | Should -Throw -ExpectedMessage '*lib/Set-WinUtilDNS.ps1 mudou*'
        } finally {
            [System.IO.File]::WriteAllBytes($alvo, $antes)
        }
    }

    It 'config alterado depois do manifesto bloqueia a carga' {
        # O tweaks.json carrega PowerShell: config e' codigo aqui.
        $global:PhportoManifesto = New-ManifestoDeTeste $Script:Copia
        $alvo = Join-Path $Script:Copia 'config/dns.json'
        $antes = [System.IO.File]::ReadAllBytes($alvo)
        try {
            [System.IO.File]::AppendAllText($alvo, ' ')
            { . $Script:BootCopia } | Should -Throw -ExpectedMessage '*config/dns.json mudou*'
        } finally {
            [System.IO.File]::WriteAllBytes($alvo, $antes)
        }
    }

    It 'acao nova na pasta, que nao estava no manifesto, bloqueia a carga' {
        $global:PhportoManifesto = New-ManifestoDeTeste $Script:Copia
        $novo = Join-Path $Script:Copia 'actions/Invoke-Intrusa.ps1'
        try {
            Set-Content -LiteralPath $novo -Value 'function Invoke-Intrusa { }'
            { . $Script:BootCopia } | Should -Throw -ExpectedMessage '*actions/Invoke-Intrusa.ps1 nao estava em src/Win*'
        } finally {
            Remove-Item -LiteralPath $novo -Force -ErrorAction SilentlyContinue
        }
    }

    It 'sem manifesto (teste, prompt) carrega do disco, como antes' {
        { . $Script:BootCopia } | Should -Not -Throw
    }
}

# ==============================================================
# PASTA PROTEGIDA — onde moram os scripts gerados e os resultados
# ==============================================================
#
# As chamadas de ACL so existem no Windows. A decisao fica em funcoes puras,
# testadas aqui; o que toca o disco fica em funcoes pequenas, trocadas por
# Mock, como os outros testes fazem com os cmdlets do Windows.
Describe 'worker - as regras da pasta protegida' {

    BeforeAll {
        $Script:Regras  = Get-RegrasProtegida 'S-1-5-21-1-2-3-1001'
        $Script:Usuario = @($Script:Regras | Where-Object { $_.Sid -eq 'S-1-5-21-1-2-3-1001' })
    }

    It 'Administradores e SYSTEM fazem tudo' {
        foreach ($sid in $global:SID_ADMINS, $global:SID_SYSTEM) {
            ($Script:Regras | Where-Object { $_.Sid -eq $sid }).Direitos | Should -Be 'FullControl'
        }
    }

    It 'o usuario do php -S so le e apaga arquivo, e nunca escreve' {
        @($Script:Usuario.Direitos) | Should -Be @('ReadAndExecute', 'Delete')
        ($Script:Usuario | Where-Object { $_.Direitos -eq 'Delete' }).SoArquivos | Should -BeTrue
        $Script:Usuario.Direitos | Should -Not -Contain 'Write'
        $Script:Usuario.Direitos | Should -Not -Contain 'Modify'
        $Script:Usuario.Direitos | Should -Not -Contain 'FullControl'
    }

    It 'OWNER RIGHTS so le: o dono de um arquivo nao reescreve a ACL dele' {
        ($Script:Regras | Where-Object { $_.Sid -eq $global:SID_DONO }).Direitos | Should -Be 'ReadAndExecute'
        $global:SID_DONO | Should -Be 'S-1-3-4'
    }

    It 'monta a ACL de verdade, protegida e com Administradores de dono' -Skip:($env:OS -ne 'Windows_NT') {
        $acl = New-AclProtegida 'S-1-5-21-1-2-3-1001' -ComDono
        $acl.AreAccessRulesProtected | Should -BeTrue
        $acl.GetOwner([System.Security.Principal.SecurityIdentifier]).Value | Should -Be 'S-1-5-32-544'
        @($acl.GetAccessRules($true, $false, [System.Security.Principal.SecurityIdentifier])).Count | Should -Be 5
    }
}

Describe 'worker - Test-PastaProtegida' {

    It 'pasta de <Dono> passa' -ForEach @(@{ Dono = 'S-1-5-32-544' }, @{ Dono = 'S-1-5-18' }) {
        Test-PastaProtegida ([PSCustomObject]@{ Existe = $true; Pasta = $true; Link = $false; Dono = $Dono }) | Should -BeNullOrEmpty
    }

    It 'link ou juncao e recusado' {
        Test-PastaProtegida ([PSCustomObject]@{ Existe = $true; Pasta = $true; Link = $true; Dono = $null }) | Should -Match 'link ou juncao'
    }

    It 'pasta do proprio usuario e recusada: o dono reescreve a ACL' {
        Test-PastaProtegida ([PSCustomObject]@{ Existe = $true; Pasta = $true; Link = $false; Dono = 'S-1-5-21-1-2-3-1001' }) | Should -Match 'dono S-1-5-21-1-2-3-1001'
    }

    It 'arquivo no lugar da pasta e recusado' {
        Test-PastaProtegida ([PSCustomObject]@{ Existe = $true; Pasta = $false; Link = $false; Dono = 'S-1-5-32-544' }) | Should -Match 'uma pasta'
    }
}

Describe 'worker - Initialize-PastaProtegida' {

    BeforeEach {
        # A trava e' um arquivo de verdade, entao a pasta existe de verdade; a
        # criacao e a ACL e' que sao trocadas por Mock.
        $Script:Pasta = Join-Path $Script:Trabalho ('protegida-' + [guid]::NewGuid().ToString('N'))
        New-Item -ItemType Directory -Path $Script:Pasta | Out-Null
        $Script:Ok = [PSCustomObject]@{ Existe = $true; Pasta = $true; Link = $false; Dono = 'S-1-5-32-544' }

        Mock New-AclProtegida { 'acl' }
        Mock New-PastaProtegida { }
        Mock Set-PastaProtegidaAcl { }
    }

    It 'pasta ausente nasce com a ACL e o dono, e a trava fica aberta' {
        $Script:Chamadas = 0
        Mock Get-PastaInfo { $Script:Chamadas++; if ($Script:Chamadas -eq 1) { [PSCustomObject]@{ Existe = $false } } else { $Script:Ok } }

        $trava = Initialize-PastaProtegida $Script:Pasta 'S-1-5-21-1'
        try {
            $trava.CanRead | Should -BeTrue
            Should -Invoke New-PastaProtegida -Times 1 -Exactly
            Should -Invoke New-AclProtegida -Times 1 -Exactly -ParameterFilter { $ComDono }
            Should -Invoke Set-PastaProtegidaAcl -Times 0 -Exactly
        } finally { $trava.Dispose() }
    }

    It 'pasta que ja existe e e de Administradores tem a ACL reparada, sem trocar o dono' {
        Mock Get-PastaInfo { $Script:Ok }

        $trava = Initialize-PastaProtegida $Script:Pasta 'S-1-5-21-1'
        try {
            Should -Invoke Set-PastaProtegidaAcl -Times 1 -Exactly
            Should -Invoke New-AclProtegida -Times 1 -Exactly -ParameterFilter { -not $ComDono }
            Should -Invoke New-PastaProtegida -Times 0 -Exactly
        } finally { $trava.Dispose() }
    }

    It 'pasta que e link e recusada antes de tocar em qualquer coisa' {
        Mock Get-PastaInfo { [PSCustomObject]@{ Existe = $true; Pasta = $true; Link = $true; Dono = $null } }

        { Initialize-PastaProtegida $Script:Pasta 'S-1-5-21-1' } | Should -Throw -ExpectedMessage '*link ou juncao*Apague a pasta*'
        Should -Invoke Set-PastaProtegidaAcl -Times 0 -Exactly
        Test-Path (Join-Path $Script:Pasta 'win-trava') | Should -BeFalse
    }

    It 'pasta de outro dono e recusada' {
        Mock Get-PastaInfo { [PSCustomObject]@{ Existe = $true; Pasta = $true; Link = $false; Dono = 'S-1-5-21-9' } }

        { Initialize-PastaProtegida $Script:Pasta 'S-1-5-21-1' } | Should -Throw -ExpectedMessage '*dono S-1-5-21-9*'
        Should -Invoke Set-PastaProtegidaAcl -Times 0 -Exactly
    }

    It 'pasta trocada entre a conferencia e a trava e recusada, e a trava e solta' {
        $Script:Chamadas = 0
        Mock Get-PastaInfo { $Script:Chamadas++; if ($Script:Chamadas -eq 1) { $Script:Ok } else { [PSCustomObject]@{ Existe = $true; Pasta = $true; Link = $true; Dono = $null } } }

        { Initialize-PastaProtegida $Script:Pasta 'S-1-5-21-1' } | Should -Throw -ExpectedMessage '*link ou juncao*'
        # Trava solta: o arquivo abre de novo sem compartilhar nada.
        $f = [System.IO.File]::Open((Join-Path $Script:Pasta 'win-trava'), 'Open', 'ReadWrite', 'None')
        $f.Dispose()
    }
}

Describe 'worker - Get-UsuarioPhp' {

    BeforeAll {
        # Os cmdlets de CIM nao existem no pwsh fora do Windows, e o Mock exige
        # um comando para substituir.
        # Cada um a parte: outro arquivo de teste pode ter deixado um deles.
        $Script:StubGet = -not (Get-Command Get-CimInstance -ErrorAction SilentlyContinue)
        $Script:StubInv = -not (Get-Command Invoke-CimMethod -ErrorAction SilentlyContinue)
        if ($Script:StubGet) { function global:Get-CimInstance { param($ClassName, $Filter) } }
        if ($Script:StubInv) { function global:Invoke-CimMethod { param($InputObject, $MethodName) } }
    }

    AfterAll {
        if ($Script:StubGet) { Remove-Item function:global:Get-CimInstance -ErrorAction SilentlyContinue }
        if ($Script:StubInv) { Remove-Item function:global:Invoke-CimMethod -ErrorAction SilentlyContinue }
    }

    It 'devolve o SID do dono do processo do php -S' {
        Mock Get-CimInstance { [PSCustomObject]@{ ProcessId = 4242 } }
        Mock Invoke-CimMethod { [PSCustomObject]@{ Sid = 'S-1-5-21-1-2-3-1001' } }
        Get-UsuarioPhp 4242 | Should -Be 'S-1-5-21-1-2-3-1001'
    }

    It 'sem processo, recusa em vez de adivinhar' {
        Mock Get-CimInstance { $null }
        { Get-UsuarioPhp 4242 } | Should -Throw -ExpectedMessage '*usuario do php -S*'
    }
}

Describe 'worker - resultados na pasta protegida' {

    It 'script gerado, saida, erro, codigo e conclusao vao para a pasta protegida' {
        $texto = Get-Content -Raw $Script:Worker
        foreach ($prefixo in 'win-exec-', 'win-out-', 'win-err-', 'win-exit-', 'win-done-') {
            $texto | Should -Not -Match ("Join-Path \`$Dir \('" + $prefixo) -Because $prefixo
            $texto | Should -Match ("Join-Path \`$PROTEGIDA \('" + $prefixo) -Because $prefixo
        }
    }

    It 'a trava e solta no fim' {
        Get-Content -Raw $Script:Worker | Should -Match '\$TRAVA\.Dispose\(\)'
    }
}
