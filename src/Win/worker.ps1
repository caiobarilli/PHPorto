<#
.SYNOPSIS
    Worker elevado do PHPorto. Recebe trabalho por arquivo e executa uma acao
    do Windows, carregada do bootstrap.ps1 ao lado.

.DESCRIPTION
    POR QUE ELE EXISTE, e por que o canal e' por arquivo:

    O php -S roda em integridade Media (S-1-16-8192) e este worker em Alta
    (S-1-16-12288). Medido nesta maquina:

      escrever de Media para Alta ....... PASSA (arquivo)
      ler de Alta para Media ............ PASSA (arquivo)
      pipe nomeado criado pelo elevado .. RECUSADO, "Permission denied"
      pipe com rotulo Low/NW explicito .. NAO PODE SER CRIADO,
                                          "o cliente nao tem o privilegio
                                          necessario" (falta SeSecurityPrivilege)
      taskkill de Media contra Alta ..... rc=128, "Acesso negado"

    Dai as duas consequencias que moldam este arquivo. Primeira: o canal e'
    arquivo, nao pipe. Segunda: o PHP NAO CONSEGUE MATAR este processo, nem o
    filho dele — entao desligar e cancelar chegam como ORDEM, num arquivo que
    este laco le, e quem obedece e' este worker.

    E POR QUE O FILHO E' ASSINCRONO: se este worker esperasse a acao de
    forma sincrona, o heartbeat pararia junto, e o PHP concluiria que o worker
    morreu exatamente durante a acao mais longa — um install de dez minutos
    derrubaria o indicador dele mesmo. Um laco so faz tudo: heartbeat, vigia
    do pai, ordens, job novo e sondagem do filho.
#>

param(
    # PID do php -S que pediu a elevacao. Este worker sai sozinho quando ele
    # desaparecer: e' o UNICO lado capaz de se encerrar, ja que Media nao mata
    # Alta. Sem isto, fechar o servidor deixaria um processo elevado de pe sem
    # ninguem para recolher.
    [Parameter(Mandatory)] [int]$ParentPid,

    # Pasta de trabalho (files/ do projeto): job, saida, ordens e heartbeat.
    [Parameter(Mandatory)] [string]$Dir,

    # Carimbo desta execucao do servidor. NAO E' TRANCA: ele mora no marcador,
    # em storage/, que qualquer processo do mesmo usuario le. E' guarda de
    # OBSOLESCENCIA — job deixado por uma execucao anterior do servidor nao e'
    # confundido com job desta.
    [Parameter(Mandatory)] [string]$Nonce
)

$ErrorActionPreference = 'Stop'
$OutputEncoding        = [System.Text.UTF8Encoding]::new($false)

# ============================================================
# CONSTANTES
# ============================================================

# Tique do laco. Medido: com 500 ms, a ordem de cancelamento foi obedecida em
# 711 ms entre a escrita do arquivo e a saida do processo.
$TICK_MS = 500

# Sem acao em andamento por este tempo, o worker sai sozinho. Encurta a janela
# em que um processo Media que leu o nonce do marcador consegue mandar job para
# ele. Dez minutos, o mesmo numero do timeout padrao de uma acao
# (PHPORTO_WINUTIL_TIMEOUT): quem esta usando a tela nao perde o worker no meio
# do trabalho. O Elevation.php repete o valor em IDLE_TIMEOUT_S, e um teste
# confere os dois.
$IDLE_TIMEOUT_S = 600

# Nomes dos arquivos do canal. Um lugar so, porque o lado PHP repete estes
# mesmos nomes e um deles ficaria para tras numa renomeacao.
$F_HEARTBEAT = Join-Path $Dir 'win-heartbeat'
$F_JOB       = Join-Path $Dir 'win-job.json'
$F_DESLIGAR  = Join-Path $Dir 'win-ordem-desligar'
$F_CANCELAR  = Join-Path $Dir 'win-ordem-cancelar'
$F_PROVA     = Join-Path $Dir ('win-prova-' + $Nonce + '.txt')
$F_LOG       = Join-Path $Dir 'win-worker.log'

# O bootstrap das acoes, irmao deste arquivo. Nao vem por parametro porque os
# dois viajam juntos no repositorio: nao ha o que configurar, e uma chave a
# menos e' uma chave a menos para ficar para tras.
#
# Resolvido AQUI, e nao dentro de New-InvocationScript, por dois motivos. O
# primeiro e' a convencao deste bloco: caminho fica num lugar so. O segundo e'
# medido — $PSScriptRoot dentro de uma funcao recriada por Invoke-Expression
# vem VAZIO e ainda sombreia o global, entao a funcao nao teria como saber onde
# esta, e o teste que a extrai por AST nao teria como dizer.
$BOOTSTRAP   = Join-Path $PSScriptRoot 'bootstrap.ps1'

