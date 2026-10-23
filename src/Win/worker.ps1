[CmdletBinding(DefaultParameterSetName = 'Laco')]
param(
    [Parameter(Mandatory, ParameterSetName = 'Laco')] [int]$ParentPid,

    [Parameter(Mandatory, ParameterSetName = 'Laco')] [string]$Dir,

    [Parameter(Mandatory, ParameterSetName = 'Laco')] [string]$Nonce,

    [Parameter(Mandatory, ParameterSetName = 'Laco')] [string]$ManifestoSha256,

    [Parameter(Mandatory, ParameterSetName = 'UmaVez')] [string]$Pedido,
    [Parameter(Mandatory, ParameterSetName = 'UmaVez')] [string]$PedidoSha256
)

$ErrorActionPreference = 'Stop'
$OutputEncoding        = [System.Text.UTF8Encoding]::new($false)

if ($PSCmdlet.ParameterSetName -eq 'UmaVez') { $Dir = Split-Path -Parent $Pedido }

$TICK_MS = 500

$IDLE_TIMEOUT_S = 600

$F_HEARTBEAT = Join-Path $Dir 'win-heartbeat'
$F_JOB       = Join-Path $Dir 'win-job.json'
$F_DESLIGAR  = Join-Path $Dir 'win-ordem-desligar'
$F_CANCELAR  = Join-Path $Dir 'win-ordem-cancelar'
$F_PROVA     = Join-Path $Dir ('win-prova-' + $Nonce + '.txt')
$F_LOG       = Join-Path $Dir 'win-worker.log'
$F_MANIFESTO = Join-Path $Dir 'win-manifesto.json'
$F_IMPORTADAS = Join-Path $Dir 'win-importadas.txt'

$PROTEGIDA   = Join-Path $Dir 'win-protected'

$SID_ADMINS  = 'S-1-5-32-544'
$SID_SYSTEM  = 'S-1-5-18'
$SID_DONO    = 'S-1-3-4'

$RAIZ_WIN    = $PSScriptRoot

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
        'Apps' = @{ tipo = 'text'; padrao = '\A[A-Za-z0-9][A-Za-z0-9_+-]*(?:\.[A-Za-z0-9_+-]+)+(?:,[A-Za-z0-9][A-Za-z0-9_+-]*(?:\.[A-Za-z0-9_+-]+)+)*\z' }
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

    'gdid'        = @{
        'SubAction' = @{ tipo = 'set'; valores = @('status', 'disable', 'enable') }
    }

    'rdp'         = @{
        'SubAction' = @{ tipo = 'set'; valores = @('status', 'on', 'off', 'h264-on', 'h264-off') }
    }

    'sunshine'    = @{
        'SubAction'  = @{ tipo = 'set'; valores = @('status', 'install', 'start', 'stop', 'firewall-open', 'firewall-close', 'set-creds', 'pair') }
        'User'       = @{ tipo = 'text'; max = 64; padrao = '\A[^:\x00-\x1F\x7F]+\z' }
        'Password'   = @{ tipo = 'text'; max = 256; min = 8; padrao = '\A[^\x00-\x1F\x7F]+\z' }
        'Pin'        = @{ tipo = 'text'; max = 4; padrao = '\A[0-9]{4}\z' }
        'DeviceName' = @{ tipo = 'text'; max = 128; padrao = '\A[^\x00-\x1F\x7F]+\z' }
        'SetCreds'   = @{ tipo = 'flag' }
    }

    'openssh'     = @{
        'SubAction' = @{ tipo = 'set'; valores = @('status', 'install', 'enable', 'disable') }
    }
    'ssh-keys'    = @{
        'SubAction'    = @{ tipo = 'set'; valores = @('status', 'generate', 'puttygen', 'list', 'authorize', 'remove', 'wsl-user') }
        'PublicKey'    = @{ tipo = 'text'; max = 2200; padrao = '\A(?:ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp256|ecdsa-sha2-nistp384|ecdsa-sha2-nistp521) AAAA[A-Za-z0-9+/]{16,2000}={0,2}(?: [A-Za-z0-9@._+-]{1,100})?\z' }
        'UseGenerated' = @{ tipo = 'flag' }
        'Fingerprint'  = @{ tipo = 'text'; max = 50; padrao = '\ASHA256:[A-Za-z0-9+/]{43}\z' }
        'User'         = @{ tipo = 'text'; max = 32; padrao = '\A[a-z][a-z0-9-]{0,31}\z' }
        'Distro'       = @{ tipo = 'text'; max = 64; padrao = '\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z' }
    }
    'hyperv'      = @{}

    'hyperv-vm'   = @{
        'SubAction' = @{ tipo = 'set'; valores = @('start', 'stop', 'restart') }
        'VmId'      = @{ tipo = 'text'; max = 36; min = 36; padrao = '\A[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\z' }
    }
}

