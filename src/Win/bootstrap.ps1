<#
.SYNOPSIS
    Monta o ambiente que as acoes do Windows assumem pronto e despacha uma
    acao pelo nome.

.DESCRIPTION
    POR QUE ELE EXISTE:

    As acoes de actions/ vieram do winutil-cli, e la nenhuma delas e'
    auto-suficiente. O ponto de entrada daquele projeto montava quatro coisas
    antes de carregar qualquer acao, e NENHUMA viaja junto quando se copia um
    Invoke-*.ps1 sozinho:

      Write-Status ... a funcao de saida que as treze acoes chamam
      $sync.configs .. o hashtable global com os JSON de configuracao
      dot-source ..... lib/ ANTES de actions/, porque as acoes chamam as
                       primitivas
      $root .......... Invoke-Audit procura $root\audit\audit.ps1 e
                       Invoke-Memory procura $root\tools — contrato implicito,
                       que nao aparece em nenhuma assinatura e sumiria calado

    Este arquivo e' o equivalente daquele ponto de entrada, e e' o unico lugar
    do PHPorto que sabe montar o ambiente.

    POR QUE O DESPACHANTE MORA AQUI, e nao no script que o worker gera: o mapa
    de 'gpu' para Invoke-GPU e de 'gdid' para Invoke-Gdid nao e' transformacao
    de texto, e' tabela — 'dns' vira Invoke-DNS, 'gdid' vira Invoke-Gdid, e
    nenhuma regra de maiuscula produz as duas. Tabela mora em codigo revisado;
    o script gerado, que ninguem le, fica com duas linhas.

    POR QUE TUDO E' $global: este processo carrega o ambiente, roda UMA acao e
    morre. As acoes resolvem $sync e $root por escopo dinamico, na hora da
    chamada, e $global: e' o unico escopo que responde igual venha a chamada do
    script gerado pelo worker, de um teste do Pester ou de um prompt.

    A CHECAGEM DE ADMINISTRADOR ESTA NO DESPACHANTE, e nao no carregamento. O
    worker.ps1 ja recusa subir sem elevacao — sai com 1 antes de escrever a
    prova —, entao esta e' a segunda tranca, para quem carregar este arquivo na
    mao. Poe-la no carregamento tornaria impossivel testar o bootstrap sem um
    terminal elevado, e o que se ganharia era zero: nenhuma acao roda sem
    passar pelo despachante.

    CONTRATO DE FALHA: o despachante RECUSA lancando, depois de escrever o
    motivo por Write-Status. Quem chama decide o codigo de saida — o script
    gerado pelo worker envolve a chamada e grava o codigo em arquivo, porque o
    ExitCode do objeto de processo chega vazio (ver worker.ps1).

    ENCODING — A REGRA INVERTIDA, e a armadilha que vem com ela:

    Este .ps1 e' CRLF COM BOM, o inverso do cmd.sh do lado do WSL (LF, sem
    BOM), e o .gitattributes preserva os bytes com "*.ps1 -text". O motivo e' o
    PowerShell 5.1, o unico instalado nesta maquina: ele le script sem BOM como
    ANSI.

    E a consequencia nao para na acentuacao virar lixo. Medido: num arquivo
    UTF-8 SEM BOM, o travessao (U+2014, bytes E2 80 94) e' lido como tres
    caracteres ANSI, e o ultimo deles, 0x94, e' a ASPA CURVA DE FECHAMENTO —
    que o PowerShell trata como delimitador de string. Um travessao dentro de
    string dupla nao estraga o texto: QUEBRA O PARSER, com erro de chave nao
    fechada apontando para a primeira linha do arquivo, longe da causa. Com
    BOM, nada disso acontece.

    Os arquivos copiados do winutil-cli para lib/, config/ e audit/ NAO tem
    BOM, e isso e' deliberado: sao ASCII puro (medido — zero bytes acima de
    0x7F), entao nao ha o que a leitura como ANSI possa estragar, e mante-los
    byte a byte iguais a origem e' o que da sentido ao THIRD-PARTY.md.
#>

# ============================================================
# SAIDA — mensagem padronizada
# ============================================================
#
# Copiada do ponto de entrada do winutil-cli, onde ela morava. Nao esta em
# lib/ porque nao e' do upstream: e' do winutil-cli, e o THIRD-PARTY.md separa
# as duas origens.

function Write-Status {
    param(
        [ValidateSet('OK', 'ERROR', 'WARNING', 'INFO')]
        [string]$Level,
        [string]$Message
    )
    $map = @{
        OK      = @{ Tag = '[ OK ]';      Color = 'Green'  }
        ERROR   = @{ Tag = '[ ERROR ]';   Color = 'Red'    }
        WARNING = @{ Tag = '[ WARNING ]'; Color = 'Yellow' }
        INFO    = @{ Tag = '[ INFO ]';    Color = 'Gray'   }
    }
    Write-Host "$($map[$Level].Tag) $Message" -ForegroundColor $map[$Level].Color
}