# A pasta dos JSON que as regras 'lista' consultam, irma do bootstrap.
$CONFIG_DIR  = Join-Path $PSScriptRoot 'config'

# ============================================================
# ALLOWLIST — a tranca de verdade
# ============================================================
#
# O arquivo de trabalho carrega {acao, params}, NUNCA PowerShell. Cada acao
# declara aqui os parametros que aceita e os valores permitidos, e o que nao
# estiver declarado e' recusado antes de qualquer execucao.
#
# O QUE ELA FAZ: impede que quem escreva no arquivo de trabalho execute codigo
# arbitrario em integridade Alta. O maximo que se consegue e' uma das acoes de
# src/Win/actions, com parametros validos.
#
# O QUE ELA NAO FAZ: limitar a CONSEQUENCIA de uma acao legitima. O
# install -Apps continua instalando qualquer coisa que o winget ofereca, e e'
# para isso que ele existe. E ela nao protege o ARQUIVO deste script: quem
# puder reescrever worker.ps1 e' dono da proxima elevacao, do mesmo jeito que
# quem puder reescrever src/ e' dono da aplicacao. Nao e' exposicao nova.
#
# 'set'  = conjunto fechado de valores aceitos: os de 'valores', mais os da
#          lista que 'fonte' nomeia, quando houver
# 'text' = texto livre, com teto de bytes
# 'int'  = inteiro numa faixa
# 'flag' = switch, so entra na chamada quando verdadeiro
# 'lista' = itens separados por virgula, cada um da lista que 'fonte' nomeia
#           (ver Get-PhportoListaPermitida), sem repetir, com teto de bytes

$MAX_PARAM_BYTES = 4096

$ALLOWLIST = @{
    'memory'      = @{}
    'processes'   = @{}

    'audit'       = @{
        'SubAction' = @{ tipo = 'set'; valores = @('run', 'open') }
    }
    'performance' = @{
        'State' = @{ tipo = 'set'; valores = @('on', 'off') }
    }

    'tweaks'      = @{
        'Preset' = @{ tipo = 'set'; valores = @('standard', 'minimal', 'advanced') }
        'Items'  = @{ tipo = 'lista'; fonte = 'tweaks' }
        'Undo'   = @{ tipo = 'flag' }
    }
    'debloat'     = @{
        'Packages' = @{ tipo = 'lista'; fonte = 'debloat' }
    }
    'dns'         = @{
        'Provider'     = @{ tipo = 'set'; fonte = 'dns'; valores = @('DHCP') }
        'PrimaryDNS'   = @{ tipo = 'text' }
        'SecondaryDNS' = @{ tipo = 'text' }
    }
    'install'     = @{
        'Apps' = @{ tipo = 'text' }
    }
    'network'     = @{
        'Interface' = @{ tipo = 'text' }
        'Duration'  = @{ tipo = 'int'; min = 1; max = 3600 }
    }
    'exporter'    = @{
        'SubAction' = @{ tipo = 'set'; valores = @('install', 'status', 'start', 'stop', 'metrics', 'firewall') }
    }
    'gpu'         = @{
        'SubAction' = @{ tipo = 'set'; valores = @('install', 'status', 'start', 'stop', 'metrics', 'uninstall') }
    }
    'optimize'    = @{
        'Preset'   = @{ tipo = 'set'; valores = @('ssh', 'kill-rdp') }
        'Kill'     = @{ tipo = 'text' }
        'KeepUser' = @{ tipo = 'text' }
        'Undo'     = @{ tipo = 'flag' }
    }

    # O gdid liga e desliga um pipeline inteiro, entao o conjunto nao se parece
    # com o do exporter nem com o do gpu. E ele NAO entra em preset nenhum: o
    # 'disable' bloqueia dominios de notificacao no arquivo hosts, e efeito
    # amplo assim tem de ser escolhido a dedo, nunca herdado de um preset.
    'gdid'        = @{
        'SubAction' = @{ tipo = 'set'; valores = @('status', 'disable', 'enable') }
    }

    'rdp'         = @{
        'SubAction' = @{ tipo = 'set'; valores = @('status', 'on', 'off', 'h264-on', 'h264-off') }
    }

    'sunshine'    = @{
        'SubAction' = @{ tipo = 'set'; valores = @('status', 'install', 'start', 'stop', 'firewall-open', 'firewall-close') }
    }

    # O hyperv so lista, e listar nao tem parametro: allowlist vazia, como
    # memory e processes. A rota /hyperv atende so a leitura nesta fatia.
    'hyperv'      = @{}
}

# ============================================================
# AUXILIARES
# ============================================================

function Write-Log([string]$msg) {
    try {
        Add-Content -Path $F_LOG -Value ("{0:yyyy-MM-dd HH:mm:ss.fff} {1}" -f (Get-Date), $msg) -Encoding UTF8
    } catch {
        # Log e' diagnostico, nao funcao: falhar ao registrar nao pode
        # derrubar o worker e deixar um processo elevado de pe sem laco.
    }
}

