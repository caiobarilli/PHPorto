function Invoke-Sunshine {
    param(
        [string]$SubAction,
        [string]$User,
        [string]$Password,
        [string]$Pin,
        [string]$DeviceName,
        [switch]$SetCreds
    )

    $wingetId = 'LizardByte.Sunshine'
    $port     = 47990
    $fwRule   = 'PHPorto - Sunshine 47990'
    $webUrl   = 'https://localhost:47990'
    $opcoes   = 'status, install, start, stop, firewall-open, firewall-close, set-creds, pair'

    function Get-SunshineService {
        return Get-Service -ErrorAction SilentlyContinue |
            Where-Object { $_.Name -like '*unshine*' -or $_.DisplayName -like '*unshine*' } |
            Select-Object -First 1
    }

    function Test-SunshinePort {
        return (Test-PhportoSunshinePorta)
    }

    function Get-SunshinePairedCount {
        $candidates = @(
            (Join-Path $env:ProgramData 'Sunshine\config\sunshine_state.json'),
            (Join-Path $env:ProgramFiles 'Sunshine\config\sunshine_state.json')
        )
        if (${env:ProgramFiles(x86)}) {
            $candidates += (Join-Path ${env:ProgramFiles(x86)} 'Sunshine\config\sunshine_state.json')
        }

        $file = $candidates | Where-Object { Test-Path $_ } | Select-Object -First 1
        if (-not $file) { return $null }

        try {
            $state = Get-Content -Path $file -Raw -Encoding UTF8 | ConvertFrom-Json
        } catch {
            return $null
        }

        if ($state.root -and $null -ne $state.root.devices) {
            return @($state.root.devices).Count
        }
        return $null
    }

    function Show-SunshineStatus {
        $svc = Get-SunshineService
        if ($svc) {
            Write-Status OK "Service: $($svc.Name) / Status: $($svc.Status) / StartType: $($svc.StartType)."
        } else {
            Write-Status WARNING "Service: not found. Sunshine may not be installed; run the 'install' subaction."
        }

        try {
            $listed = winget list --id $wingetId --exact --accept-source-agreements 2>$null | Out-String
            if ($listed -match [regex]::Escape($wingetId)) {
                $ver = ($listed -split "`r?`n" | Where-Object { $_ -match [regex]::Escape($wingetId) } | Select-Object -First 1)
                Write-Status OK "Installed (winget: $($ver.Trim()))."
            } else {
                Write-Status INFO "Not installed according to winget."
            }
        } catch {
            Write-Status WARNING "Could not query winget: $($_.Exception.Message)"
        }

        if (Test-SunshinePort) {
            Write-Status OK "Port $port is listening."
        } else {
            Write-Status INFO "Port $port is not listening (service stopped or not installed)."
        }

        $paired = Get-SunshinePairedCount
        if ($null -eq $paired) {
            Write-Status INFO "Paired clients: cannot be read without touching Sunshine (state file absent or unrecognised)."
        } else {
            Write-Status OK "Paired clients: $paired."
        }
    }

    function Install-Sunshine {
        Write-Status INFO "Ensuring winget is available..."
        try {
            Install-WinUtilWinget
        } catch {
            Write-Status ERROR "Failed to prepare winget: $($_.Exception.Message)"
            return
        }

        Write-Status INFO "Installing $wingetId via winget..."
        try {
            $resultados = @(Install-WinUtilProgramWinget -Action Install -Programs @($wingetId))
        } catch {
            Write-Status ERROR $_.Exception.Message
            return
        }

        foreach ($r in $resultados) {
            switch (Get-PhportoWingetOutcome -ExitCode $r.ExitCode) {
                'ok'        { Write-Status OK "$($r.Program) installed." }
                'installed' { Write-Status OK "$($r.Program) was already installed." }
                'reboot'    { Write-Status WARNING "$($r.Program) installed; Windows must restart to finish." }
                default     { Write-Status ERROR ("{0} -> winget exit code 0x{1:X8}" -f $r.Program, $r.ExitCode) }
            }
        }

        Write-Status INFO "Start the service, then set the Web UI credentials and pair Moonlight from PHPorto (Acesso Remoto)."
        Write-Status INFO "Web UI: $webUrl"
    }

    function Start-SunshineService {
        $svc = Get-SunshineService
        if (-not $svc) {
            Write-Status ERROR "Sunshine service not found. Run the 'install' subaction first."
            return
        }
        if ($svc.Status -eq 'Running') {
            Write-Status INFO "Sunshine service already running ($($svc.Name))."
        } else {
            try {
                Start-Service -Name $svc.Name -ErrorAction Stop
                Write-Status OK "Sunshine service started ($($svc.Name))."
            } catch {
                Write-Status ERROR "Failed to start Sunshine service: $($_.Exception.Message)"
                return
            }
        }
        Write-Status INFO "To pair Moonlight, use 'Parear com o PIN' in PHPorto (Acesso Remoto). Web UI: $webUrl"
    }

    function Stop-SunshineService {
        $svc = Get-SunshineService
        if (-not $svc) {
            Write-Status ERROR "Sunshine service not found."
            return
        }
        if ($svc.Status -ne 'Running') {
            Write-Status INFO "Sunshine service already stopped ($($svc.Name))."
            return
        }
        try {
            Stop-Service -Name $svc.Name -Force -ErrorAction Stop
            Write-Status OK "Sunshine service stopped ($($svc.Name))."
        } catch {
            Write-Status ERROR "Failed to stop Sunshine service: $($_.Exception.Message)"
        }
    }

    function Open-SunshineFirewall {
        $existing = Get-NetFirewallRule -DisplayName $fwRule -ErrorAction SilentlyContinue
        if ($existing) {
            Write-Status INFO "Firewall rule already exists: '$fwRule'."
            return
        }
        try {
            New-NetFirewallRule -DisplayName $fwRule -Direction Inbound -Protocol TCP -LocalPort $port -Action Allow -Profile Any | Out-Null
            Write-Status OK "Firewall rule created: '$fwRule' (TCP $port inbound)."
        } catch {
            Write-Status ERROR "Failed to create firewall rule: $($_.Exception.Message)"
        }
    }

    function Close-SunshineFirewall {
        $existing = Get-NetFirewallRule -DisplayName $fwRule -ErrorAction SilentlyContinue
        if (-not $existing) {
            Write-Status INFO "No firewall rule '$fwRule' to remove."
            return
        }
        try {
            Remove-NetFirewallRule -DisplayName $fwRule -ErrorAction Stop
            Write-Status OK "Firewall rule removed: '$fwRule'."
        } catch {
            Write-Status ERROR "Failed to remove firewall rule: $($_.Exception.Message)"
        }
    }

    if (-not $SubAction) {
        Write-Status ERROR "No subaction specified."
        Write-Status INFO "Options: $opcoes"
        return
    }

    $sub = $SubAction.ToLower()

    $credencial = @('set-creds', 'pair')
    if ($sub -notin $credencial) {
        foreach ($campo in 'User', 'Password', 'Pin', 'DeviceName', 'SetCreds') {
            if ($PSBoundParameters.ContainsKey($campo)) {
                Write-Status ERROR "$campo não vale para a subação '$sub'."
                $global:LASTEXITCODE = 1
                return
            }
        }
    } else {
        if (-not $User -or -not $Password) {
            Write-Status ERROR "set-creds e pair exigem User e Password."
            $global:LASTEXITCODE = 1
            return
        }
        if ($sub -eq 'set-creds' -and ($PSBoundParameters.ContainsKey('Pin') -or $PSBoundParameters.ContainsKey('DeviceName'))) {
            Write-Status ERROR "set-creds não usa Pin nem DeviceName."
            $global:LASTEXITCODE = 1
            return
        }
        if ($sub -eq 'pair' -and $Pin -cnotmatch '\A[0-9]{4}\z') {
            Write-Status ERROR "pair exige Pin de 4 dígitos."
            $global:LASTEXITCODE = 1
            return
        }
    }

    switch ($sub) {
        'status'         { Show-SunshineStatus }
        'install'        { Install-Sunshine }
        'start'          { Start-SunshineService }
        'stop'           { Stop-SunshineService }
        'firewall-open'  { Open-SunshineFirewall }
        'firewall-close' { Close-SunshineFirewall }
        'set-creds' {
            $exe = Get-PhportoSunshineExe
            if (-not $exe -or -not (Set-PhportoSunshineCreds -Exe $exe -User $User -Password $Password)) {
                $global:LASTEXITCODE = 1
                return
            }
            Write-Status INFO "Web UI: $webUrl"
        }
        'pair' {
            $nome = if ($DeviceName) { $DeviceName } else { 'notebook' }
            if (-not (Invoke-PhportoSunshineParear -User $User -Password $Password -Pin $Pin -DeviceName $nome -SetCreds:$SetCreds)) {
                $global:LASTEXITCODE = 1
                return
            }
            Write-Status INFO "Web UI: $webUrl"
        }
        default {
            Write-Status ERROR "Unknown subaction: '$SubAction'."
            Write-Status INFO "Options: $opcoes"
        }
    }
}

