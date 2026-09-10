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
# 'set'  = conjunto fechado de valores aceitos
# 'text' = texto livre, com teto de bytes
# 'int'  = inteiro numa faixa
# 'flag' = switch, so entra na chamada quando verdadeiro

$MAX_PARAM_BYTES = 4096

$ALLOWLIST = @{
    'audit'       = @{}
    'debloat'     = @{}
    'memory'      = @{}
    'processes'   = @{}

    # Sem 'State', e AGORA A ALLOWLIST E' A UNICA COISA QUE O IMPEDE.
    #
    # Antes havia duas trancas: esta lista e o param() do winutil-cli.ps1, que
    # nao declarava State — passar -State devolvia NamedParameterNotFound e
    # nada executava. Aquele ponto de entrada nao existe mais, e o bootstrap
    # faz splatting direto em Invoke-Performance, que DECLARA
    # -State [ValidateSet('on','off')]. Ou seja: pos ou nao, 'State' aqui
    # decide sozinho se o desligar do plano de energia fica alcancavel pela
    # tela. Deixar de fora e' escolha, nao heranca.
    'performance' = @{}

    'tweaks'      = @{
        'Preset' = @{ tipo = 'set'; valores = @('standard', 'minimal', 'advanced') }
        'Undo'   = @{ tipo = 'flag' }
    }
    'dns'         = @{
        'Provider'     = @{ tipo = 'set'; valores = @(
                'Google', 'Cloudflare', 'Cloudflare_Malware', 'Cloudflare_Malware_Adult',
                'Open_DNS', 'Quad9', 'AdGuard_Ads_Trackers',
                'AdGuard_Ads_Trackers_Malware_Adult', 'Custom', 'Default', 'DHCP'
            )
        }
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
    $variavel, nem $(...), nem crase. O unico escape e' o proprio apostrofo,
    dobrado. E' o mesmo criterio do PsScriptBuilder::literal() do lado PHP, e
    e' o que permite um valor validado entrar num script gerado sem virar
    codigo.
#>
function ConvertTo-PsLiteral([string]$value) {
    return "'" + ($value -replace "'", "''") + "'"
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
                    $texto = [string]$valor
                    $casou = $regra.valores | Where-Object { $_ -ieq $texto } | Select-Object -First 1
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
                default { throw "regra desconhecida para '$nome'" }
            }
        }
    }

    return @{ acao = $acao; params = $aceitos }
}

<#
    Gera o script que chama a acao e devolve o caminho dele.

    O JOB NUNCA VIRA LINHA DE COMANDO. Os valores ja validados sao emitidos
    como literais de string dentro de um .ps1, e o que vai para a linha de
    comando do filho e' apenas o caminho desse arquivo — a mesma regra do
    cmd.sh no lado do WSL, pelo mesmo motivo: qualquer escape que se
    escrevesse falharia em algum valor, e a falha apareceria como erro DO
    COMANDO, mandando quem depura para o lugar errado.

    O ALVO E' O BOOTSTRAP DESTE REPOSITORIO. O caminho vem de $BOOTSTRAP,
    resolvido no bloco de constantes: bootstrap.ps1 e worker.ps1 sao irmaos na
    mesma pasta e viajam juntos, entao nao ha o que configurar, nao ha parametro
    para manter em dia, e nao ha projeto externo a apontar.

    OS PARAMETROS VAO POR SPLATTING, num hashtable literal, e nao como
    -Nome valor soltos na chamada. Assim um nome de parametro tambem e' literal
    de string, e nao ha ponto nenhum da linha em que um valor validado possa
    virar outra coisa que nao dado.

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

    $pares = New-Object System.Collections.Generic.List[string]

    foreach ($nome in $validado.params.Keys) {
        $valor = $validado.params[$nome]

        if ($valor -is [bool]) {
            # A allowlist so guarda flag verdadeira; falsa e' ausencia.
            if ($valor) { $pares.Add((ConvertTo-PsLiteral $nome) + ' = $true') }
        } elseif ($valor -is [int]) {
            $pares.Add((ConvertTo-PsLiteral $nome) + " = $valor")
        } else {
            $pares.Add((ConvertTo-PsLiteral $nome) + ' = ' + (ConvertTo-PsLiteral ([string]$valor)))
        }
    }

    $hash = if ($pares.Count -eq 0) { '@{}' } else { '@{ ' + ($pares -join '; ') + ' }' }

    $linhas = New-Object System.Collections.Generic.List[string]
    $linhas.Add('$ErrorActionPreference = ' + (ConvertTo-PsLiteral 'Continue'))
    $linhas.Add('[Console]::OutputEncoding = [System.Text.UTF8Encoding]::new($true)')
    $linhas.Add('$phportoRecusado = $false')
    $linhas.Add('try {')
    $linhas.Add('    . ' + (ConvertTo-PsLiteral $BOOTSTRAP))
    $linhas.Add('    Invoke-PhportoWinAction -Action ' + (ConvertTo-PsLiteral $validado.acao) + ' -Params ' + $hash + ' *>&1')
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

function Write-Done([string]$id, $exit, [int]$ms, [string]$nota) {
    $done = Join-Path $Dir ('win-done-' + $id + '.json')
    $dados = [ordered]@{
        id    = $id
        exit  = $exit
        ms    = $ms
        nota  = $nota
    }
    # WriteAllText sem BOM: este arquivo e' JSON lido pelo PHP, e BOM em JSON
    # faz json_decode devolver null. O Set-Content -Encoding UTF8 poria BOM.
    [System.IO.File]::WriteAllText($done, ($dados | ConvertTo-Json -Compress), [System.Text.UTF8Encoding]::new($false))
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

$filho     = $null   # processo da acao em andamento
$filhoId   = $null
$filhoT0   = $null
$filhoOut  = $null
$filhoScript = $null

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
}

while ($true) {
    Write-Heartbeat

    # --- 1. o pai ainda existe? --------------------------------------------
    # Get-Process e' checagem em processo, sem custo de spawn. Reuso de PID e'
    # possivel no Windows, mas o marcador do lado PHP tambem confere o
    # heartbeat, e as duas coisas juntas fecham a janela na pratica.
    if (-not (Get-Process -Id $ParentPid -ErrorAction SilentlyContinue)) {
        Write-Log "pai $ParentPid desapareceu: encerrando por conta propria"
        Stop-Filho 'pai desapareceu'
        break
    }

    # --- 2. ordem de desligar ----------------------------------------------
    if (Test-Path $F_DESLIGAR) {
        Remove-Item $F_DESLIGAR -Force -ErrorAction SilentlyContinue
        Write-Log 'ordem de desligar recebida'
        Stop-Filho 'desligando'
        break
    }

    # --- 3. ordem de cancelar a acao em andamento --------------------------
    if (Test-Path $F_CANCELAR) {
        Remove-Item $F_CANCELAR -Force -ErrorAction SilentlyContinue

        if ($null -ne $filho -and -not $filho.HasExited) {
            $ms = [int]((Get-Date) - $filhoT0).TotalMilliseconds
            Stop-Filho 'cancelado'
            Write-Done $filhoId $null $ms 'cancelado'
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

        Write-Done $filhoId $code $ms ''
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

                $script:filhoId = $id
                $script:filhoT0 = Get-Date
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
                Write-Done $id 126 0 'recusado'
                Clear-Filho
            }
        }
    }

    Start-Sleep -Milliseconds $TICK_MS
}

Remove-Item $F_HEARTBEAT -Force -ErrorAction SilentlyContinue
Remove-Item $F_PROVA -Force -ErrorAction SilentlyContinue
Write-Log "fim pid=$PID"