function Test-WorkerOcioso([datetime]$desde, [datetime]$agora, [int]$limiteS, [bool]$temFilho) {
    # Acao em andamento nunca e' ociosidade, por mais longa que seja.
    if ($temFilho) { return $false }
    return (($agora - $desde).TotalSeconds -ge $limiteS)
}

function Write-Heartbeat {
    try {
        # -Encoding ASCII e nao UTF8: o Set-Content UTF8 do 5.1 grava BOM, e
        # aqui o conteudo nem e' lido — o PHP olha o mtime. Sem BOM o arquivo
        # fica com os bytes que aparenta ter.
        Set-Content -Path $F_HEARTBEAT -Value ([DateTimeOffset]::UtcNow.ToUnixTimeMilliseconds()) -Encoding ASCII
    } catch {
        # Disco ocupado num tique nao e' motivo para sair: o PHP tolera
        # heartbeat velho por alguns segundos.
    }
}

<#
    Um valor como literal de string do PowerShell, entre apostrofos.

    Apostrofo SIMPLES porque dentro dele o PowerShell nao interpola nada: nem
    $variavel, nem $(...), nem crase. O unico escape e' dobrar o apostrofo — e
    o PowerShell conta como apostrofo nao so o ', mas tambem as quatro aspas
    simples curvas (U+2018, U+2019, U+201A, U+201B). Dobrar so o ' deixava a
    curva fechar a string. Quem dobra as cinco e' o proprio PowerShell, pelo
    EscapeSingleQuotedStringContent. E' o mesmo criterio do
    PsScriptBuilder::literal() do lado PHP.
#>
function ConvertTo-PsLiteral([string]$value) {
    return "'" + [System.Management.Automation.Language.CodeGeneration]::EscapeSingleQuotedStringContent($value) + "'"
}

<#
    Os itens que uma regra 'lista' aceita, lidos do config na hora.

    Recebe o nome da fonte. Devolve, para 'tweaks', as chaves do tweaks.json
    cujo Type nao e' Button nem Combobox; para 'debloat', os pacotes do
    debloat.json; para 'dns', as chaves do dns.json. Fonte desconhecida ou arquivo ilegivel devolve lista vazia,
    e lista vazia recusa todo item.
#>
function Get-PhportoListaPermitida([string]$fonte) {
    try {
        $dados = Get-Content -Path (Join-Path $CONFIG_DIR ($fonte + '.json')) -Raw -Encoding UTF8 | ConvertFrom-Json
    } catch {
        Write-Log "lista '$fonte' ilegivel: $($_.Exception.Message)"
        return @()
    }

    switch ($fonte) {
        'tweaks'  { return @($dados.PSObject.Properties | Where-Object { $_.Value.Type -notin 'Button', 'Combobox' } | ForEach-Object { $_.Name }) }
        'debloat' { return @($dados | ForEach-Object { [string]$_ }) }
        'dns'     { return @($dados.PSObject.Properties | ForEach-Object { $_.Name }) }
        default   { return @() }
    }
}

<#
    Valida um job contra a allowlist e devolve os pares nome/valor aceitos.

    Lanca em qualquer desvio. Quem chama trata a excecao como recusa: nada
    executa, e o motivo vai para o arquivo de conclusao.