$SENSIVEIS = @{
    'install'   = '*'
    'rdp'       = @('on')
    'sunshine'  = @('install', 'firewall-open', 'set-creds', 'pair')
    'openssh'   = @('install', 'enable')
    'ssh-keys'  = @('puttygen', 'authorize', 'remove', 'wsl-user')
    'exporter'  = @('install', 'firewall')
    'gpu'       = @('install')
    'hyperv-vm' = '*'
}

$SEGREDOS = @{
    'sunshine' = @('Password', 'Pin')
}

function Write-Log([string]$msg) {
    try {
        Add-Content -Path $F_LOG -Value ("{0:yyyy-MM-dd HH:mm:ss.fff} {1}" -f (Get-Date), $msg) -Encoding UTF8
    } catch {
    }
}

function Test-WorkerOcioso([datetime]$desde, [datetime]$agora, [int]$limiteS, [bool]$temFilho) {
    if ($temFilho) { return $false }
    return (($agora - $desde).TotalSeconds -ge $limiteS)
}

function Write-Heartbeat {
    try {
        Set-Content -Path $F_HEARTBEAT -Value ([DateTimeOffset]::UtcNow.ToUnixTimeMilliseconds()) -Encoding ASCII
    } catch {
    }
}

function ConvertTo-PsLiteral([string]$value) {
    return "'" + [System.Management.Automation.Language.CodeGeneration]::EscapeSingleQuotedStringContent($value) + "'"
}

function Get-PhportoListaPermitida([string]$fonte) {
    if ($fonte -notin 'tweaks', 'debloat', 'dns') { return @() }

    $dados = Read-PhportoConferido $RAIZ_WIN ('config/' + $fonte + '.json') $MANIFESTO | ConvertFrom-Json

    switch ($fonte) {
        'tweaks'  { return @($dados.PSObject.Properties | Where-Object { $_.Value.Type -notin 'Button', 'Combobox' } | ForEach-Object { $_.Name }) }
        'debloat' { return @($dados | ForEach-Object { [string]$_ }) }
        'dns'     { return @($dados.PSObject.Properties | ForEach-Object { $_.Name }) }
        default   { return @() }
    }
}

