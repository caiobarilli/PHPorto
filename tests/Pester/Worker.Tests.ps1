#Requires -Version 5.1

BeforeAll {
    $Script:RaizProjeto = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $Script:PastaWin    = Join-Path $Script:RaizProjeto 'src\Win'
    $Script:Worker      = Join-Path $Script:PastaWin 'worker.ps1'

    $ast = [System.Management.Automation.Language.Parser]::ParseFile(
        $Script:Worker, [ref]$null, [ref]$null
    )
    $ast.FindAll(
        { param($n) $n -is [System.Management.Automation.Language.FunctionDefinitionAst] },
        $false
    ) | ForEach-Object { Invoke-Expression $_.Extent.Text }

    $ast.FindAll(
        {
            param($n)
            $n -is [System.Management.Automation.Language.AssignmentStatementAst] -and
            $n.Left -is [System.Management.Automation.Language.VariableExpressionAst] -and
            $n.Left.VariablePath.UserPath -in @('ALLOWLIST', 'SENSIVEIS', 'SEGREDOS', 'MAX_PARAM_BYTES', 'SID_ADMINS', 'SID_SYSTEM', 'SID_DONO')
        },
        $false
    ) | ForEach-Object { Invoke-Expression $_.Extent.Text }

    $global:ALLOWLIST       = $ALLOWLIST
    $global:SENSIVEIS       = $SENSIVEIS
    $global:SEGREDOS        = $SEGREDOS
    $global:MAX_PARAM_BYTES = $MAX_PARAM_BYTES
    $global:SID_ADMINS      = $SID_ADMINS
    $global:SID_SYSTEM      = $SID_SYSTEM
    $global:SID_DONO        = $SID_DONO

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

Describe 'worker - encoding' {

    It 'worker.ps1 tem BOM UTF-8, sem ele o 5.1 le como ANSI' {
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

        $texto | Should -Not -Match '\$Winutil'
        $texto | Should -Not -Match 'PHPORTO_WINUTIL_PATH'
    }
}

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
        Write-Done 'teste02' 0 1 '' 'audit' $null

        $j = Get-Content (Join-Path $Script:Trabalho 'win-done-teste02.json') -Raw | ConvertFrom-Json

        $j.fim | Should -Match '^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$'

        $agora = [DateTime]::UtcNow
        $lido  = [DateTime]::ParseExact($j.fim, 'yyyy-MM-dd HH:mm:ss', [System.Globalization.CultureInfo]::InvariantCulture)
        [Math]::Abs(($agora - $lido).TotalMinutes) | Should -BeLessThan 2
    }

    It 'params sai como OBJETO JSON, e nao como texto de tipo .NET' {
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

    It 'senha e PIN do sunshine saem como ***, e o resto como veio' {
        Write-Done 'teste07' 0 1 '' 'sunshine' ([ordered]@{ SubAction = 'pair'; User = 'admin'; Password = 'Xyzzy12345'; Pin = '4821'; DeviceName = 'notebook'; SetCreds = $true })

        $bruto = Get-Content (Join-Path $Script:Trabalho 'win-done-teste07.json') -Raw
        $j = $bruto | ConvertFrom-Json

        $j.params.Password   | Should -Be '***'
        $j.params.Pin        | Should -Be '***'
        $j.params.User       | Should -Be 'admin'
        $j.params.DeviceName | Should -Be 'notebook'
        $j.params.SetCreds   | Should -BeTrue
        $bruto | Should -Not -Match 'Xyzzy12345'
        $bruto | Should -Not -Match '4821'
    }

    It 'a mascara nao altera o hashtable de quem chamou' {
        $params = [ordered]@{ SubAction = 'set-creds'; User = 'admin'; Password = 'Xyzzy12345' }
        Write-Done 'teste08' 0 1 '' 'sunshine' $params
        $params['Password'] | Should -Be 'Xyzzy12345'
    }

    It 'outra acao com um parametro chamado Pin nao seria mascarada por engano' {
        (Hide-PhportoSegredos 'gdid' ([ordered]@{ SubAction = 'status' }))['SubAction'] | Should -Be 'status'
        (Hide-PhportoSegredos 'sunshine' $null).Count | Should -Be 0
    }

    It 'o JSON sai sem BOM, com BOM o json_decode do PHP devolve null' {
        Write-Done 'teste06' 0 1 '' 'audit' $null

        $bytes = [System.IO.File]::ReadAllBytes((Join-Path $Script:Trabalho 'win-done-teste06.json'))
        $bytes[0] | Should -Not -Be 0xEF
    }

    It 'a interrupcao existe como caminho proprio, e deixa sinal de fim' {
        Get-Command -Name 'Stop-FilhoComSinal' -CommandType Function -ErrorAction SilentlyContinue |
            Should -Not -BeNullOrEmpty

        $texto = Get-Content -Path $Script:Worker -Raw

        $texto | Should -Match "Stop-FilhoComSinal 'pai desapareceu'"
        $texto | Should -Match "Stop-FilhoComSinal 'desligando'"
        $texto | Should -Match "Write-Done .*'interrompido'"
    }
}