#>
function Test-Job($job) {
    if ($null -eq $job) { throw 'job vazio' }
    if ($job.nonce -ne $Nonce) { throw "nonce de outra execucao do servidor (obsoleto)" }

    $acao = [string]$job.acao
    if (-not $ALLOWLIST.ContainsKey($acao)) { throw "acao fora da allowlist: '$acao'" }

    $permitidos = $ALLOWLIST[$acao]
    $aceitos    = [ordered]@{}

    # params PRECISA ser objeto JSON, e a checagem nao e' preciosismo: um
    # "params": [] vira ARRAY aqui, e PSObject.Properties de um array vazio
    # expoe Count e Length — que apareceriam como parametros inventados e
    # fariam a allowlist recusar uma acao legitima. Medido: a acao 'audit'
    # sem parametros foi recusada por "parametro fora da allowlist: 'Count'".
    # Lista vazia e ausencia significam a mesma coisa: nenhum parametro.
    $params = $job.params

    if ($null -ne $params -and $params -isnot [System.Management.Automation.PSCustomObject]) {
        if ($params -is [System.Array] -and $params.Count -eq 0) {
            $params = $null
        } else {
            throw 'params precisa ser objeto JSON'
        }
    }

    if ($null -ne $params) {
        foreach ($p in $params.PSObject.Properties) {
            $nome = $p.Name

            if (-not $permitidos.ContainsKey($nome)) {
                throw "parametro fora da allowlist para '$acao': '$nome'"
            }

            $regra = $permitidos[$nome]
            $valor = $p.Value

            switch ($regra.tipo) {
                'set' {
                    $texto   = [string]$valor
                    $aceitas = @($regra.valores)
                    if ($regra.fonte) { $aceitas += @(Get-PhportoListaPermitida $regra.fonte) }
                    $casou = $aceitas | Where-Object { $_ -ieq $texto } | Select-Object -First 1
                    if (-not $casou) { throw "valor fora do conjunto em '$nome'" }
                    # Guarda a grafia da LISTA, nao a que veio no arquivo.
                    $aceitos[$nome] = [string]$casou
                }
                'text' {
                    $texto = [string]$valor
                    # BYTES, nao caracteres: o lado PHP compara com strlen(),
                    # e .Length aqui contaria um acento como um.
                    if ([System.Text.Encoding]::UTF8.GetByteCount($texto) -gt $MAX_PARAM_BYTES) {
                        throw "'$nome' passou do teto de bytes"
                    }
                    if ($texto.Contains([char]0)) { throw "'$nome' tem byte nulo" }
                    $aceitos[$nome] = $texto
                }
                'int' {
                    $n = 0
                    if (-not [int]::TryParse([string]$valor, [ref]$n)) { throw "'$nome' nao e' inteiro" }
                    if ($n -lt $regra.min -or $n -gt $regra.max) { throw "'$nome' fora da faixa" }
                    $aceitos[$nome] = $n
                }
                'flag' {
                    if ($valor -eq $true) { $aceitos[$nome] = $true }
                }
                'lista' {
                    $texto = [string]$valor
                    if ([System.Text.Encoding]::UTF8.GetByteCount($texto) -gt $MAX_PARAM_BYTES) {
                        throw "'$nome' passou do teto de bytes"
                    }
                    if ($texto.Contains([char]0)) { throw "'$nome' tem byte nulo" }

                    $permitidos = @(Get-PhportoListaPermitida $regra.fonte)
                    if ($permitidos.Count -eq 0) { throw "lista '$($regra.fonte)' indisponivel" }

                    $itens = New-Object System.Collections.Generic.List[string]
                    foreach ($bruto in ($texto -split ',')) {
                        $item = $bruto.Trim()
                        if ($item -eq '') { continue }
                        $casou = $permitidos | Where-Object { $_ -ieq $item } | Select-Object -First 1
                        if (-not $casou) { throw "item fora da lista em '$nome': '$item'" }
                        if ($itens.Contains([string]$casou)) { throw "item repetido em '$nome': '$casou'" }
                        $itens.Add([string]$casou)
                    }
                    if ($itens.Count -eq 0) { throw "'$nome' sem nenhum item" }
                    # Guarda a grafia da LISTA, como o 'set'.
                    $aceitos[$nome] = [string[]]$itens.ToArray()
                }
                default { throw "regra desconhecida para '$nome'" }
            }
        }
    }

    return @{ acao = $acao; params = $aceitos }
}

<#
    Gera o script que chama a acao e devolve o caminho dele.

    O JOB NUNCA VIRA LINHA DE COMANDO. Os valores ja validados viajam como
    dado dentro de um .ps1, e o que vai para a linha de
    comando do filho e' apenas o caminho desse arquivo — a mesma regra do
    cmd.sh no lado do WSL, pelo mesmo motivo: qualquer escape que se
    escrevesse falharia em algum valor, e a falha apareceria como erro DO
    COMANDO, mandando quem depura para o lugar errado.

    O ALVO E' O BOOTSTRAP DESTE REPOSITORIO. O caminho vem de $BOOTSTRAP,
    resolvido no bloco de constantes: bootstrap.ps1 e worker.ps1 sao irmaos na
    mesma pasta e viajam juntos, entao nao ha o que configurar, nao ha parametro
    para manter em dia, e nao ha projeto externo a apontar.

    OS PARAMETROS VIAJAM COMO DADO, nunca como codigo. Nomes e valores viram
    JSON, o JSON vira base64, e o script gerado decodifica, monta o hashtable e
    passa por splatting. O alfabeto do base64 nao tem aspa de especie nenhuma,
    entao nenhum caractere de um valor chega ao parser. Antes eles iam como
    literais de apostrofo num hashtable, e uma aspa curva (U+2019) no -Apps do
    install bastava para fechar a string e rodar o resto como codigo em
    integridade Alta. Com o valor fora do codigo, nao ha escape que possa
    falhar.

    O ConvertFrom-Json do 5.1 devolve PSCustomObject, e o splatting precisa de
    hashtable: a conversao e' feita a mao, propriedade por propriedade. Lista
    volta como string[], com um item so inclusive.

    O *>&1 funde todos os fluxos no de sucesso. E' o equivalente do
    "exec 2>&1" do lado bash, e e' o que faz a ordem das linhas ser a real —
    as acoes escrevem por Write-Host, que sem isso nao entra na captura.

    A RECUSA VOLTA COMO 1. O despachante do bootstrap nao chama exit: ele lanca,
    depois de escrever o motivo por Write-Status (sem elevacao, acao fora do
    mapa, funcao ausente, config ausente). Sem o try/catch, a excecao mataria o
    script ANTES das linhas que gravam o codigo de saida — o worker nao acharia
    arquivo de codigo e registraria exit nulo, que na tela nao se distingue de
    "terminou sem dizer nada". Com ele, recusa e' exit 1, que e' o mesmo que o
    o winutil-cli devolvia quando recusava por falta de Administrador.