function Read-PhportoConferido {
    param([string]$Raiz, [string]$Relativo, [hashtable]$Manifesto)

    $chave = $Relativo.Replace('\', '/')

    if (-not $Manifesto.ContainsKey($chave)) {
        throw "PHPorto: $chave nao estava em src/Win quando o PowerShell elevado foi ligado. Nada foi executado. Desligue e ligue de novo em /config."
    }

    $caminho = Join-Path $Raiz $chave
    if (-not (Test-Path -LiteralPath $caminho -PathType Leaf)) {
        throw "PHPorto: $chave sumiu de src/Win depois que o PowerShell elevado foi ligado. Nada foi executado."
    }

    $bytes = [System.IO.File]::ReadAllBytes($caminho)
    $lido  = (Get-FileHash -InputStream ([System.IO.MemoryStream]::new($bytes)) -Algorithm SHA256).Hash

    if ($lido -ne $Manifesto[$chave]) {
        throw "PHPorto: $chave mudou depois que o PowerShell elevado foi ligado (SHA-256 $lido, esperado $($Manifesto[$chave])). Nada foi executado. Se a mudanca e' sua, desligue e ligue de novo em /config."
    }

    $inicio = if ($bytes.Length -ge 3 -and $bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF) { 3 } else { 0 }
    return [System.Text.Encoding]::UTF8.GetString($bytes, $inicio, $bytes.Length - $inicio)
}

function Read-PhportoManifesto([string]$caminho, [string]$sha256) {
    $bytes = [System.IO.File]::ReadAllBytes($caminho)
    $lido  = (Get-FileHash -InputStream ([System.IO.MemoryStream]::new($bytes)) -Algorithm SHA256).Hash

    if ($lido -ne $sha256) {
        throw "o manifesto em files/ nao e' o que o PHP gravou ao ligar (SHA-256 $lido, esperado $sha256)"
    }

    $manifesto = @{}
    foreach ($par in ([System.Text.Encoding]::UTF8.GetString($bytes) | ConvertFrom-Json).PSObject.Properties) {
        if ([string]$par.Value -notmatch '\A[0-9a-fA-F]{64}\z') {
            throw "manifesto com SHA-256 invalido para '$($par.Name)'"
        }
        $manifesto[$par.Name] = [string]$par.Value
    }

    if (-not $manifesto.ContainsKey('bootstrap.ps1')) { throw 'manifesto sem o bootstrap.ps1' }

    return $manifesto
}

function Get-UsuarioPhp([int]$processo) {
    $p   = Get-CimInstance -ClassName Win32_Process -Filter "ProcessId = $processo"
    $sid = if ($null -ne $p) { (Invoke-CimMethod -InputObject $p -MethodName GetOwnerSid).Sid } else { $null }

    if (-not $sid) { throw "nao foi possivel saber o usuario do php -S (pid $processo)" }

    return [string]$sid
}

function Get-RegrasProtegida([string]$usuarioSid) {
    $tudo = 'ContainerInherit, ObjectInherit'
    return @(
        [PSCustomObject]@{ Sid = $SID_ADMINS; Direitos = 'FullControl';    Heranca = $tudo;           SoArquivos = $false }
        [PSCustomObject]@{ Sid = $SID_SYSTEM; Direitos = 'FullControl';    Heranca = $tudo;           SoArquivos = $false }
        [PSCustomObject]@{ Sid = $SID_DONO;   Direitos = 'ReadAndExecute'; Heranca = $tudo;           SoArquivos = $false }
        [PSCustomObject]@{ Sid = $usuarioSid; Direitos = 'ReadAndExecute'; Heranca = $tudo;           SoArquivos = $false }
        [PSCustomObject]@{ Sid = $usuarioSid; Direitos = 'Delete';         Heranca = 'ObjectInherit'; SoArquivos = $true }
    )
}

function New-AclProtegida([string]$usuarioSid, [switch]$ComDono) {
    $acl = New-Object System.Security.AccessControl.DirectorySecurity
    $acl.SetAccessRuleProtection($true, $false)

    if ($ComDono) {
        $acl.SetOwner((New-Object System.Security.Principal.SecurityIdentifier($SID_ADMINS)))
    }

    foreach ($r in Get-RegrasProtegida $usuarioSid) {
        $propagacao = if ($r.SoArquivos) { 'InheritOnly' } else { 'None' }
        $acl.AddAccessRule((New-Object System.Security.AccessControl.FileSystemAccessRule(
            (New-Object System.Security.Principal.SecurityIdentifier($r.Sid)), $r.Direitos, $r.Heranca, $propagacao, 'Allow'
        )))
    }

    return $acl
}

function New-PastaProtegida([string]$caminho, $acl) {
    [System.IO.Directory]::CreateDirectory($caminho, $acl) | Out-Null
}

function Set-PastaProtegidaAcl([string]$caminho, $acl) {
    [System.IO.Directory]::SetAccessControl($caminho, $acl)
}

function Get-PastaInfo([string]$caminho) {
    $item = Get-Item -LiteralPath $caminho -Force -ErrorAction SilentlyContinue
    if ($null -eq $item) { return [PSCustomObject]@{ Existe = $false; Pasta = $false; Link = $false; Dono = $null } }

    $link = [bool]($item.Attributes -band [System.IO.FileAttributes]::ReparsePoint)
    $dono = if ($link) { $null } else { (Get-Acl -LiteralPath $caminho).GetOwner([System.Security.Principal.SecurityIdentifier]).Value }

    return [PSCustomObject]@{ Existe = $true; Pasta = [bool]$item.PSIsContainer; Link = $link; Dono = $dono }
}

function Test-PastaProtegida($info) {
    if (-not $info.Existe) { return 'nao existe' }
    if ($info.Link)        { return "e' um link ou juncao" }
    if (-not $info.Pasta)  { return "nao e' uma pasta" }
    if ($info.Dono -notin $SID_ADMINS, $SID_SYSTEM) { return "tem como dono $($info.Dono), e nao Administradores nem SYSTEM" }
    return $null
}

function Initialize-PastaProtegida([string]$caminho, [string]$usuarioSid) {
    $info = Get-PastaInfo $caminho

    if (-not $info.Existe) {
        New-PastaProtegida $caminho (New-AclProtegida $usuarioSid -ComDono)
    } else {
        $motivo = Test-PastaProtegida $info
        if ($motivo) { throw "files/win-protected $motivo. Apague a pasta e ligue de novo." }
        Set-PastaProtegidaAcl $caminho (New-AclProtegida $usuarioSid)
    }

    $trava = [System.IO.File]::Open((Join-Path $caminho 'win-trava'), 'OpenOrCreate', 'Read', 'ReadWrite')

    $motivo = Test-PastaProtegida (Get-PastaInfo $caminho)
    if ($motivo) {
        $trava.Dispose()
        throw "files/win-protected $motivo. Apague a pasta e ligue de novo."
    }

    return $trava
}

function Test-AcaoSensivel([string]$acao, $params) {
    if (-not $SENSIVEIS.ContainsKey($acao)) { return $false }

    $regra = $SENSIVEIS[$acao]
    if ($regra -is [string]) { return $true }

    $sub = if ($null -ne $params -and $params.Contains('SubAction')) { [string]$params['SubAction'] } else { '' }
    return ($sub -in $regra)
}

function Test-Job($job, [ValidateSet('Laco', 'UmaVez')] [string]$Modo = 'Laco') {
    if ($null -eq $job) { throw 'job vazio' }
    if ($Modo -eq 'Laco' -and $job.nonce -ne $Nonce) { throw "nonce de outra execucao do servidor (obsoleto)" }

    $acao = [string]$job.acao
    if (-not $ALLOWLIST.ContainsKey($acao)) { throw "acao fora da allowlist: '$acao'" }

    $permitidos = $ALLOWLIST[$acao]
    $aceitos    = [ordered]@{}

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
                    $aceitos[$nome] = [string]$casou
                }
                'text' {
                    $texto = [string]$valor
                    $bytes = [System.Text.Encoding]::UTF8.GetByteCount($texto)
                    $teto  = if ($regra.max) { [int]$regra.max } else { $MAX_PARAM_BYTES }
                    if ($bytes -gt $teto) {
                        throw "'$nome' passou do teto de bytes"
                    }
                    if ($regra.min -and $bytes -lt [int]$regra.min) {
                        throw "'$nome' abaixo do minimo de bytes"
                    }
                    if ($texto.Contains([char]0)) { throw "'$nome' tem byte nulo" }
                    if ($regra.padrao -and $texto -cnotmatch $regra.padrao) {
                        throw "'$nome' fora do formato"
                    }
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

                    $daLista = @(Get-PhportoListaPermitida $regra.fonte)
                    if ($daLista.Count -eq 0) { throw "lista '$($regra.fonte)' indisponivel" }

                    $itens = New-Object System.Collections.Generic.List[string]
                    foreach ($bruto in ($texto -split ',')) {
                        $item = $bruto.Trim()
                        if ($item -eq '') { continue }
                        $casou = $daLista | Where-Object { $_ -ieq $item } | Select-Object -First 1
                        if (-not $casou) { throw "item fora da lista em '$nome': '$item'" }
                        if ($itens.Contains([string]$casou)) { throw "item repetido em '$nome': '$casou'" }
                        $itens.Add([string]$casou)
                    }
                    if ($itens.Count -eq 0) { throw "'$nome' sem nenhum item" }
                    $aceitos[$nome] = [string[]]$itens.ToArray()
                }
                default { throw "regra desconhecida para '$nome'" }
            }
        }
    }

    $sensivel = Test-AcaoSensivel $acao $aceitos
    $rotulo   = if ($aceitos.Contains('SubAction')) { $acao + ' ' + $aceitos['SubAction'] } else { $acao }

    if ($Modo -eq 'Laco' -and $sensivel) {
        throw "acao sensivel: '$rotulo' so roda com UAC proprio, pela tela"
    }
    if ($Modo -eq 'UmaVez' -and -not $sensivel) {
        throw "o modo de uso unico so roda acao sensivel, e '$rotulo' nao e'"
    }

    return @{ acao = $acao; params = $aceitos }
}