Describe 'worker - a allowlist do lado elevado' {

    It 'um parametro de lista no meio nao apaga a allowlist da acao para os parametros seguintes' {
        $item = @(Get-PhportoListaPermitida 'tweaks')[0]
        $job  = [PSCustomObject]@{
            nonce  = $global:Nonce
            acao   = 'tweaks'
            params = [PSCustomObject]@{ Items = $item; Undo = $true }
        }

        $ok = Test-Job $job
        $ok.params['Items'] | Should -Be @($item)
        $ok.params['Undo']  | Should -BeTrue
    }

    It 'conhece as vinte e tres acoes' {
        @($global:ALLOWLIST.Keys | Sort-Object) | Should -Be @(
            'audit', 'debloat', 'dns', 'exporter', 'gdid', 'gpu', 'hyperv', 'hyperv-vm', 'install',
            'memory', 'network', 'openssh', 'optimize', 'performance', 'php-update', 'processes', 'rdp', 'ssh-keys', 'sunshine', 'tweaks', 'wsl', 'wsl-enable', 'wsl-install-distro'
        )
    }

    It 'wsl-enable: so passa com UAC proprio e nao aceita parametro' {
        $job = [PSCustomObject]@{ nonce = $global:Nonce; acao = 'wsl-enable'; params = [PSCustomObject]@{} }

        { Test-Job $job } | Should -Throw -ExpectedMessage '*so roda com UAC proprio*'
        $ok = Test-Job $job -Modo UmaVez
        $ok.params.Count | Should -Be 0

        $extra = [PSCustomObject]@{ acao = 'wsl-enable'; params = [PSCustomObject]@{ Distro = 'Debian' } }
        { Test-Job $extra -Modo UmaVez } | Should -Throw
    }

    It 'wsl-install-distro: so passa com UAC proprio e so aceita um nome de distro valido' {
        $job = [PSCustomObject]@{ nonce = $global:Nonce; acao = 'wsl-install-distro'; params = [PSCustomObject]@{ Distro = 'Debian' } }

        { Test-Job $job } | Should -Throw -ExpectedMessage '*so roda com UAC proprio*'
        $ok = Test-Job $job -Modo UmaVez
        $ok.params['Distro'] | Should -Be 'Debian'

        foreach ($ruim in @('Debian; calc', '-d', '../x', 'a b', ('a' * 65))) {
            $mau = [PSCustomObject]@{ acao = 'wsl-install-distro'; params = [PSCustomObject]@{ Distro = $ruim } }
            { Test-Job $mau -Modo UmaVez } | Should -Throw
        }

        $extra = [PSCustomObject]@{ acao = 'wsl-install-distro'; params = [PSCustomObject]@{ Distro = 'Debian'; SubAction = 'x' } }
        { Test-Job $extra -Modo UmaVez } | Should -Throw
    }

    It 'php-update: so passa com UAC proprio, e a pasta fora do formato nao passa' {
        $sha = 'ab' * 32
        $job = [PSCustomObject]@{ nonce = $global:Nonce; acao = 'php-update'; params = [PSCustomObject]@{ Version = '8.5.13'; Sha256 = $sha; PhpDir = 'C:\php' } }

        { Test-Job $job } | Should -Throw -ExpectedMessage '*so roda com UAC proprio*'
        $ok = Test-Job $job -Modo UmaVez
        $ok.params['Version'] | Should -Be '8.5.13'
        $ok.params['Sha256']  | Should -Be $sha
        $ok.params['PhpDir']  | Should -Be 'C:\php'

        foreach ($pasta in @('C:\', 'C:\..\x', 'C:/php', 'C:\php\', '\\srv\php')) {
            $ruim = [PSCustomObject]@{ acao = 'php-update'; params = [PSCustomObject]@{ Version = '8.5.13'; Sha256 = $sha; PhpDir = $pasta } }
            { Test-Job $ruim -Modo UmaVez } | Should -Throw -ExpectedMessage "'PhpDir' fora do formato"
        }

        $curto = [PSCustomObject]@{ acao = 'php-update'; params = [PSCustomObject]@{ Version = '8.5.13'; Sha256 = 'ab'; PhpDir = 'C:\php' } }
        { Test-Job $curto -Modo UmaVez } | Should -Throw -ExpectedMessage "'Sha256' abaixo do minimo de bytes"
    }

    It 'ssh-keys: authorize so passa com UAC proprio, e a linha com opcoes na frente nao passa' {
        $chave = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8g notebook'
        $job   = [PSCustomObject]@{ nonce = $global:Nonce; acao = 'ssh-keys'; params = [PSCustomObject]@{ SubAction = 'authorize'; PublicKey = $chave } }

        { Test-Job $job } | Should -Throw -ExpectedMessage '*so roda com UAC proprio*'
        (Test-Job $job -Modo UmaVez).params['PublicKey'] | Should -Be $chave

        $ruim = [PSCustomObject]@{ acao = 'ssh-keys'; params = [PSCustomObject]@{ SubAction = 'authorize'; PublicKey = ('command="x" ' + $chave) } }
        { Test-Job $ruim -Modo UmaVez } | Should -Throw -ExpectedMessage "'PublicKey' fora do formato"
    }

    It 'ssh-keys: nome da chave fora do formato nao passa' {
        $job = [PSCustomObject]@{ nonce = $global:Nonce; acao = 'ssh-keys'; params = [PSCustomObject]@{ SubAction = 'generate'; KeyName = '..\x' } }
        { Test-Job $job } | Should -Throw -ExpectedMessage "'KeyName' fora do formato"

        $bom = [PSCustomObject]@{ nonce = $global:Nonce; acao = 'ssh-keys'; params = [PSCustomObject]@{ SubAction = 'generate'; KeyName = 'trabalho' } }
        (Test-Job $bom).params['KeyName'] | Should -Be 'trabalho'
    }

    It 'ssh-keys: usuario do WSL fora do formato nao passa' {
        $job = [PSCustomObject]@{ acao = 'ssh-keys'; params = [PSCustomObject]@{ SubAction = 'wsl-user'; UseGenerated = $true; User = 'Dev;id'; Distro = 'Debian' } }
        { Test-Job $job -Modo UmaVez } | Should -Throw -ExpectedMessage "'User' fora do formato"
    }

    It 'ssh-keys: status e list rodam no laco, sem UAC' {
        foreach ($sub in 'status', 'list', 'generate') {
            $job = [PSCustomObject]@{ nonce = $global:Nonce; acao = 'ssh-keys'; params = [PSCustomObject]@{ SubAction = $sub } }
            (Test-Job $job).params['SubAction'] | Should -Be $sub
        }
    }

    It 'aceita sunshine com <_>' -ForEach @('status', 'install', 'start', 'stop', 'firewall-open', 'firewall-close', 'set-creds', 'pair') {
        $job = [PSCustomObject]@{
            nonce  = $global:Nonce
            acao   = 'sunshine'
            params = [PSCustomObject]@{ SubAction = $_ }
        }

        $modo = if (Test-AcaoSensivel 'sunshine' @{ SubAction = $_ }) { 'UmaVez' } else { 'Laco' }
        (Test-Job $job -Modo $modo).params['SubAction'] | Should -Be $_
    }

    It 'recusa uma subacao que o sunshine nao tem' {
        $job = [PSCustomObject]@{
            nonce  = $global:Nonce
            acao   = 'sunshine'
            params = [PSCustomObject]@{ SubAction = 'pin' }
        }

        { Test-Job $job } | Should -Throw -ExpectedMessage "valor fora do conjunto em 'SubAction'"
    }

    It 'sunshine pair passa no uso unico com os cinco campos, a senha intacta' {
        $job = [PSCustomObject]@{
            acao   = 'sunshine'
            params = [PSCustomObject]@{ SubAction = 'pair'; User = 'admin'; Password = ' a:b"c\d 9 '; Pin = '0482'; DeviceName = 'TV da sala'; SetCreds = $true }
        }

        $ok = Test-Job $job -Modo UmaVez
        $ok.params['Password']   | Should -BeExactly ' a:b"c\d 9 '
        $ok.params['Pin']        | Should -BeExactly '0482'
        $ok.params['DeviceName'] | Should -BeExactly 'TV da sala'
        $ok.params['SetCreds']   | Should -BeTrue
    }

    It 'sunshine recusa <Campo> invalido, e a frase cita so o nome' -ForEach @(
        @{ Campo = 'Pin';        Valor = '123' }
        @{ Campo = 'Pin';        Valor = '12a4' }
        @{ Campo = 'Pin';        Valor = ([string][char]0x0661 * 4) }
        @{ Campo = 'User';       Valor = 'ad:min' }
        @{ Campo = 'User';       Valor = "ad`tmin" }
        @{ Campo = 'Password';   Valor = "Xyzzy`n12345" }
        @{ Campo = 'DeviceName'; Valor = ('tv' + [char]0x1B + 'sala') }
    ) {
        $p = [ordered]@{ SubAction = 'pair'; User = 'admin'; Password = 'Xyzzy12345'; Pin = '4821' }
        $p[$Campo] = $Valor
        $job = [PSCustomObject]@{ acao = 'sunshine'; params = [PSCustomObject]$p }

        { Test-Job $job -Modo UmaVez } | Should -Throw -ExpectedMessage "'$Campo' *"
    }

    It 'sunshine recusa senha abaixo de 8 bytes, e conta bytes: quatro acentos passam' {
        $curta = [PSCustomObject]@{ acao = 'sunshine'; params = [PSCustomObject]@{ SubAction = 'set-creds'; User = 'admin'; Password = '1234567' } }
        { Test-Job $curta -Modo UmaVez } | Should -Throw -ExpectedMessage "'Password' abaixo do minimo de bytes"

        $acentos = [PSCustomObject]@{ acao = 'sunshine'; params = [PSCustomObject]@{ SubAction = 'set-creds'; User = 'admin'; Password = "$([char]0xE3)$([char]0xE9)$([char]0xED)$([char]0xF5)" } }
        (Test-Job $acentos -Modo UmaVez).params['Password'].Length | Should -Be 4
    }

    It 'sunshine aplica o teto proprio de <Campo> (<Teto> bytes), e nao o geral' -ForEach @(
        @{ Campo = 'User';       Teto = 64 }
        @{ Campo = 'Password';   Teto = 256 }
        @{ Campo = 'DeviceName'; Teto = 128 }
    ) {
        $p = [ordered]@{ SubAction = 'pair'; User = 'admin'; Password = 'Xyzzy12345'; Pin = '4821' }
        $p[$Campo] = 'a' * $Teto
        (Test-Job ([PSCustomObject]@{ acao = 'sunshine'; params = [PSCustomObject]$p }) -Modo UmaVez).params[$Campo].Length | Should -Be $Teto

        $p[$Campo] = ('a' * ($Teto - 1)) + [char]0xE9
        { Test-Job ([PSCustomObject]@{ acao = 'sunshine'; params = [PSCustomObject]$p }) -Modo UmaVez } | Should -Throw -ExpectedMessage "'$Campo' passou do teto de bytes"
    }

    It 'a frase de recusa nunca leva o valor do campo' {
        $job = [PSCustomObject]@{ acao = 'sunshine'; params = [PSCustomObject]@{ SubAction = 'pair'; User = 'admin'; Password = "Xyzzy`n12345"; Pin = '4821' } }
        try { Test-Job $job -Modo UmaVez; throw 'deveria recusar' } catch { $_.Exception.Message | Should -Not -Match 'Xyzzy' }
    }

    It 'aceita rdp com <_>' -ForEach @('status', 'on', 'off', 'h264-on', 'h264-off') {
        $job = [PSCustomObject]@{
            nonce  = $global:Nonce
            acao   = 'rdp'
            params = [PSCustomObject]@{ SubAction = $_ }
        }

        $modo = if (Test-AcaoSensivel 'rdp' @{ SubAction = $_ }) { 'UmaVez' } else { 'Laco' }
        (Test-Job $job -Modo $modo).params['SubAction'] | Should -Be $_
    }

    It 'recusa subacao que o rdp nao tem' {
        $job = [PSCustomObject]@{
            nonce  = $global:Nonce
            acao   = 'rdp'
            params = [PSCustomObject]@{ SubAction = 'reboot' }
        }

        { Test-Job $job } | Should -Throw -ExpectedMessage "valor fora do conjunto em 'SubAction'"
    }

    It 'aceita hyperv-vm <_> no uso unico, com o VmId intacto' -ForEach @('start', 'stop', 'restart') {
        $job = [PSCustomObject]@{ acao = 'hyperv-vm'; params = [PSCustomObject]@{ SubAction = $_; VmId = '6C1E2A4B-0D3F-4E5A-9B8C-7D6E5F4A3B2C' } }

        $ok = Test-Job $job -Modo UmaVez
        $ok.acao                | Should -Be 'hyperv-vm'
        $ok.params['SubAction'] | Should -Be $_
        $ok.params['VmId']      | Should -BeExactly '6C1E2A4B-0D3F-4E5A-9B8C-7D6E5F4A3B2C'
    }

    It 'hyperv-vm recusa subacao fora de start, stop e restart: <_>' -ForEach @('reset', 'turnoff', 'save', 'pause') {
        $job = [PSCustomObject]@{ acao = 'hyperv-vm'; params = [PSCustomObject]@{ SubAction = $_; VmId = '6c1e2a4b-0d3f-4e5a-9b8c-7d6e5f4a3b2c' } }
        { Test-Job $job -Modo UmaVez } | Should -Throw -ExpectedMessage "valor fora do conjunto em 'SubAction'"
    }

    It 'hyperv-vm recusa VmId que nao e GUID: <_>' -ForEach @(
        '*'
        'Ubuntu'
        '6c1e2a4b0d3f4e5a9b8c7d6e5f4a3b2c'
        '{6c1e2a4b-0d3f-4e5a-9b8c-7d6e5f4a3b2c}'
        '6c1e2a4b-0d3f-4e5a-9b8c-7d6e5f4a3b2g'
        "6c1e2a4b-0d3f-4e5a-9b8c-7d6e5f4a3b2c`n"
        '6c1e2a4b-0d3f-4e5a-9b8c-7d6e5f4a3b2c; Stop-VM *'
    ) {
        $job = [PSCustomObject]@{ acao = 'hyperv-vm'; params = [PSCustomObject]@{ SubAction = 'stop'; VmId = $_ } }
        { Test-Job $job -Modo UmaVez } | Should -Throw -ExpectedMessage "'VmId' *"
    }

    It 'hyperv-vm nao aceita parametro de nome' {
        $job = [PSCustomObject]@{ acao = 'hyperv-vm'; params = [PSCustomObject]@{ SubAction = 'stop'; VmId = '6c1e2a4b-0d3f-4e5a-9b8c-7d6e5f4a3b2c'; Name = '*' } }
        { Test-Job $job -Modo UmaVez } | Should -Throw -ExpectedMessage "parametro fora da allowlist para 'hyperv-vm': 'Name'"
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

    It 'funde os fluxos com *>&1, sem isso o Write-Host nao entra na captura' {
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

Describe 'worker - o script gerado roda e devolve codigo' {

    It 'recusa por falta de elevacao vira exit 1, com o motivo na saida' {
        if ($env:OS -eq 'Windows_NT' -and ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
            Write-Host 'SKIP: o host ja roda como Administrador; este teste so vale em host sem elevacao.'
            Set-ItResult -Skipped -Because 'o host ja roda como Administrador'
            return
        }
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
        $p.Attributes.Extent.Text | Should -Contain "[Parameter(Mandatory, ParameterSetName = 'Laco')]"
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
        $f = [System.IO.File]::Open((Join-Path $Script:Pasta 'win-trava'), 'Open', 'ReadWrite', 'None')
        $f.Dispose()
    }
}

Describe 'worker - Get-UsuarioPhp' {

    BeforeAll {
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
        Mock Invoke-CimMethod -RemoveParameterType InputObject { [PSCustomObject]@{ Sid = 'S-1-5-21-1-2-3-1001' } }
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

Describe 'worker - acoes sensiveis' {

    It 'toda acao e subacao sensivel existe na allowlist' {
        foreach ($acao in $global:SENSIVEIS.Keys) {
            $global:ALLOWLIST.ContainsKey($acao) | Should -BeTrue -Because $acao
            $regra = $global:SENSIVEIS[$acao]
            if ($regra -is [string]) { $regra | Should -Be '*'; continue }
            foreach ($sub in $regra) {
                $global:ALLOWLIST[$acao]['SubAction'].valores | Should -Contain $sub -Because "$acao $sub"
            }
        }
    }

    It 'Test-AcaoSensivel <Acao> <Sub> e <Esperado>' -ForEach @(
        @{ Acao = 'install';  Sub = $null;            Esperado = $true }
        @{ Acao = 'rdp';      Sub = 'on';             Esperado = $true }
        @{ Acao = 'rdp';      Sub = 'off';            Esperado = $false }
        @{ Acao = 'rdp';      Sub = 'h264-on';        Esperado = $false }
        @{ Acao = 'sunshine'; Sub = 'install';        Esperado = $true }
        @{ Acao = 'sunshine'; Sub = 'firewall-open';  Esperado = $true }
        @{ Acao = 'sunshine'; Sub = 'firewall-close'; Esperado = $false }
        @{ Acao = 'sunshine'; Sub = 'set-creds';      Esperado = $true }
        @{ Acao = 'sunshine'; Sub = 'pair';           Esperado = $true }
        @{ Acao = 'sunshine'; Sub = 'status';         Esperado = $false }
        @{ Acao = 'exporter'; Sub = 'install';        Esperado = $true }
        @{ Acao = 'exporter'; Sub = 'firewall';       Esperado = $true }
        @{ Acao = 'exporter'; Sub = 'metrics';        Esperado = $false }
        @{ Acao = 'gpu';      Sub = 'install';        Esperado = $true }
        @{ Acao = 'gpu';      Sub = 'uninstall';      Esperado = $false }
        @{ Acao = 'network';  Sub = $null;            Esperado = $false }
        @{ Acao = 'optimize'; Sub = $null;            Esperado = $false }
        @{ Acao = 'dns';      Sub = $null;            Esperado = $false }
        @{ Acao = 'hyperv-vm'; Sub = 'start';         Esperado = $true }
        @{ Acao = 'hyperv-vm'; Sub = 'stop';          Esperado = $true }
        @{ Acao = 'hyperv-vm'; Sub = 'restart';       Esperado = $true }
        @{ Acao = 'hyperv';   Sub = $null;            Esperado = $false }
    ) {
        $params = [ordered]@{}
        if ($null -ne $Sub) { $params['SubAction'] = $Sub }
        Test-AcaoSensivel $Acao $params | Should -Be $Esperado
    }

    It 'o worker longo recusa <Acao> <Sub>, mesmo com o nonce certo' -ForEach @(
        @{ Acao = 'rdp';      Sub = 'on' }
        @{ Acao = 'sunshine'; Sub = 'firewall-open' }
        @{ Acao = 'sunshine'; Sub = 'install' }
        @{ Acao = 'sunshine'; Sub = 'set-creds' }
        @{ Acao = 'sunshine'; Sub = 'pair' }
        @{ Acao = 'exporter'; Sub = 'install' }
        @{ Acao = 'exporter'; Sub = 'firewall' }
        @{ Acao = 'gpu';      Sub = 'install' }
    ) {
        $job = [PSCustomObject]@{ nonce = $global:Nonce; acao = $Acao; params = [PSCustomObject]@{ SubAction = $Sub } }
        { Test-Job $job } | Should -Throw -ExpectedMessage "acao sensivel: '$Acao $Sub' so roda com UAC proprio, pela tela"
    }

    It 'o worker longo recusa hyperv-vm <_>, mesmo com o nonce certo' -ForEach @('start', 'stop', 'restart') {
        $job = [PSCustomObject]@{ nonce = $global:Nonce; acao = 'hyperv-vm'; params = [PSCustomObject]@{ SubAction = $_; VmId = '6c1e2a4b-0d3f-4e5a-9b8c-7d6e5f4a3b2c' } }
        { Test-Job $job } | Should -Throw -ExpectedMessage "acao sensivel: 'hyperv-vm $_' so roda com UAC proprio, pela tela"
    }

    It 'o worker longo recusa install, e a recusa sai com o nome da acao' {
        $job = [PSCustomObject]@{ nonce = $global:Nonce; acao = 'install'; params = [PSCustomObject]@{ Apps = 'Mozilla.Firefox' } }
        { Test-Job $job } | Should -Throw -ExpectedMessage "acao sensivel: 'install' so roda com UAC proprio, pela tela"
    }

    It 'a subacao conta na grafia da LISTA: RDP ON tambem e sensivel' {
        $job = [PSCustomObject]@{ nonce = $global:Nonce; acao = 'rdp'; params = [PSCustomObject]@{ SubAction = 'ON' } }
        { Test-Job $job } | Should -Throw -ExpectedMessage '*acao sensivel*'
    }

    It 'o uso unico aceita a sensivel sem nonce: quem faz esse papel e o hash do pedido' {
        $job = [PSCustomObject]@{ acao = 'install'; params = [PSCustomObject]@{ Apps = 'Mozilla.Firefox' } }
        $ok = Test-Job $job -Modo UmaVez
        $ok.acao           | Should -Be 'install'
        $ok.params['Apps'] | Should -Be 'Mozilla.Firefox'
    }

    It 'o uso unico recusa Apps fora do formato de ID do winget: <_>' -ForEach @('Git.Git --override x', 'a"b.c', 'Git .Git', 'Git.Git, Mozilla.Firefox', 'Git', '--override') {
        $job = [PSCustomObject]@{ acao = 'install'; params = [PSCustomObject]@{ Apps = $_ } }
        { Test-Job $job -Modo UmaVez } | Should -Throw -ExpectedMessage "'Apps' fora do formato"
    }

    It 'o uso unico aceita lista de IDs do winget separada por virgula' {
        $job = [PSCustomObject]@{ acao = 'install'; params = [PSCustomObject]@{ Apps = 'Git.Git,Python.Python.3.12,Notepad++.Notepad++' } }
        (Test-Job $job -Modo UmaVez).params['Apps'] | Should -Be 'Git.Git,Python.Python.3.12,Notepad++.Notepad++'
    }

    It 'o uso unico recusa acao comum' {
        $job = [PSCustomObject]@{ acao = 'rdp'; params = [PSCustomObject]@{ SubAction = 'off' } }
        { Test-Job $job -Modo UmaVez } | Should -Throw -ExpectedMessage "*so roda acao sensivel, e 'rdp off' nao e'*"
    }

    It 'o uso unico continua passando pela allowlist' {
        $job = [PSCustomObject]@{ acao = 'rdp'; params = [PSCustomObject]@{ SubAction = 'on'; Kill = 'x' } }
        { Test-Job $job -Modo UmaVez } | Should -Throw -ExpectedMessage "parametro fora da allowlist para 'rdp': 'Kill'"
    }

    It 'o worker longo segue aceitando a comum' {
        $job = [PSCustomObject]@{ nonce = $global:Nonce; acao = 'rdp'; params = [PSCustomObject]@{ SubAction = 'off' } }
        (Test-Job $job).params['SubAction'] | Should -Be 'off'
    }
}

Describe 'worker - Read-PhportoPedido' {

    BeforeAll {
        function New-PedidoDeTeste([hashtable]$Troca = @{}, [string]$Nome) {
            $id = if ($Troca.ContainsKey('id')) { $Troca['id'] } else { -join ((1..12) | ForEach-Object { '0123456789abcdef'[(Get-Random -Maximum 16)] }) }
            $agora = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds()
            $p = [ordered]@{
                v         = 1
                id        = $id
                acao      = 'rdp'
                params    = [ordered]@{ SubAction = 'on' }
                php_pid   = $PID
                raiz_win  = $Script:PastaWin
                dir       = $Script:Trabalho
                criado_em = $agora
                expira_em = $agora + 75
                prazo_s   = 615
                manifesto = [ordered]@{ 'bootstrap.ps1' = ('ab' * 32) }
            }
            foreach ($k in $Troca.Keys) { if ($k -ne 'id') { $p[$k] = $Troca[$k] } }
            if (-not $Nome) { $Nome = 'win-oneshot-' + $id + '.json' }

            $caminho = Join-Path $Script:Trabalho $Nome
            [System.IO.File]::WriteAllText($caminho, (ConvertTo-Json -InputObject $p -Compress -Depth 5), [System.Text.UTF8Encoding]::new($false))

            return [PSCustomObject]@{
                Id      = $id
                Caminho = $caminho
                Hash    = (Get-FileHash -LiteralPath $caminho -Algorithm SHA256).Hash.ToLowerInvariant()
            }
        }
    }

    It 'com o hash certo, devolve o pedido e o CONSOME: uma segunda leitura falha' {
        $n = New-PedidoDeTeste

        $p = Read-PhportoPedido $n.Caminho $n.Hash
        $p.id                 | Should -Be $n.Id
        $p.acao               | Should -Be 'rdp'
        $p.params.SubAction   | Should -Be 'on'
        $p.php_pid            | Should -Be $PID
        $p.prazo_s            | Should -Be 615
        $p.manifesto          | Should -BeOfType [hashtable]
        $p.manifesto['bootstrap.ps1'] | Should -Be ('ab' * 32)

        Test-Path -LiteralPath $n.Caminho | Should -BeFalse
        { Read-PhportoPedido $n.Caminho $n.Hash } | Should -Throw -ExpectedMessage '*pedido ausente*'
    }

    It 'pedido trocado depois do clique e recusado, e fica onde estava' {
        $n = New-PedidoDeTeste
        Add-Content -LiteralPath $n.Caminho -Value ' '

        { Read-PhportoPedido $n.Caminho $n.Hash } | Should -Throw -ExpectedMessage "*nao e' o que o PHP gravou*"
        Test-Path -LiteralPath $n.Caminho | Should -BeTrue
    }

    It 'nome fora do formato e recusado antes de ler: <_>' -ForEach @('win-oneshot-ABC000000000.json', 'win-oneshot-abc.json', 'pedido.json', 'win-oneshot-abc000000000.json.txt') {
        $n = New-PedidoDeTeste -Nome $_
        { Read-PhportoPedido $n.Caminho $n.Hash } | Should -Throw -ExpectedMessage '*nome fora do formato*'
    }

    It 'pedido expirado e recusado: aceitar o prompt tarde nao roda nada' {
        $n = New-PedidoDeTeste @{ expira_em = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds() - 1 }
        { Read-PhportoPedido $n.Caminho $n.Hash } | Should -Throw -ExpectedMessage '*expirado*'
    }

    It 'id de dentro diferente do nome e recusado' {
        $n = New-PedidoDeTeste
        $outro = Join-Path $Script:Trabalho 'win-oneshot-fff000000000.json'
        Move-Item -LiteralPath $n.Caminho -Destination $outro
        { Read-PhportoPedido $outro $n.Hash } | Should -Throw -ExpectedMessage "*id que nao e' o do nome*"
    }

    It 'prazo fora de 60-3615 s e recusado: <_>' -ForEach @(59, 3616, 'x') {
        $n = New-PedidoDeTeste @{ prazo_s = $_ }
        { Read-PhportoPedido $n.Caminho $n.Hash } | Should -Throw -ExpectedMessage '*prazo fora da faixa*'
    }

    It 'php -S que ja saiu e recusado' {
        $n = New-PedidoDeTeste @{ php_pid = 2147483000 }
        { Read-PhportoPedido $n.Caminho $n.Hash } | Should -Throw -ExpectedMessage '*nao existe mais*'
    }

    It 'versao desconhecida e recusada' {
        $n = New-PedidoDeTeste @{ v = 2 }
        { Read-PhportoPedido $n.Caminho $n.Hash } | Should -Throw -ExpectedMessage '*versao desconhecida*'
    }

    It 'campo de texto vazio e recusado: <_>' -ForEach @('acao', 'raiz_win', 'dir') {
        $n = New-PedidoDeTeste @{ $_ = '' }
        { Read-PhportoPedido $n.Caminho $n.Hash } | Should -Throw -ExpectedMessage "*sem '$_'*"
    }

    It 'manifesto sem o bootstrap, ou com hash que nao e SHA-256, e recusado' {
        $n = New-PedidoDeTeste @{ manifesto = [ordered]@{ 'lib/x.ps1' = ('cd' * 32) } }
        { Read-PhportoPedido $n.Caminho $n.Hash } | Should -Throw -ExpectedMessage '*sem o bootstrap*'

        $n = New-PedidoDeTeste @{ manifesto = [ordered]@{ 'bootstrap.ps1' = 'x' } }
        { Read-PhportoPedido $n.Caminho $n.Hash } | Should -Throw -ExpectedMessage '*SHA-256 invalido*'
    }
}

Describe 'worker - dois modos de linha de comando' {

    BeforeAll {
        $ast = [System.Management.Automation.Language.Parser]::ParseFile($Script:Worker, [ref]$null, [ref]$null)
        $Script:Conjuntos = @{}
        foreach ($p in $ast.ParamBlock.Parameters) {
            $texto = $p.Attributes.Extent.Text -join ' '
            if ($texto -match "ParameterSetName = '(\w+)'") { $Script:Conjuntos[$p.Name.VariablePath.UserPath] = $Matches[1] }
        }
        $Script:Binding = ($ast.ParamBlock.Attributes | Where-Object { $_.TypeName.Name -eq 'CmdletBinding' }).Extent.Text
    }

    It 'o padrao e o worker longo' {
        $Script:Binding | Should -Match "DefaultParameterSetName\s*=\s*'Laco'"
    }

    It '<_> e do worker longo' -ForEach @('ParentPid', 'Dir', 'Nonce', 'ManifestoSha256') {
        $Script:Conjuntos[$_] | Should -Be 'Laco'
    }

    It '<_> e do uso unico' -ForEach @('Pedido', 'PedidoSha256') {
        $Script:Conjuntos[$_] | Should -Be 'UmaVez'
    }

    It 'misturar os dois modos nao resolve conjunto e nao roda nada' {
        $log = Join-Path $Script:Trabalho 'win-worker.log'
        Remove-Item -LiteralPath $log -ErrorAction SilentlyContinue

        $saida = & $Script:Exe -NoProfile -NonInteractive -File $Script:Worker -Pedido 'x.json' -PedidoSha256 'y' -ParentPid 1 -Dir $Script:Trabalho -Nonce 'n' -ManifestoSha256 'z' 2>&1
        $LASTEXITCODE | Should -Not -Be 0
        ($saida | Out-String) | Should -Match 'Parameter set|conjunto de par'
        Test-Path -LiteralPath $log | Should -BeFalse
    }

    It 'no uso unico, o modo sai antes da prova, do heartbeat e do laco' {
        $texto = Get-Content -Raw $Script:Worker
        $texto | Should -Match "(?s)IsInRole.*if \(\`$PSCmdlet\.ParameterSetName -eq 'UmaVez'\) \{\s*exit \(Invoke-UmaVez\)\s*\}.*Read-PhportoManifesto \`$F_MANIFESTO"
    }
}

Describe 'worker - a trava abre duas vezes' {

    It 'dois Initialize-PastaProtegida na mesma pasta convivem' {
        $pasta = Join-Path $Script:Trabalho ('trava-' + [guid]::NewGuid().ToString('N'))
        New-Item -ItemType Directory -Path $pasta | Out-Null
        Mock Get-PastaInfo { [PSCustomObject]@{ Existe = $true; Pasta = $true; Link = $false; Dono = 'S-1-5-32-544' } }
        Mock New-AclProtegida { 'acl' }
        Mock Set-PastaProtegidaAcl { }

        $a = Initialize-PastaProtegida $pasta 'S-1-5-21-1'
        try {
            $b = Initialize-PastaProtegida $pasta 'S-1-5-21-1'
            try {
                $a.CanRead | Should -BeTrue
                $b.CanRead | Should -BeTrue
                $a.CanWrite | Should -BeFalse -Because 'so leitura: quem segura a pasta nao precisa escrever'
            } finally { $b.Dispose() }
        } finally { $a.Dispose() }
    }

    It 'com a trava aberta, ninguem apaga o arquivo da trava' -Skip:(-not $IsWindows -and $PSVersionTable.PSVersion.Major -ge 6) {
        $pasta = Join-Path $Script:Trabalho ('trava-' + [guid]::NewGuid().ToString('N'))
        New-Item -ItemType Directory -Path $pasta | Out-Null
        Mock Get-PastaInfo { [PSCustomObject]@{ Existe = $true; Pasta = $true; Link = $false; Dono = 'S-1-5-32-544' } }
        Mock New-AclProtegida { 'acl' }
        Mock Set-PastaProtegidaAcl { }

        $a = Initialize-PastaProtegida $pasta 'S-1-5-21-1'
        try {
            { Remove-Item -LiteralPath (Join-Path $pasta 'win-trava') -Force -ErrorAction Stop } | Should -Throw
        } finally { $a.Dispose() }
    }
}

Describe 'worker - apaga as execucoes que o PHP ja importou' {

    BeforeEach {
        $Script:Prot  = Join-Path $Script:Trabalho ('importadas-' + [guid]::NewGuid().ToString('N'))
        $Script:Lista = Join-Path $Script:Prot 'win-importadas.txt'
        $Script:Pasta = Join-Path $Script:Prot 'win-protected'
        New-Item -ItemType Directory -Path $Script:Pasta -Force | Out-Null
        Mock Write-Log { }

        function New-ParDeTeste([string]$id) {
            foreach ($nome in ('win-done-' + $id + '.json'), ('win-out-' + $id + '.txt'), ('win-exec-' + $id + '.ps1')) {
                Set-Content -LiteralPath (Join-Path $Script:Pasta $nome) -Value 'x' -Encoding ASCII
            }
        }
    }

    It 'apaga win-done e win-out dos ids da lista, e mais nada' {
        New-ParDeTeste 'aaaaaaaaaaaa'
        New-ParDeTeste 'bbbbbbbbbbbb'
        Set-Content -LiteralPath $Script:Lista -Value "aaaaaaaaaaaa`r`n" -Encoding ASCII -NoNewline

        Clear-PhportoImportadas $Script:Lista $Script:Pasta | Should -Be 2

        Test-Path (Join-Path $Script:Pasta 'win-done-aaaaaaaaaaaa.json') | Should -BeFalse
        Test-Path (Join-Path $Script:Pasta 'win-out-aaaaaaaaaaaa.txt') | Should -BeFalse
        Test-Path (Join-Path $Script:Pasta 'win-exec-aaaaaaaaaaaa.ps1') | Should -BeTrue
        Test-Path (Join-Path $Script:Pasta 'win-done-bbbbbbbbbbbb.json') | Should -BeTrue
        Test-Path (Join-Path $Script:Pasta 'win-out-bbbbbbbbbbbb.txt') | Should -BeTrue
    }

    It 'a lista fica como estava: quem poda e o PHP' {
        New-ParDeTeste 'cccccccccccc'
        Set-Content -LiteralPath $Script:Lista -Value "cccccccccccc`n" -Encoding ASCII -NoNewline

        Clear-PhportoImportadas $Script:Lista $Script:Pasta | Out-Null

        Get-Content -Raw -LiteralPath $Script:Lista | Should -Be "cccccccccccc`n"
    }

    It 'linha fora do formato nao vira caminho' {
        $fora = Join-Path $Script:Prot 'win-done-fora.json'
        Set-Content -LiteralPath $fora -Value 'fica' -Encoding ASCII
        New-ParDeTeste 'dddddddddddd'
        $linhas = @('../win-done-fora', '..\fora', 'DDDDDDDDDDDD', 'dddddddddddd0', ' dddddddddddd', '*', '')
        Set-Content -LiteralPath $Script:Lista -Value ($linhas -join "`n") -Encoding ASCII

        Clear-PhportoImportadas $Script:Lista $Script:Pasta | Should -Be 0

        Test-Path $fora | Should -BeTrue
        Test-Path (Join-Path $Script:Pasta 'win-done-dddddddddddd.json') | Should -BeTrue
    }

    It 'sem lista, nao faz nada e nao quebra' {
        Clear-PhportoImportadas $Script:Lista $Script:Pasta | Should -Be 0
        Clear-PhportoImportadas '' $Script:Pasta | Should -Be 0
    }

    It 'lista acima do teto e ignorada inteira' {
        New-ParDeTeste 'eeeeeeeeeeee'
        Set-Content -LiteralPath $Script:Lista -Value ("eeeeeeeeeeee`n" * 10) -Encoding ASCII

        Clear-PhportoImportadas $Script:Lista $Script:Pasta 64 | Should -Be 0

        Test-Path (Join-Path $Script:Pasta 'win-done-eeeeeeeeeeee.json') | Should -BeTrue
    }

    It 'id repetido conta uma vez so' {
        New-ParDeTeste 'ffffffffffff'
        Set-Content -LiteralPath $Script:Lista -Value "ffffffffffff`nffffffffffff`n" -Encoding ASCII -NoNewline

        Clear-PhportoImportadas $Script:Lista $Script:Pasta | Should -Be 2
    }

    It 'o laco e o uso unico varrem antes de rodar o job' {
        $texto = Get-Content -Raw $Script:Worker
        $texto | Should -Match "Join-Path \`$Dir 'win-importadas\.txt'"
        ([regex]::Matches($texto, 'Clear-PhportoImportadas \$F_IMPORTADAS \$PROTEGIDA')).Count | Should -Be 2
    }
}

Describe 'worker - Invoke-UmaVez' {

    BeforeEach {
        $Script:IdUma  = -join ((1..12) | ForEach-Object { '0123456789abcdef'[(Get-Random -Maximum 16)] })
        $Script:Estado = Join-Path $Script:Trabalho ('win-oneshot-' + $Script:IdUma + '.estado')
        $Script:PedUma = Join-Path $Script:Trabalho ('win-oneshot-' + $Script:IdUma + '.json')
        $global:Pedido = $Script:PedUma
        $global:Dir    = $Script:Trabalho

        Mock Initialize-PastaProtegida { [System.IO.MemoryStream]::new() }
        Mock Get-UsuarioPhp { 'S-1-5-21-1' }
        Mock Write-Log { }
        $global:filho = $null
    }

    AfterEach { $global:Dir = $Script:Trabalho }

    It 'pedido recusado vira ERRO= no recado, e nada roda' {
        Mock Read-PhportoPedido { throw 'pedido expirado' }
        Mock Start-FilhoJob { throw 'nao devia rodar' }

        Invoke-UmaVez | Should -Be 1
        (Get-Content -Raw -LiteralPath $Script:Estado) | Should -Match '^ERRO=pedido expirado'
        Should -Invoke Start-FilhoJob -Times 0 -Exactly
    }

    It 'pedido de outra pasta de trabalho e recusado' {
        Mock Read-PhportoPedido { [PSCustomObject]@{ id = $Script:IdUma; acao = 'rdp'; params = [PSCustomObject]@{ SubAction = 'on' }; php_pid = $PID; raiz_win = 'C:\w'; dir = 'C:\outra'; prazo_s = 615; manifesto = @{} } }
        Mock Start-FilhoJob { throw 'nao devia rodar' }

        Invoke-UmaVez | Should -Be 1
        (Get-Content -Raw -LiteralPath $Script:Estado) | Should -Match 'outra pasta de trabalho'
    }

    It 'acao comum no pedido e recusada com 126, depois do ACEITO' {
        Mock Read-PhportoPedido { [PSCustomObject]@{ id = $Script:IdUma; acao = 'rdp'; params = [PSCustomObject]@{ SubAction = 'off' }; php_pid = $PID; raiz_win = 'C:\w'; dir = $Script:Trabalho; prazo_s = 615; manifesto = @{} } }
        Mock Write-Recusa { }

        Invoke-UmaVez | Should -Be 126
        (Get-Content -Raw -LiteralPath $Script:Estado) | Should -Match "^ACEITO=$PID"
        Should -Invoke Write-Recusa -Times 1 -Exactly -ParameterFilter { $motivo -like '*so roda acao sensivel*' }
    }

    It 'pedido aceito varre as execucoes ja importadas antes do filho' {
        Mock Read-PhportoPedido { [PSCustomObject]@{ id = $Script:IdUma; acao = 'rdp'; params = [PSCustomObject]@{ SubAction = 'off' }; php_pid = $PID; raiz_win = 'C:\w'; dir = $Script:Trabalho; prazo_s = 615; manifesto = @{} } }
        Mock Write-Recusa { }
        Mock Clear-PhportoImportadas { 0 }

        Invoke-UmaVez | Out-Null

        Should -Invoke Clear-PhportoImportadas -Times 1 -Exactly
    }

    It 'pedido recusado nao varre nada' {
        Mock Read-PhportoPedido { throw 'pedido expirado' }
        Mock Clear-PhportoImportadas { 0 }

        Invoke-UmaVez | Out-Null

        Should -Invoke Clear-PhportoImportadas -Times 0 -Exactly
    }
}