#>
function New-InvocationScript($validado, [string]$id) {
    $arqExit = Join-Path $Dir ('win-exit-' + $id + '.txt')

    $dados = [ordered]@{}

    foreach ($nome in $validado.params.Keys) {
        $valor = $validado.params[$nome]

        if ($valor -is [bool]) {
            # A allowlist so guarda flag verdadeira; falsa e' ausencia.
            if ($valor) { $dados[$nome] = $true }
        } elseif ($valor -is [int]) {
            $dados[$nome] = $valor
        } elseif ($valor -is [array]) {
            $dados[$nome] = [string[]]@($valor | ForEach-Object { [string]$_ })
        } else {
            $dados[$nome] = [string]$valor
        }
    }

    $json  = ConvertTo-Json -InputObject $dados -Compress -Depth 5
    $carga = [System.Convert]::ToBase64String([System.Text.Encoding]::UTF8.GetBytes($json))

    $linhas = New-Object System.Collections.Generic.List[string]
    $linhas.Add('$ErrorActionPreference = ' + (ConvertTo-PsLiteral 'Continue'))
    $linhas.Add('[Console]::OutputEncoding = [System.Text.UTF8Encoding]::new($true)')
    $linhas.Add('$phportoRecusado = $false')
    $linhas.Add('try {')
    $linhas.Add('    $phportoJson   = [System.Text.Encoding]::UTF8.GetString([System.Convert]::FromBase64String(' + (ConvertTo-PsLiteral $carga) + '))')
    $linhas.Add('    $phportoParams = @{}')
    $linhas.Add('    foreach ($phportoPar in ($phportoJson | ConvertFrom-Json).PSObject.Properties) {')
    $linhas.Add('        if ($phportoPar.Value -is [System.Array]) { $phportoParams[$phportoPar.Name] = [string[]]$phportoPar.Value } else { $phportoParams[$phportoPar.Name] = $phportoPar.Value }')
    $linhas.Add('    }')
    $linhas.Add('    . ' + (ConvertTo-PsLiteral $BOOTSTRAP))
    $linhas.Add('    Invoke-PhportoWinAction -Action ' + (ConvertTo-PsLiteral $validado.acao) + ' -Params $phportoParams *>&1')
    $linhas.Add('} catch {')
    $linhas.Add('    Write-Host ("[phporto] " + $_.Exception.Message)')
    $linhas.Add('    $phportoRecusado = $true')
    $linhas.Add('}')

    # O CODIGO DE SAIDA VOLTA POR ARQUIVO, e nao pelo objeto do processo.
    #
    # Medido: o Process devolvido por Start-Process -PassThru chega com
    # ExitCode VAZIO depois de HasExited virar verdadeiro — o handle nao fica
    # retido, e a saida seria chamar WaitForExit(), que e' exatamente o
    # bloqueio que este worker nao pode ter (o heartbeat pararia). Quem sabe o
    # codigo com certeza e' o proprio filho, e ele grava antes de sair.
    #
    # $LASTEXITCODE fica nulo quando nada nativo rodou nem houve exit
    # explicito; nesse caso o certo e' zero.
    $linhas.Add('$code = if ($phportoRecusado) { 1 } elseif ($null -eq $LASTEXITCODE) { 0 } else { $LASTEXITCODE }')
    $linhas.Add('[System.IO.File]::WriteAllText(' + (ConvertTo-PsLiteral $arqExit) + ', [string]$code, [System.Text.UTF8Encoding]::new($false))')
    $linhas.Add('exit $code')

    $caminho = Join-Path $Dir ('win-exec-' + $id + '.ps1')

    # CRLF COM BOM: o 5.1 le script sem BOM como ANSI e a acentuacao vira
    # lixo. E' a regra INVERSA da do cmd.sh, e o porque esta no
    # PsScriptBuilder e no .gitattributes.
    $texto = ($linhas -join "`r`n") + "`r`n"
    [System.IO.File]::WriteAllText($caminho, $texto, [System.Text.UTF8Encoding]::new($true))

    return $caminho
}