$script:PhportoSunshineHost  = '127.0.0.1'
$script:PhportoSunshinePorta = 47990

function Test-PhportoSunshinePorta {
    $client = New-Object System.Net.Sockets.TcpClient
    try {
        $iar = $client.BeginConnect('127.0.0.1', 47990, $null, $null)
        $ok  = $iar.AsyncWaitHandle.WaitOne(1500)
        return ($ok -and $client.Connected)
    } catch {
        return $false
    } finally {
        $client.Close()
    }
}

function Get-PhportoSunshineServico {
    return Get-Service -Name 'SunshineService' -ErrorAction SilentlyContinue
}

function Get-PhportoSunshinePasta {
    try {
        $svc = Get-CimInstance -ClassName Win32_Service -Filter "Name='SunshineService'" -ErrorAction Stop
    } catch {
        $svc = $null
    }
    if (-not $svc -or -not $svc.PathName) {
        Write-Status ERROR "Serviço SunshineService não encontrado. Instale o Sunshine (botão Instalar) e tente de novo."
        return $null
    }

    $bruto = ([string]$svc.PathName).Trim()
    if ($bruto.StartsWith('"')) {
        $fim     = $bruto.IndexOf('"', 1)
        $caminho = if ($fim -gt 1) { $bruto.Substring(1, $fim - 1) } else { '' }
    } elseif ($bruto -match '\A(.+?\.exe)') {
        $caminho = $Matches[1]
    } else {
        $caminho = ''
    }

    if (-not $caminho) {
        Write-Status ERROR "Não foi possível ler o caminho do serviço SunshineService."
        return $null
    }

    $pasta = $caminho.Substring(0, [Math]::Max(0, $caminho.LastIndexOf('\')))
    if ($pasta.Substring($pasta.LastIndexOf('\') + 1) -ieq 'tools') {
        $pasta = $pasta.Substring(0, [Math]::Max(0, $pasta.LastIndexOf('\')))
    }

    $base = ([string]$env:ProgramFiles).TrimEnd('\') + '\'
    if (-not $env:ProgramFiles -or -not ($pasta + '\').StartsWith($base, [System.StringComparison]::OrdinalIgnoreCase) -or $pasta.Contains('..')) {
        Write-Status ERROR "O Sunshine está instalado fora de Program Files ($pasta). O PHPorto só grava senha e pareia com a instalação padrão."
        return $null
    }

    return $pasta
}

function Get-PhportoSunshineExe {
    $pasta = Get-PhportoSunshinePasta
    if (-not $pasta) { return $null }

    $exe = $pasta + '\sunshine.exe'
    if (-not (Test-Path -LiteralPath $exe -PathType Leaf)) {
        Write-Status ERROR "sunshine.exe não encontrado em $pasta. Reinstale o Sunshine."
        return $null
    }
    return $exe
}

function Get-PhportoSunshineCertSha256([string]$Pasta) {
    $config = Join-Path $Pasta 'config'
    $conf   = Join-Path $config 'sunshine.conf'
    if (Test-Path -LiteralPath $conf -PathType Leaf) {
        $linhas = [System.IO.File]::ReadAllLines($conf)
        if ($linhas | Where-Object { $_ -match '\A\s*cert\s*=' }) {
            Write-Status ERROR "O sunshine.conf aponta um certificado próprio (cert = ...). O PHPorto só confere o certificado padrão; pareie pela Web UI."
            return $null
        }
    }

    $pem = Join-Path (Join-Path $config 'credentials') 'cacert.pem'
    if (-not (Test-Path -LiteralPath $pem -PathType Leaf)) {
        Write-Status ERROR "Certificado do Sunshine não encontrado ($pem). Inicie o serviço uma vez para ele ser gerado."
        return $null
    }

    $texto = [System.IO.File]::ReadAllText($pem)
    if ($texto -notmatch '-----BEGIN CERTIFICATE-----([A-Za-z0-9+/=\s]+)-----END CERTIFICATE-----') {
        Write-Status ERROR "O arquivo $pem não tem um certificado legível."
        return $null
    }

    try {
        $der = [System.Convert]::FromBase64String(($Matches[1] -replace '\s', ''))
    } catch {
        Write-Status ERROR "O arquivo $pem não tem um certificado legível."
        return $null
    }

    return (Get-PhportoSha256Hex $der)
}

function Get-PhportoSha256Hex([byte[]]$Bytes) {
    $sha = [System.Security.Cryptography.SHA256]::Create()
    try {
        return ([System.BitConverter]::ToString($sha.ComputeHash($Bytes)) -replace '-', '')
    } finally {
        $sha.Dispose()
    }
}

function ConvertTo-PhportoArgvWin {
    param([string[]]$Argumentos)

    $partes = foreach ($arg in $Argumentos) {
        if ($arg -ne '' -and $arg -notmatch '[\s"]') {
            $arg
            continue
        }

        $sb     = New-Object System.Text.StringBuilder
        $barras = 0
        [void]$sb.Append('"')
        foreach ($c in $arg.ToCharArray()) {
            if ($c -eq [char]'\') {
                $barras++
                continue
            }
            if ($c -eq [char]'"') {
                [void]$sb.Append([char]'\', (2 * $barras) + 1)
                [void]$sb.Append('"')
            } else {
                [void]$sb.Append([char]'\', $barras)
                [void]$sb.Append($c)
            }
            $barras = 0
        }
        [void]$sb.Append([char]'\', 2 * $barras)
        [void]$sb.Append('"')
        $sb.ToString()
    }

    return ($partes -join ' ')
}

function Invoke-PhportoSunshineCredsExe {
    param([string]$Exe, [string]$User, [string]$Password)

    $psi = New-Object System.Diagnostics.ProcessStartInfo
    $psi.FileName               = $Exe
    $psi.Arguments              = '--creds ' + (ConvertTo-PhportoArgvWin @($User, $Password))
    $psi.WorkingDirectory       = Split-Path -Path $Exe -Parent
    $psi.UseShellExecute        = $false
    $psi.CreateNoWindow         = $true
    $psi.RedirectStandardOutput = $true
    $psi.RedirectStandardError  = $true

    $p = [System.Diagnostics.Process]::Start($psi)
    try {
        [void]$p.StandardOutput.ReadToEndAsync()
        [void]$p.StandardError.ReadToEndAsync()
        if (-not $p.WaitForExit(30000)) {
            try { $p.Kill() } catch { }
            return $null
        }
        return $p.ExitCode
    } finally {
        $p.Dispose()
    }
}

function Wait-PhportoSunshineServico {
    param([string]$Estado, [int]$Segundos, [switch]$Porta)

    for ($i = 0; $i -lt ($Segundos * 2); $i++) {
        $svc = Get-PhportoSunshineServico
        if ($svc -and [string]$svc.Status -eq $Estado -and (-not $Porta -or (Test-PhportoSunshinePorta))) {
            return $true
        }
        Start-Sleep -Milliseconds 500
    }
    return $false
}

function Set-PhportoSunshineCreds {
    param([string]$Exe, [string]$User, [string]$Password)

    if (-not (Get-PhportoSunshineServico)) {
        Write-Status ERROR "Serviço SunshineService não encontrado. Instale o Sunshine (botão Instalar) e tente de novo."
        return $false
    }

    try {
        Stop-Service -Name 'SunshineService' -Force -ErrorAction Stop
    } catch {
        Write-Status ERROR "Não foi possível parar o serviço SunshineService: $($_.Exception.Message)"
        return $false
    }
    if (-not (Wait-PhportoSunshineServico -Estado 'Stopped' -Segundos 15)) {
        Write-Status ERROR "O serviço SunshineService não parou em 15 s. Nada foi gravado."
        Start-Service -Name 'SunshineService' -ErrorAction SilentlyContinue
        return $false
    }

    $gravou = $false
    try {
        $codigo = Invoke-PhportoSunshineCredsExe -Exe $Exe -User $User -Password $Password
        if ($null -eq $codigo) {
            Write-Status ERROR "sunshine.exe --creds não terminou em 30 s. As credenciais podem não ter sido gravadas."
        } elseif ($codigo -ne 0) {
            Write-Status ERROR "sunshine.exe --creds falhou (código $codigo). As credenciais não foram gravadas."
        } else {
            $gravou = $true
        }
    } catch {
        Write-Status ERROR "Não foi possível rodar o sunshine.exe: $($_.Exception.Message)"
    } finally {
        try {
            Start-Service -Name 'SunshineService' -ErrorAction Stop
        } catch {
            Write-Status ERROR "Não foi possível subir o serviço SunshineService de volta: $($_.Exception.Message)"
        }
    }

    if (-not (Wait-PhportoSunshineServico -Estado 'Running' -Segundos 20 -Porta)) {
        Write-Status ERROR "O serviço SunshineService não voltou com a porta 47990 respondendo em 20 s. Use Iniciar serviço e Ver o estado."
        return $false
    }

    if ($gravou) {
        Write-Status OK "Credenciais gravadas; serviço SunshineService de volta (porta 47990 respondendo)."
    }
    return $gravou
}

function Invoke-PhportoSunshineApi {
    param(
        [ValidateSet('GET', 'POST')] [string]$Metodo,
        [string]$Caminho,
        $Corpo = $null,
        [string]$User,
        [string]$Password,
        [string]$CertSha256
    )

    $alvoHost  = $script:PhportoSunshineHost
    $alvoPorta = $script:PhportoSunshinePorta
    $esperado  = $CertSha256

    [System.Net.ServicePointManager]::SecurityProtocol = [System.Net.ServicePointManager]::SecurityProtocol -bor [System.Net.SecurityProtocolType]::Tls12

    $req = [System.Net.HttpWebRequest]::Create([Uri]('https://' + $alvoHost + ':' + $alvoPorta + $Caminho))
    $req.Method            = $Metodo
    $req.Proxy             = $null
    $req.AllowAutoRedirect = $false
    $req.KeepAlive         = $false
    $req.Timeout           = 35000
    $req.ReadWriteTimeout  = 35000
    $req.Accept            = 'application/json'
    $req.Headers.Add('Authorization', 'Basic ' + [System.Convert]::ToBase64String([System.Text.Encoding]::UTF8.GetBytes($User + ':' + $Password)))
    $req.ServerCertificateValidationCallback = {
        param($remetente, $cert, $cadeia, $erros)
        if ($null -eq $cert -or $null -eq $remetente) { return $false }
        if ($remetente.RequestUri.Host -ne $alvoHost -or $remetente.RequestUri.Port -ne $alvoPorta) { return $false }
        $h = [System.Security.Cryptography.SHA256]::Create()
        try {
            $visto = [System.BitConverter]::ToString($h.ComputeHash($cert.GetRawCertData())) -replace '-', ''
        } finally {
            $h.Dispose()
        }
        return ($visto -eq $esperado)
    }.GetNewClosure()

    $resp = $null
    try {
        if ($null -ne $Corpo) {
            $bytes = [System.Text.Encoding]::UTF8.GetBytes((ConvertTo-Json -InputObject $Corpo -Compress))
            $req.ContentType   = 'application/json'
            $req.ContentLength = $bytes.Length
            $fluxo = $req.GetRequestStream()
            try { $fluxo.Write($bytes, 0, $bytes.Length) } finally { $fluxo.Dispose() }
        }
        $resp = $req.GetResponse()
    } catch {
        $we = $_.Exception
        while ($null -ne $we -and $we -isnot [System.Net.WebException]) { $we = $we.InnerException }

        if ($null -ne $we -and $null -ne $we.Response) {
            $resp = $we.Response
        } elseif ($null -ne $we -and $we.Status -eq [System.Net.WebExceptionStatus]::TrustFailure) {
            return [PSCustomObject]@{ Status = 0; Location = ''; Json = $null; Falha = 'certificado' }
        } else {
            return [PSCustomObject]@{ Status = 0; Location = ''; Json = $null; Falha = 'rede' }
        }
    }

    try {
        $leitor = New-Object System.IO.StreamReader($resp.GetResponseStream(), [System.Text.Encoding]::UTF8)
        try { $texto = $leitor.ReadToEnd() } finally { $leitor.Dispose() }

        $json = $null
        if ($texto) {
            try { $json = $texto | ConvertFrom-Json } catch { $json = $null }
        }

        return [PSCustomObject]@{
            Status   = [int]$resp.StatusCode
            Location = [string]$resp.Headers['Location']
            Json     = $json
            Falha    = ''
        }
    } finally {
        $resp.Close()
    }
}

function Get-PhportoSunshineMotivo($Resposta) {
    if ($Resposta.Falha -eq 'certificado') {
        return "O certificado em 127.0.0.1:47990 não é o do Sunshine instalado. Nada foi enviado."
    }
    if ($Resposta.Falha -eq 'rede') {
        return "Sem resposta do Sunshine em 127.0.0.1:47990. Confira com Ver o estado se o serviço está no ar."
    }
    if ($Resposta.Status -eq 401) {
        return "Usuário ou senha da Web UI não conferem. Marque 'Gravar estas credenciais' para trocá-las."
    }
    if ($Resposta.Status -ge 300 -and $Resposta.Status -lt 400) {
        if ($Resposta.Location -match 'welcome') {
            return "O Sunshine ainda não tem credenciais. Marque 'Gravar estas credenciais'."
        }
        return "O Sunshine redirecionou o pedido ($($Resposta.Status)) para um lugar inesperado."
    }
    return $null
}

function Invoke-PhportoSunshinePair {
    param([string]$User, [string]$Password, [string]$Pin, [string]$DeviceName, [string]$CertSha256)

    $auth = @{ User = $User; Password = $Password; CertSha256 = $CertSha256 }

    $lista = Invoke-PhportoSunshineApi -Metodo GET -Caminho '/api/pin' @auth
    $motivo = Get-PhportoSunshineMotivo $lista
    if ($motivo) {
        Write-Status ERROR $motivo
        return $false
    }

    $origem = ''
    if ($lista.Status -eq 404) {
        $corpo = [ordered]@{ pin = $Pin; name = $DeviceName }
    } elseif ($lista.Status -eq 200 -and $null -ne $lista.Json -and $lista.Json.PSObject.Properties.Name -contains 'pairings') {
        $pendentes = @($lista.Json.pairings | Where-Object { $null -ne $_ })

        if ($pendentes.Count -eq 0) {
            Write-Status ERROR "Nenhum Moonlight está esperando PIN. Inicie o pareamento no Moonlight e tente de novo (o PIN expira)."
            return $false
        }
        if ($pendentes.Count -gt 1) {
            Write-Status ERROR "Há $($pendentes.Count) Moonlight esperando PIN; o PHPorto não escolhe um. Cancele os outros no Moonlight e tente de novo:"
            foreach ($p in $pendentes) {
                Write-Status INFO ("  - " + [string]$p.name + " (" + [string]$p.address + ")")
            }
            return $false
        }

        $id = [string]$pendentes[0].id
        if ($id -cnotmatch '\A[0-9a-fA-F]{32}\z') {
            Write-Status ERROR "O Sunshine devolveu um pedido de pareamento com id fora do formato esperado."
            return $false
        }
        $origem = [string]$pendentes[0].address
        $corpo  = [ordered]@{ pairing_id = $id; pin = $Pin; name = $DeviceName }
    } else {
        Write-Status ERROR "Resposta inesperada do Sunshine ao listar os pareamentos (HTTP $($lista.Status)). A API pode ter mudado."
        return $false
    }

    $resp = Invoke-PhportoSunshineApi -Metodo POST -Caminho '/api/pin' -Corpo $corpo @auth
    $motivo = Get-PhportoSunshineMotivo $resp
    if ($motivo) {
        Write-Status ERROR $motivo
        return $false
    }
    if ($resp.Status -ne 200) {
        Write-Status ERROR "O Sunshine recusou o pareamento (HTTP $($resp.Status)). Gere outro PIN no Moonlight e tente de novo."
        return $false
    }

    $status = if ($null -ne $resp.Json) { $resp.Json.status } else { $null }
    if ($status -eq $true -or [string]$status -eq 'true') {
        $de = if ($origem) { " (pedido de $origem)" } else { '' }
        Write-Status OK ("Pareado: `"" + $DeviceName + "`"" + $de + ".")
        return $true
    }

    Write-Status ERROR "PIN errado, expirado ou o Moonlight desistiu. Gere outro PIN."
    return $false
}

function Invoke-PhportoSunshineParear {
    param([string]$User, [string]$Password, [string]$Pin, [string]$DeviceName, [switch]$SetCreds)

    $exe = Get-PhportoSunshineExe
    if (-not $exe) { return $false }

    if ($SetCreds) {
        if (-not (Set-PhportoSunshineCreds -Exe $exe -User $User -Password $Password)) { return $false }
    } elseif (-not (Test-PhportoSunshinePorta)) {
        Write-Status ERROR "O Sunshine não está respondendo na porta 47990. Use Iniciar serviço e tente de novo."
        return $false
    }

    $sha = Get-PhportoSunshineCertSha256 ($exe.Substring(0, $exe.LastIndexOf('\')))
    if (-not $sha) { return $false }

    return (Invoke-PhportoSunshinePair -User $User -Password $Password -Pin $Pin -DeviceName $DeviceName -CertSha256 $sha)
}