function New-InvocationScript($validado, [string]$id) {
    $arqExit = Join-Path $PROTEGIDA ('win-exit-' + $id + '.txt')

    $dados = [ordered]@{}

    foreach ($nome in $validado.params.Keys) {
        $valor = $validado.params[$nome]

        if ($valor -is [bool]) {
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

    $mapa = [System.Convert]::ToBase64String([System.Text.Encoding]::UTF8.GetBytes((ConvertTo-Json -InputObject $MANIFESTO -Compress)))

    $linhas = New-Object System.Collections.Generic.List[string]
    $linhas.Add('$ErrorActionPreference = ' + (ConvertTo-PsLiteral 'Continue'))
    $linhas.Add('[Console]::OutputEncoding = [System.Text.UTF8Encoding]::new($true)')
    $linhas.Add('$phportoRecusado = $false')
    $linhas.Add('try {')
    $linhas.Add('    function global:Read-PhportoConferido {')
    foreach ($l in (${function:Read-PhportoConferido}.ToString() -split "\r?\n")) { $linhas.Add($l) }
    $linhas.Add('    }')
    $linhas.Add('    $global:PhportoManifesto = @{}')
    $linhas.Add('    foreach ($phportoPar in ([System.Text.Encoding]::UTF8.GetString([System.Convert]::FromBase64String(' + (ConvertTo-PsLiteral $mapa) + ')) | ConvertFrom-Json).PSObject.Properties) { $global:PhportoManifesto[$phportoPar.Name] = [string]$phportoPar.Value }')
    $linhas.Add('    $global:root = ' + (ConvertTo-PsLiteral $RAIZ_WIN))
    $linhas.Add('    $phportoJson   = [System.Text.Encoding]::UTF8.GetString([System.Convert]::FromBase64String(' + (ConvertTo-PsLiteral $carga) + '))')
    $linhas.Add('    $phportoParams = @{}')
    $linhas.Add('    foreach ($phportoPar in ($phportoJson | ConvertFrom-Json).PSObject.Properties) {')
    $linhas.Add('        if ($phportoPar.Value -is [System.Array]) { $phportoParams[$phportoPar.Name] = [string[]]$phportoPar.Value } else { $phportoParams[$phportoPar.Name] = $phportoPar.Value }')
    $linhas.Add('    }')
    $linhas.Add('    . ([scriptblock]::Create((Read-PhportoConferido $global:root ''bootstrap.ps1'' $global:PhportoManifesto)))')
    $linhas.Add('    Invoke-PhportoWinAction -Action ' + (ConvertTo-PsLiteral $validado.acao) + ' -Params $phportoParams *>&1')
    $linhas.Add('} catch {')
    $linhas.Add('    Write-Host ("[phporto] " + $_.Exception.Message)')
    $linhas.Add('    $phportoRecusado = $true')
    $linhas.Add('}')

    $linhas.Add('$code = if ($phportoRecusado) { 1 } elseif ($null -eq $LASTEXITCODE) { 0 } else { $LASTEXITCODE }')
    $linhas.Add('[System.IO.File]::WriteAllText(' + (ConvertTo-PsLiteral $arqExit) + ', [string]$code, [System.Text.UTF8Encoding]::new($false))')
    $linhas.Add('exit $code')

    $caminho = Join-Path $PROTEGIDA ('win-exec-' + $id + '.ps1')

    $texto = ($linhas -join "`r`n") + "`r`n"
    [System.IO.File]::WriteAllText($caminho, $texto, [System.Text.UTF8Encoding]::new($true))

    return $caminho
}

function Write-Done {
    param(
        [string]$id,
        $exit,
        [int]$ms,
        [string]$nota,
        [string]$acao = '',
        $params = $null
    )

    $done = Join-Path $PROTEGIDA ('win-done-' + $id + '.json')
    $dados = [ordered]@{
        id     = $id
        exit   = $exit
        ms     = $ms
        nota   = $nota
        acao   = $acao
        params = Hide-PhportoSegredos $acao $params
        fim    = [DateTime]::UtcNow.ToString(
            'yyyy-MM-dd HH:mm:ss',
            [System.Globalization.CultureInfo]::InvariantCulture
        )
    }
    [System.IO.File]::WriteAllText(
        $done,
        ($dados | ConvertTo-Json -Compress -Depth 5),
        [System.Text.UTF8Encoding]::new($false)
    )
}

function Hide-PhportoSegredos([string]$acao, $params) {
    $copia = [ordered]@{}
    if ($null -eq $params) { return $copia }

    $esconder = if ($SEGREDOS.ContainsKey($acao)) { @($SEGREDOS[$acao]) } else { @() }

    foreach ($nome in @($params.Keys)) {
        $copia[$nome] = if ($nome -in $esconder) { '***' } else { $params[$nome] }
    }
    return $copia
}

$filho       = $null
$filhoId     = $null
$filhoT0     = $null
$filhoOut    = $null
$filhoScript = $null

$filhoAcao   = ''
$filhoParams = $null

function Stop-Filho([string]$motivo) {
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
    if ($null -ne $script:filhoScript -and (Test-Path $script:filhoScript)) {
        Remove-Item $script:filhoScript -Force -ErrorAction SilentlyContinue
    }

    if ($null -ne $script:filhoId) {
        foreach ($sufixo in @('win-exit-', 'win-err-')) {
            $alvo = Join-Path $PROTEGIDA ($sufixo + $script:filhoId + '.txt')
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

    Clear-Filho
}

function Start-FilhoJob($validado, [string]$id) {
    $script:filhoScript = New-InvocationScript $validado $id
    $script:filhoOut    = Join-Path $PROTEGIDA ('win-out-' + $id + '.txt')
    $erro               = Join-Path $PROTEGIDA ('win-err-' + $id + '.txt')

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
}

function Complete-Filho {
    $ms = [int]((Get-Date) - $script:filhoT0).TotalMilliseconds

    $arqErr = Join-Path $PROTEGIDA ('win-err-' + $script:filhoId + '.txt')
    if (Test-Path $arqErr) {
        try {
            $residuo = [System.IO.File]::ReadAllText($arqErr)
            if ($residuo.Trim().Length -gt 0) {
                [System.IO.File]::AppendAllText($script:filhoOut, $residuo, [System.Text.UTF8Encoding]::new($false))
            }
        } catch {
            Write-Log "falha ao juntar o stderr: $($_.Exception.Message)"
        }
        Remove-Item $arqErr -Force -ErrorAction SilentlyContinue
    }

    $arqExit = Join-Path $PROTEGIDA ('win-exit-' + $script:filhoId + '.txt')
    $code    = $null
    if (Test-Path $arqExit) {
        $bruto = ([System.IO.File]::ReadAllText($arqExit)).Trim()
        $n     = 0
        if ([int]::TryParse($bruto, [ref]$n)) { $code = $n }
        Remove-Item $arqExit -Force -ErrorAction SilentlyContinue
    }

    Write-Done $script:filhoId $code $ms '' $script:filhoAcao $script:filhoParams
    Write-Log "filho concluido id=$($script:filhoId) exit=$code ms=$ms"
    Clear-Filho

    return $code
}

function Write-Recusa([string]$id, [string]$motivo) {
    Write-Log "job RECUSADO id=$id : $motivo"
    [System.IO.File]::WriteAllText(
        (Join-Path $PROTEGIDA ('win-out-' + $id + '.txt')),
        "[phporto] job recusado pela allowlist do worker: $motivo`r`n",
        [System.Text.UTF8Encoding]::new($false)
    )
    Write-Done $id 126 0 'recusado'
    Clear-Filho
}

function Clear-PhportoImportadas([string]$lista, [string]$protegida, [int]$tetoBytes = 65536) {
    if ([string]::IsNullOrEmpty($lista) -or [string]::IsNullOrEmpty($protegida)) { return 0 }

    $info = Get-Item -LiteralPath $lista -Force -ErrorAction SilentlyContinue
    if ($null -eq $info -or $info.PSIsContainer) { return 0 }

    if ($info.Length -gt $tetoBytes) {
        Write-Log "lista de execucoes ja importadas com $($info.Length) bytes, acima do teto: ignorada"
        return 0
    }

    try {
        $texto = [System.IO.File]::ReadAllText($lista)
    } catch {
        Write-Log "falha ao ler a lista de execucoes ja importadas: $($_.Exception.Message)"
        return 0
    }

    $ids = New-Object System.Collections.Generic.List[string]
    foreach ($linha in ($texto -split '\r?\n')) {
        if ($linha -cmatch '\A[0-9a-f]{12}\z' -and -not $ids.Contains($linha)) { $ids.Add($linha) }
    }

    $apagados = 0
    foreach ($id in $ids) {
        foreach ($nome in @(('win-done-' + $id + '.json'), ('win-out-' + $id + '.txt'))) {
            $alvo = Join-Path $protegida $nome
            if (-not (Test-Path -LiteralPath $alvo -PathType Leaf)) { continue }
            try {
                Remove-Item -LiteralPath $alvo -Force -ErrorAction Stop
                $apagados++
            } catch {
                Write-Log "falha ao apagar $nome ja importado: $($_.Exception.Message)"
            }
        }
    }

    if ($apagados -gt 0) { Write-Log "apagados $apagados arquivos de execucoes ja importadas" }
    return $apagados
}

function Read-PhportoPedido([string]$caminho, [string]$sha256) {
    $nome = [System.IO.Path]::GetFileName($caminho)
    if ($nome -cnotmatch '\Awin-oneshot-([0-9a-f]{12})\.json\z') { throw "pedido com nome fora do formato: '$nome'" }
    $idDoNome = $Matches[1]

    if (-not (Test-Path -LiteralPath $caminho -PathType Leaf)) {
        throw 'pedido ausente: ja foi consumido, ou o PHP desistiu de esperar e o apagou'
    }

    $bytes = [System.IO.File]::ReadAllBytes($caminho)
    $lido  = (Get-FileHash -InputStream ([System.IO.MemoryStream]::new($bytes)) -Algorithm SHA256).Hash

    if ($lido -ne $sha256) {
        throw "o pedido em files/ nao e' o que o PHP gravou no clique (SHA-256 $lido, esperado $sha256)"
    }

    Remove-Item -LiteralPath $caminho -Force

    try {
        $p = [System.Text.Encoding]::UTF8.GetString($bytes) | ConvertFrom-Json
    } catch {
        throw 'pedido que nao e JSON valido'
    }

    if ($null -eq $p -or $p.v -ne 1) { throw 'pedido de versao desconhecida' }
    if ([string]$p.id -cne $idDoNome) { throw "pedido com id que nao e' o do nome do arquivo" }

    $agora = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds()
    $expira = 0L
    if (-not [long]::TryParse([string]$p.expira_em, [ref]$expira) -or $expira -lt $agora) {
        throw 'pedido expirado: o prompt foi aceito depois de o PHP desistir de esperar'
    }

    $prazo = 0
    if (-not [int]::TryParse([string]$p.prazo_s, [ref]$prazo) -or $prazo -lt 60 -or $prazo -gt 3615) {
        throw 'pedido com prazo fora da faixa (60-3615 s)'
    }

    $pai = 0
    if (-not [int]::TryParse([string]$p.php_pid, [ref]$pai) -or $pai -le 0) { throw 'pedido sem o pid do php -S' }
    if (-not (Get-Process -Id $pai -ErrorAction SilentlyContinue)) { throw "o php -S que pediu (pid $pai) nao existe mais" }

    foreach ($campo in 'acao', 'raiz_win', 'dir') {
        if ($p.$campo -isnot [string] -or $p.$campo -eq '') { throw "pedido sem '$campo'" }
    }

    if ($p.manifesto -isnot [System.Management.Automation.PSCustomObject]) { throw 'pedido sem manifesto' }
    $manifesto = @{}
    foreach ($par in $p.manifesto.PSObject.Properties) {
        if ([string]$par.Value -notmatch '\A[0-9a-fA-F]{64}\z') {
            throw "manifesto com SHA-256 invalido para '$($par.Name)'"
        }
        $manifesto[$par.Name] = [string]$par.Value
    }
    if (-not $manifesto.ContainsKey('bootstrap.ps1')) { throw 'manifesto sem o bootstrap.ps1' }

    return [PSCustomObject]@{
        id        = $idDoNome
        acao      = [string]$p.acao
        params    = $p.params
        php_pid   = $pai
        raiz_win  = [string]$p.raiz_win
        dir       = [string]$p.dir
        prazo_s   = $prazo
        manifesto = $manifesto
    }
}

function Invoke-UmaVez {
    $estado = $null
    if ([System.IO.Path]::GetFileName($Pedido) -cmatch '\Awin-oneshot-([0-9a-f]{12})\.json\z') {
        $estado = Join-Path $Dir ('win-oneshot-' + $Matches[1] + '.estado')
    }

    try {
        $p = Read-PhportoPedido $Pedido $PedidoSha256

        if ($p.dir.TrimEnd('\', '/') -ne $Dir.TrimEnd('\', '/')) {
            throw "o pedido aponta para outra pasta de trabalho ('$($p.dir)')"
        }

        $script:MANIFESTO = $p.manifesto
        if (-not $script:RAIZ_WIN) { $script:RAIZ_WIN = $p.raiz_win }
        $script:TRAVA = Initialize-PastaProtegida $PROTEGIDA (Get-UsuarioPhp $p.php_pid)
    } catch {
        Write-Log "uso unico recusado: $($_.Exception.Message)"
        if ($estado) { Set-Content -LiteralPath $estado -Value ('ERRO=' + $_.Exception.Message) -Encoding ASCII }
        return 1
    }

    Set-Content -LiteralPath $estado -Value "ACEITO=$PID" -Encoding ASCII
    Write-Log "uso unico pid=$PID pai=$($p.php_pid) id=$($p.id) acao=$($p.acao)"
    Clear-PhportoImportadas $F_IMPORTADAS $PROTEGIDA | Out-Null

    $code = $null
    try {
        try {
            Start-FilhoJob (Test-Job ([PSCustomObject]@{ acao = $p.acao; params = $p.params }) -Modo UmaVez) $p.id
        } catch {
            Write-Recusa $p.id $_.Exception.Message
            return 126
        }

        $cancelar = Join-Path $Dir ('win-ordem-cancelar-' + $p.id)
        $limite   = (Get-Date).AddSeconds($p.prazo_s)

        while ($null -ne $script:filho) {
            if ($script:filho.HasExited) {
                $code = Complete-Filho
                break
            }

            if (-not (Get-Process -Id $p.php_pid -ErrorAction SilentlyContinue)) {
                Stop-FilhoComSinal 'pai desapareceu'
                break
            }

            if (Test-Path $cancelar) {
                Remove-Item $cancelar -Force -ErrorAction SilentlyContinue
                $ms = [int]((Get-Date) - $script:filhoT0).TotalMilliseconds
                Stop-Filho 'cancelado'
                Write-Done $script:filhoId $null $ms 'cancelado' $script:filhoAcao $script:filhoParams
                Clear-Filho
                break
            }

            if ((Get-Date) -gt $limite) {
                Stop-FilhoComSinal 'prazo'
                break
            }

            Start-Sleep -Milliseconds $TICK_MS
        }
    } finally {
        $script:TRAVA.Dispose()
        Write-Log "fim do uso unico pid=$PID id=$($p.id) exit=$code"
    }

    if ($null -eq $code) { return 1 }
    return $code
}

$admin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)

if (-not $admin) {
    Write-Log "recusado: processo nao esta elevado (pid=$PID)"
    exit 1
}

if ($PSCmdlet.ParameterSetName -eq 'UmaVez') {
    exit (Invoke-UmaVez)
}

try {
    $MANIFESTO = Read-PhportoManifesto $F_MANIFESTO $ManifestoSha256
    $TRAVA     = Initialize-PastaProtegida $PROTEGIDA (Get-UsuarioPhp $ParentPid)
} catch {
    Write-Log "recusado ao subir: $($_.Exception.Message)"
    Set-Content -Path $F_PROVA -Value ('ERRO=' + $_.Exception.Message) -Encoding ASCII
    exit 1
}

Set-Content -Path $F_PROVA -Value "PID=$PID;ADMIN=True;NONCE=$Nonce" -Encoding ASCII
Write-Heartbeat
Write-Log "iniciado pid=$PID pai=$ParentPid raiz='$RAIZ_WIN' manifesto=$($MANIFESTO.Count) arquivos"

$ultimaAtividade = Get-Date

while ($true) {
    Write-Heartbeat

    if (-not (Get-Process -Id $ParentPid -ErrorAction SilentlyContinue)) {
        Write-Log "pai $ParentPid desapareceu: encerrando por conta propria"
        Stop-FilhoComSinal 'pai desapareceu'
        break
    }

    if (Test-Path $F_DESLIGAR) {
        Remove-Item $F_DESLIGAR -Force -ErrorAction SilentlyContinue
        Write-Log 'ordem de desligar recebida'
        Stop-FilhoComSinal 'desligando'
        break
    }

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

    if ($null -ne $filho -and $filho.HasExited) {
        Complete-Filho | Out-Null
    }

    if ($null -eq $filho -and (Test-Path $F_JOB)) {
        $bruto = $null
        try {
            $bruto = Get-Content $F_JOB -Raw -Encoding UTF8
        } catch {
            Write-Log "falha ao ler o job: $($_.Exception.Message)"
        }

        Remove-Item $F_JOB -Force -ErrorAction SilentlyContinue

        if ($null -ne $bruto) {
            $ultimaAtividade = Get-Date
            Clear-PhportoImportadas $F_IMPORTADAS $PROTEGIDA | Out-Null
            $id = [guid]::NewGuid().ToString('N').Substring(0, 12)

            try {
                $job      = $bruto | ConvertFrom-Json
                $validado = Test-Job $job
                Start-FilhoJob $validado $id
            } catch {
                Write-Recusa $id $_.Exception.Message
            }
        }
    }

    if ($null -ne $filho) { $ultimaAtividade = Get-Date }
    if (Test-WorkerOcioso $ultimaAtividade (Get-Date) $IDLE_TIMEOUT_S ($null -ne $filho)) {
        Write-Log "ocioso por $IDLE_TIMEOUT_S s: encerrando por conta propria"
        break
    }

    Start-Sleep -Milliseconds $TICK_MS
}

Remove-Item $F_HEARTBEAT -Force -ErrorAction SilentlyContinue
Remove-Item $F_PROVA -Force -ErrorAction SilentlyContinue
$TRAVA.Dispose()
Write-Log "fim pid=$PID"