<#
    Escreve o arquivo de conclusao — o UNICO sinal de que um job terminou.

    ELE CARREGA MAIS DO QUE O PHP QUE ESTA ESPERANDO PRECISA, e isso e'
    deliberado. Quem espera ja sabe qual acao pediu; quem RECOLHE depois nao
    sabe de nada. Se o php -S sair entre o filho terminar e a linha ser gravada
    no banco, este arquivo e' tudo o que resta da execucao — e sem a acao, os
    parametros e a hora de fim, o recolhimento gravaria uma linha que nao diz
    o que aconteceu nem quando.

    A HORA E' A DE FIM, EM UTC, no formato que o CURRENT_TIMESTAMP do banco
    usa. O recolhimento grava esse valor em vez de deixar o banco carimbar a
    hora da abertura da pagina: a listagem ordena por created_at, entao a hora
    errada nao seria so um detalhe no texto — poria a execucao de ontem no topo
    do historico de hoje.

    InvariantCulture no ToString nao e' preciosismo: no formato do .NET o ':'
    significa "separador de hora da cultura", nao dois-pontos literais. Numa
    cultura que use outro separador o carimbo sairia num formato que o banco
    nao entende.
#>
function Write-Done {
    param(
        [string]$id,
        $exit,
        [int]$ms,
        [string]$nota,
        [string]$acao = '',
        $params = $null
    )

    $done = Join-Path $Dir ('win-done-' + $id + '.json')
    $dados = [ordered]@{
        id     = $id
        exit   = $exit
        ms     = $ms
        nota   = $nota
        acao   = $acao
        params = if ($null -eq $params) { [ordered]@{} } else { $params }
        fim    = [DateTime]::UtcNow.ToString(
            'yyyy-MM-dd HH:mm:ss',
            [System.Globalization.CultureInfo]::InvariantCulture
        )
    }
    # WriteAllText sem BOM: este arquivo e' JSON lido pelo PHP, e BOM em JSON
    # faz json_decode devolver null. O Set-Content -Encoding UTF8 poria BOM.
    # -Depth 5 porque agora ha objeto aninhado: com o padrao (2) o hashtable de
    # params sairia como o TEXTO do tipo .NET em vez de objeto JSON.
    [System.IO.File]::WriteAllText(
        $done,
        ($dados | ConvertTo-Json -Compress -Depth 5),
        [System.Text.UTF8Encoding]::new($false)
    )
}

# ============================================================
# PROVA — a primeira coisa que o PHP espera ver
# ============================================================

$admin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)

if (-not $admin) {
    # Sem elevacao este worker nao serve para nada: o despachante do
    # bootstrap recusa toda acao por falta de Administrador. Melhor nao
    # escrever prova e deixar o PHP dizer que a permissao nao foi concedida,
    # em vez de aceitar jobs para recusar um por um.
    Write-Log "recusado: processo nao esta elevado (pid=$PID)"
    exit 1
}

# Prova em ASCII e sem BOM, para o PHP casar o conteudo sem tirar bytes antes.
Set-Content -Path $F_PROVA -Value "PID=$PID;ADMIN=True;NONCE=$Nonce" -Encoding ASCII
Write-Heartbeat
Write-Log "iniciado pid=$PID pai=$ParentPid bootstrap='$BOOTSTRAP'"

# ============================================================
# LACO — 500 ms, cinco tarefas
# ============================================================

$filho       = $null   # processo da acao em andamento
$filhoId     = $null
$filhoT0     = $null
$filhoOut    = $null
$filhoScript = $null

# A acao e os parametros do job em andamento. Guardados porque o arquivo de
# conclusao os carrega: quem recolhe a execucao depois nao tem outra fonte.
$filhoAcao   = ''
$filhoParams = $null

# A ultima vez em que houve trabalho: job lido ou filho de pe. E' daqui que a
# ociosidade conta.
$ultimaAtividade = Get-Date

function Stop-Filho([string]$motivo) {
    # Alta contra Alta: AQUI o taskkill funciona. E' o PHP, em Media, que nao
    # consegue — por isso o cancelamento chega como ordem e a morte acontece
    # deste lado.
    if ($null -ne $script:filho -and -not $script:filho.HasExited) {
        try {
            & taskkill.exe /F /T /PID $script:filho.Id | Out-Null
            Write-Log "filho $($script:filho.Id) morto: $motivo"
        } catch {
            Write-Log "falha ao matar filho: $($_.Exception.Message)"
        }
    }
}

function Clear-Filho {
    # O script gerado e os arquivos auxiliares somem junto: eles carregam os
    # valores do job, e deixar isso em files/ seria manter uma copia do que
    # foi executado fora do banco, que e' onde o registro deve viver.
    if ($null -ne $script:filhoScript -and (Test-Path $script:filhoScript)) {
        Remove-Item $script:filhoScript -Force -ErrorAction SilentlyContinue
    }

    if ($null -ne $script:filhoId) {
        foreach ($sufixo in @('win-exit-', 'win-err-')) {
            $alvo = Join-Path $Dir ($sufixo + $script:filhoId + '.txt')
            if (Test-Path $alvo) { Remove-Item $alvo -Force -ErrorAction SilentlyContinue }
        }
    }
    $script:filho       = $null
    $script:filhoId     = $null
    $script:filhoT0     = $null
    $script:filhoOut    = $null
    $script:filhoScript = $null
    $script:filhoAcao   = ''
    $script:filhoParams = $null
}

