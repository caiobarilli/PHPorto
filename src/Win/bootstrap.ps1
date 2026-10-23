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

if ($PSScriptRoot) { $global:root = $PSScriptRoot }

function Read-PhportoFonte([string]$Relativo) {
    if ($null -ne $global:PhportoManifesto) {
        return Read-PhportoConferido $global:root $Relativo $global:PhportoManifesto
    }
    return [System.IO.File]::ReadAllText((Join-Path $global:root $Relativo))
}

$global:runtime = Join-Path (Split-Path (Split-Path $global:root -Parent) -Parent) 'runtime'

$global:sync          = [hashtable]::Synchronized(@{})
$global:sync.configs  = @{}

foreach ($nome in 'debloat', 'dns', 'preset', 'tweaks') {
    $arquivo = Join-Path $global:root ('config\' + $nome + '.json')

    if (-not (Test-Path $arquivo)) {
        throw "PHPorto: config ausente em src/Win/config: $nome.json"
    }

    $texto = Read-PhportoFonte ('config/' + $nome + '.json')

    try {
        $global:sync.configs.$nome = $texto | ConvertFrom-Json
    } catch {
        throw "PHPorto: $nome.json nao e' JSON valido: $($_.Exception.Message)"
    }
}

foreach ($par in @(
    @{ Pasta = 'lib';     Filtro = '*.ps1' },
    @{ Pasta = 'actions'; Filtro = 'Invoke-*.ps1' }
)) {
    $pasta = Join-Path $global:root $par.Pasta

    if (-not (Test-Path $pasta)) {
        throw "PHPorto: pasta ausente em src/Win: $($par.Pasta)"
    }

    Get-ChildItem -Path $pasta -Filter $par.Filtro -File | ForEach-Object {
        . ([scriptblock]::Create((Read-PhportoFonte ($par.Pasta + '/' + $_.Name))))
    }
}

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
    'rdp'         = 'Invoke-Rdp'
    'sunshine'    = 'Invoke-Sunshine'
    'openssh'     = 'Invoke-OpenSsh'
    'ssh-keys'    = 'Invoke-SshKeys'
    'hyperv'      = 'Invoke-Hyperv'
    'hyperv-vm'   = 'Invoke-HypervVm'
    'wsl'         = 'Invoke-Wsl'
}

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