function Test-PhportoElevado {
    return ([Security.Principal.WindowsPrincipal] `
        [Security.Principal.WindowsIdentity]::GetCurrent()
    ).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

# ============================================================
# RAIZ — o contrato implicito das acoes
# ============================================================
#
# $root e' src/Win/, e a arvore interna repete a do winutil-cli (audit/,
# tools/) de proposito: assim Invoke-Audit e Invoke-Memory migram sem uma
# linha de edicao. O valor do diff desta migracao e' "mudou de endereco".

$global:root = $PSScriptRoot

# ============================================================
# CONFIGS — os JSON que as acoes leem
# ============================================================
#
# dns, preset e tweaks vieram do upstream. debloat e' do projeto: a lista de
# pacotes do Invoke-Debloat, que a tela /win le do mesmo arquivo.
#
# AUSENTE E' ERRO, e aqui esta a diferenca deliberada em relacao ao
# winutil-cli, que avisava e seguia. Sem tweaks.json a acao tweaks nao falha:
# ela roda ate o fim sem fazer nada, e o registro no banco fica com cara de
# sucesso. Um checkout quebrado tem de se explicar na hora.

$global:sync          = [hashtable]::Synchronized(@{})
$global:sync.configs  = @{}

foreach ($nome in 'debloat', 'dns', 'preset', 'tweaks') {
    $arquivo = Join-Path $PSScriptRoot ('config\' + $nome + '.json')

    if (-not (Test-Path $arquivo)) {
        throw "PHPorto: config ausente em src/Win/config: $nome.json"
    }

    try {
        $global:sync.configs.$nome = Get-Content -Path $arquivo -Raw -Encoding UTF8 | ConvertFrom-Json
    } catch {
        throw "PHPorto: $nome.json nao e' JSON valido: $($_.Exception.Message)"
    }
}

# ============================================================
# CARGA — lib/ antes de actions/
# ============================================================
#
# A ordem importa: as acoes chamam as primitivas no corpo delas. Dot-source e'
# leitura de arquivo, nao execucao de acao — nada roda ate o despachante.
#
# O filtro de actions/ e' 'Invoke-*.ps1' pelo mesmo motivo que era no
# winutil-cli: garante que so entra o que tem forma de acao.

foreach ($par in @(
    @{ Pasta = 'lib';     Filtro = '*.ps1' },
    @{ Pasta = 'actions'; Filtro = 'Invoke-*.ps1' }
)) {
    $pasta = Join-Path $PSScriptRoot $par.Pasta

    if (-not (Test-Path $pasta)) {
        throw "PHPorto: pasta ausente em src/Win: $($par.Pasta)"
    }

    Get-ChildItem -Path $pasta -Filter $par.Filtro -File | ForEach-Object {
        . $_.FullName
    }
}

# ============================================================
# DESPACHANTE
# ============================================================
#
# O mapa e' o contrato: nome da acao (o mesmo das duas allowlists) para nome
# da funcao. Ele lista as treze, e a assimetria e' segura nas duas direcoes —
# acao mapeada cuja funcao nao existe e' recusada aqui, com mensagem; acao que
# existe em actions/ e nao esta no mapa e' inalcancavel. O que nao pode
# acontecer e' uma acao chegar ao PowerShell sem passar pelas allowlists, e
# disso cuidam a WinAction do lado PHP e o $ALLOWLIST do worker.

$global:PhportoWinActions = [ordered]@{
    'audit'       = 'Invoke-Audit'
    'tweaks'      = 'Invoke-Tweaks'
    'debloat'     = 'Invoke-Debloat'
    'dns'         = 'Invoke-DNS'
    'performance' = 'Invoke-Performance'
    'install'     = 'Invoke-Install'
    'memory'      = 'Invoke-Memory'
    'network'     = 'Invoke-Network'
    'exporter'    = 'Invoke-Exporter'
    'processes'   = 'Invoke-Processes'
    'optimize'    = 'Invoke-Optimize'
    'gpu'         = 'Invoke-GPU'
    'gdid'        = 'Invoke-Gdid'
}

<#
    Roda uma acao pelo nome, com os parametros ja validados.

    -Params entra por splatting, com os nomes EXATOS dos parametros das acoes
    (Preset, Provider, PrimaryDNS...). Nao ha traducao no caminho, e nao ter
    mapa de nomes e' o que impede uma ponta ficar para tras — mesma decisao
    que a WinAction do lado PHP registra.
#>
function Invoke-PhportoWinAction {
    param(
        [Parameter(Mandatory)] [string]$Action,
        [hashtable]$Params = @{}
    )

    if (-not (Test-PhportoElevado)) {
        Write-Status ERROR 'Este processo nao esta elevado. As acoes do Windows exigem Administrador.'
        throw 'PHPorto: sem elevacao'
    }

    if (-not $global:PhportoWinActions.Contains($Action)) {
        Write-Status ERROR "Acao desconhecida: '$Action'."
        Write-Status INFO  "Acoes: $(($global:PhportoWinActions.Keys) -join ', ')"
        throw "PHPorto: acao desconhecida '$Action'"
    }

    $funcao = $global:PhportoWinActions[$Action]

    if (-not (Get-Command -Name $funcao -CommandType Function -ErrorAction SilentlyContinue)) {
        Write-Status ERROR "A acao '$Action' esta no mapa, mas $funcao nao foi carregada de src/Win/actions."
        throw "PHPorto: funcao ausente '$funcao'"
    }

    & $funcao @Params
}