<#
    Encerra o job em andamento DEIXANDO SINAL DE FIM, e sai do laco.

    ISTO NASCEU DE UMA MEDICAO, e a medicao esta no proprio win-worker.log:

      13:02:17  job aceito id=3d7456dba545 acao=tweaks filho=13364
      13:04:13  pai 9260 desapareceu: encerrando por conta propria
      13:04:14  filho 13364 morto: pai desapareceu

    Duas execucoes de tweaks acabaram assim. O php -S saiu no meio da espera, o
    worker matou o filho, e o laco terminava sem escrever arquivo de conclusao
    nenhum — entao nao sobrava sinal de fim, e as duas execucoes NUNCA viraram
    linha no banco. Mexeram no registro e nos servicos do Windows, e o
    historico nao sabe que existiram.

    Agora sobra. O par saida+conclusao fica no disco, o proximo carregamento da
    /win o recolhe, e a linha entra com a hora real e a nota de que a acao foi
    interrompida.

    O CODIGO DE SAIDA E' NULO de proposito. O filho foi morto: nao houve codigo.
    Nulo e' o que o banco guarda para "nao se sabe", e e' diferente de zero —
    ninguem deve ler esta linha como sucesso. A saida parcial que o filho
    escreveu ate ali fica, porque e' a unica pista do que chegou a acontecer.
#>
function Stop-FilhoComSinal([string]$motivo) {
    if ($null -eq $script:filho) {
        return
    }

    $ms = if ($null -ne $script:filhoT0) {
        [int]((Get-Date) - $script:filhoT0).TotalMilliseconds
    } else {
        0
    }

    Stop-Filho $motivo
    Write-Done $script:filhoId $null $ms 'interrompido' $script:filhoAcao $script:filhoParams
    Write-Log "job interrompido id=$($script:filhoId) motivo='$motivo': conclusao deixada para recolhimento"

    # Limpa os auxiliares e PRESERVA o par saida+conclusao: e' exatamente o que
    # o Clear-Filho faz. Sem esta chamada o script gerado ficava para tras — os
    # dois win-exec-*.ps1 de 09/09 que sobraram em files/ sao desse caminho,
    # que saia do laco sem passar por limpeza nenhuma.
    Clear-Filho
}

while ($true) {
    Write-Heartbeat

    # --- 1. o pai ainda existe? --------------------------------------------
    # Get-Process e' checagem em processo, sem custo de spawn. Reuso de PID e'
    # possivel no Windows, mas o marcador do lado PHP tambem confere o
    # heartbeat, e as duas coisas juntas fecham a janela na pratica.
    if (-not (Get-Process -Id $ParentPid -ErrorAction SilentlyContinue)) {
        Write-Log "pai $ParentPid desapareceu: encerrando por conta propria"
        Stop-FilhoComSinal 'pai desapareceu'
        break
    }

    # --- 2. ordem de desligar ----------------------------------------------
    if (Test-Path $F_DESLIGAR) {
        Remove-Item $F_DESLIGAR -Force -ErrorAction SilentlyContinue
        Write-Log 'ordem de desligar recebida'
        Stop-FilhoComSinal 'desligando'
        break
    }

    # --- 3. ordem de cancelar a acao em andamento --------------------------
    if (Test-Path $F_CANCELAR) {
        Remove-Item $F_CANCELAR -Force -ErrorAction SilentlyContinue

        if ($null -ne $filho -and -not $filho.HasExited) {
            $ms = [int]((Get-Date) - $filhoT0).TotalMilliseconds
            Stop-Filho 'cancelado'
            Write-Done $filhoId $null $ms 'cancelado' $filhoAcao $filhoParams
            Clear-Filho
        } else {
            Write-Log 'ordem de cancelar sem acao em andamento: ignorada'
        }
    }

    # --- 4. o filho terminou? ----------------------------------------------
    if ($null -ne $filho -and $filho.HasExited) {
        $ms = [int]((Get-Date) - $filhoT0).TotalMilliseconds

        # O fluxo de erro nao vem sempre pelo *>&1: um Write-Error do script
        # chamado pode escapar para o stderr do processo. Medido — um
        # Write-Error do alvo nao apareceu na saida e estava no arquivo de
        # erro. Juntar os dois e' o que o Runner do WSL ja faz com o stderr
        # residual do shell de login, pelo mesmo motivo: o que sobrou num
        # canto tem de aparecer na tela.
        $arqErr = Join-Path $Dir ('win-err-' + $filhoId + '.txt')
        if (Test-Path $arqErr) {
            try {
                $residuo = [System.IO.File]::ReadAllText($arqErr)
                if ($residuo.Trim().Length -gt 0) {
                    [System.IO.File]::AppendAllText($filhoOut, $residuo, [System.Text.UTF8Encoding]::new($false))
                }
            } catch {
                Write-Log "falha ao juntar o stderr: $($_.Exception.Message)"
            }
            Remove-Item $arqErr -Force -ErrorAction SilentlyContinue
        }

        $arqExit = Join-Path $Dir ('win-exit-' + $filhoId + '.txt')
        $code    = $null
        if (Test-Path $arqExit) {
            $bruto = ([System.IO.File]::ReadAllText($arqExit)).Trim()
            $n     = 0
            if ([int]::TryParse($bruto, [ref]$n)) { $code = $n }
            Remove-Item $arqExit -Force -ErrorAction SilentlyContinue
        }

        Write-Done $filhoId $code $ms '' $filhoAcao $filhoParams
        Write-Log "filho concluido id=$filhoId exit=$code ms=$ms"
        Clear-Filho
    }

    # --- 5. job novo, so quando nao ha filho de pe -------------------------
    # Uma acao por vez, de proposito: duas acoes ao mesmo tempo
    # mexeriam no mesmo registro e nos mesmos servicos. O php -S atende em
    # serie, entao nem havia como pedir duas.
    if ($null -eq $filho -and (Test-Path $F_JOB)) {
        $bruto = $null
        try {
            $bruto = Get-Content $F_JOB -Raw -Encoding UTF8
        } catch {
            Write-Log "falha ao ler o job: $($_.Exception.Message)"
        }

        # Some com o arquivo ANTES de executar: job lido duas vezes seria a
        # mesma acao executada duas vezes, que e' o problema que o token de
        # uso unico resolve do outro lado.
        Remove-Item $F_JOB -Force -ErrorAction SilentlyContinue

        if ($null -ne $bruto) {
            $ultimaAtividade = Get-Date
            $id = [guid]::NewGuid().ToString('N').Substring(0, 12)

            try {
                $job       = $bruto | ConvertFrom-Json
                $validado  = Test-Job $job
                $script:filhoScript = New-InvocationScript $validado $id
                $script:filhoOut    = Join-Path $Dir ('win-out-' + $id + '.txt')
                $erro               = Join-Path $Dir ('win-err-' + $id + '.txt')

                # O caminho do script gerado e' o UNICO conteudo variavel na
                # linha de comando, e quem o escreveu foi este codigo. Aspas
                # explicitas porque a pasta do projeto pode ter espaco.
                $argLine = '-NoProfile -NonInteractive -ExecutionPolicy Bypass -File "' + $script:filhoScript + '"'

                $script:filho = Start-Process -FilePath 'powershell.exe' `
                    -ArgumentList $argLine `
                    -RedirectStandardOutput $script:filhoOut `
                    -RedirectStandardError $erro `
                    -WindowStyle Hidden `
                    -PassThru

                $script:filhoId     = $id
                $script:filhoT0     = Get-Date
                $script:filhoAcao   = $validado.acao
                $script:filhoParams = $validado.params
                Write-Log "job aceito id=$id acao=$($validado.acao) filho=$($script:filho.Id)"
            } catch {
                # Recusa e' resposta: o PHP esta esperando um arquivo de
                # conclusao, e sem ele ficaria sondando ate o timeout.
                Write-Log "job RECUSADO id=$id : $($_.Exception.Message)"
                [System.IO.File]::WriteAllText(
                    (Join-Path $Dir ('win-out-' + $id + '.txt')),
                    "[phporto] job recusado pela allowlist do worker: $($_.Exception.Message)`r`n",
                    [System.Text.UTF8Encoding]::new($false)
                )
                # Sem acao nem parametros: a recusa aconteceu ANTES de a
                # allowlist devolver algo validado, e inventar um nome aqui
                # seria gravar como fato o que o worker justamente nao aceitou.
                # Quem recolher esta conclusao registra o que ha — a saida
                # acima diz o motivo.
                Write-Done $id 126 0 'recusado'
                Clear-Filho
            }
        }
    }

    # --- 6. ocioso demais? -------------------------------------------------
    # Depois do job novo, para um job que acabou de chegar contar como
    # atividade antes da conta. Sair aqui nao deixa filho para tras: so sai
    # quem nao tem filho de pe.
    if ($null -ne $filho) { $ultimaAtividade = Get-Date }
    if (Test-WorkerOcioso $ultimaAtividade (Get-Date) $IDLE_TIMEOUT_S ($null -ne $filho)) {
        Write-Log "ocioso por $IDLE_TIMEOUT_S s: encerrando por conta propria"
        break
    }

    Start-Sleep -Milliseconds $TICK_MS
}

Remove-Item $F_HEARTBEAT -Force -ErrorAction SilentlyContinue
Remove-Item $F_PROVA -Force -ErrorAction SilentlyContinue
Write-Log "fim pid=$PID"
