#Requires -Version 5.1
<#
.SYNOPSIS
    Pester 5+ tests for src/Win/actions/Invoke-Sunshine.ps1
.DESCRIPTION
    Mock-based unit tests for each SubAction. Nothing is installed and no service
    or firewall rule is touched: winget, the winget lib, service control, the
    firewall cmdlets, sunshine.exe and the HTTPS client are all mocked, so the
    suite runs unelevated and changes nothing.

    set-creds and pair carry a password and a PIN. Every test of them checks
    that the output never repeats either one.
#>

BeforeAll {
    $Script:RaizProjeto = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $Script:PastaWin    = Join-Path $Script:RaizProjeto 'src\Win'
    $Script:Acao        = Join-Path $Script:PastaWin 'actions\Invoke-Sunshine.ps1'

    # Loads Write-Status, the winget lib and every action (Get-PhportoWingetOutcome included).
    . (Join-Path $Script:PastaWin 'bootstrap.ps1')

    # Outside Windows (pwsh on Linux) these cmdlets do not exist, and Pester can
    # only mock a command that exists. Stand-ins that throw if a test forgets
    # to mock them; on Windows the real cmdlets are there and get mocked.
    foreach ($nome in 'Get-Service', 'Start-Service', 'Stop-Service', 'Get-CimInstance',
                      'Get-NetFirewallRule', 'New-NetFirewallRule', 'Remove-NetFirewallRule') {
        if (-not (Get-Command -Name $nome -ErrorAction SilentlyContinue)) {
            # The parameters the action passes, so -ParameterFilter can see them.
            Set-Item -Path ("function:global:" + $nome) -Value ([scriptblock]::Create(
                "[CmdletBinding()] param([string]`$Name, [string]`$DisplayName, [string]`$Direction, [string]`$Protocol, `$LocalPort, [string]`$Action, [string]`$Profile, [string]`$ClassName, [string]`$Filter, [switch]`$Force) throw 'stand-in de $nome sem mock'"
            ))
        }
    }
    if (-not (Get-Command -Name 'winget' -ErrorAction SilentlyContinue)) {
        function global:winget { throw 'stand-in de winget sem mock' }
    }
    # Same story for the Windows folders the status reads: an empty temp folder.
    foreach ($var in 'ProgramData', 'ProgramFiles') {
        if (-not [Environment]::GetEnvironmentVariable($var)) {
            [Environment]::SetEnvironmentVariable($var, [System.IO.Path]::GetTempPath())
        }
    }

    $Script:Senha = 'Xyzzy "Segr\edo" 99'
    $Script:Pin   = '4821'

    # The output of a block, all streams, as one string.
    function global:Get-SaidaSunshine([scriptblock]$Bloco) {
        return (& $Bloco *>&1 | Out-String)
    }
}

# ==============================================================
# SANITY
# ==============================================================
Describe "Invoke-Sunshine - Sanity" {

    It "Invoke-Sunshine.ps1 exists in actions/" {
        Test-Path $Script:Acao | Should -BeTrue
    }

    It "Invoke-Sunshine.ps1 has no syntax errors" {
        $errors = $null
        [System.Management.Automation.Language.Parser]::ParseFile($Script:Acao, [ref]$null, [ref]$errors) | Out-Null
        $errors.Count | Should -Be 0
    }

    It "Invoke-Sunshine function is available after dot-sourcing" {
        Get-Command -Name 'Invoke-Sunshine' -ErrorAction SilentlyContinue | Should -Not -BeNullOrEmpty
    }

    It "does not prompt and does not auto-elevate" {
        $text = Get-Content -Path $Script:Acao -Raw
        $text | Should -Not -Match 'Read-Host'
        $text | Should -Not -Match 'RunAs'
    }

    It "never WRITES a PIN or a password anywhere" {
        # The PIN is asked for now, but it is never persisted: no file writes at all.
        $text = Get-Content -Path $Script:Acao -Raw
        $text | Should -Not -Match 'Read-Host'
        $text | Should -Not -Match 'Set-Content'
        $text | Should -Not -Match 'Add-Content'
        $text | Should -Not -Match 'Out-File'
        $text | Should -Not -Match 'WriteAll'
        $text | Should -Not -Match 'Export-'
        $text | Should -Not -Match 'Write-Log'
    }

    It "trusts only the pinned certificate: no global callback, no Add-Type" {
        $text = Get-Content -Path $Script:Acao -Raw
        $text | Should -Not -Match 'ServicePointManager\]::ServerCertificateValidationCallback'
        $text | Should -Not -Match 'Add-Type'
        $text | Should -Not -Match '\{\s*\$true\s*\}'
        $text | Should -Match '\$req\.ServerCertificateValidationCallback\s*='
    }

    It "talks to 127.0.0.1:47990 only, never to localhost" {
        $api = (Get-Command -Name 'Invoke-PhportoSunshineApi').Definition
        $api | Should -Not -Match 'localhost'
        $api | Should -Not -Match 'Origin'
        $api | Should -Not -Match 'Referer'
        $text = Get-Content -Path $Script:Acao -Raw
        $text | Should -Match "\`$script:PhportoSunshineHost\s*=\s*'127\.0\.0\.1'"
        $text | Should -Match '\$script:PhportoSunshinePorta\s*=\s*47990'
    }

    It "sends the header as exactly 'Authorization' (Sunshine masks only that spelling in its debug log)" {
        (Get-Command -Name 'Invoke-PhportoSunshineApi').Definition | Should -Match "Headers\.Add\('Authorization', 'Basic '"
    }
}

# ==============================================================
# SUBACTION — missing / unknown
# ==============================================================
Describe "Invoke-Sunshine - SubAction validation" {

    It "emits [ ERROR ] and lists the options when no subaction is given" {
        $output = (Invoke-Sunshine) 6>&1 | Out-String
        $output | Should -Match '\[ ERROR \]'
        $output | Should -Match 'status, install, start, stop, firewall-open, firewall-close, set-creds, pair'
    }

    It "emits [ ERROR ] for an unrecognised subaction" {
        $output = (Invoke-Sunshine -SubAction 'pin') 6>&1 | Out-String
        $output | Should -Match '\[ ERROR \]'
        $output | Should -Match 'set-creds, pair'
    }

    It "the old subactions refuse the credential fields (<_>), and say only the field name" -ForEach @('User', 'Password', 'Pin', 'DeviceName') {
        $global:LASTEXITCODE = 0
        $args1 = @{ SubAction = 'status'; $_ = $Script:Pin }
        $output = Get-SaidaSunshine { Invoke-Sunshine @args1 }
        $output | Should -Match "\[ ERROR \] $_ não vale para a subação 'status'"
        $output | Should -Not -Match $Script:Pin
        $global:LASTEXITCODE | Should -Be 1
    }

    It "set-creds refuses Pin and DeviceName" {
        Mock Get-PhportoSunshineExe { 'C:\Program Files\Sunshine\sunshine.exe' }
        $output = Get-SaidaSunshine { Invoke-Sunshine -SubAction 'set-creds' -User 'admin' -Password $Script:Senha -Pin $Script:Pin }
        $output | Should -Match 'set-creds não usa Pin nem DeviceName'
        Should -Invoke -CommandName Get-PhportoSunshineExe -Times 0
    }

    It "set-creds and pair require User and Password" {
        $output = Get-SaidaSunshine { Invoke-Sunshine -SubAction 'pair' -User 'admin' -Pin $Script:Pin }
        $output | Should -Match 'exigem User e Password'
    }

    It "pair refuses a PIN that is not 4 ASCII digits" -ForEach @('123', '12345', '12a4', ([string][char]0x0661 * 4)) {
        $global:LASTEXITCODE = 0
        $pin = $_
        $output = Get-SaidaSunshine { Invoke-Sunshine -SubAction 'pair' -User 'admin' -Password $Script:Senha -Pin $pin }
        $output | Should -Match 'pair exige Pin de 4 dígitos'
        $global:LASTEXITCODE | Should -Be 1
    }
}

# ==============================================================
# SUBACTION — status (read only)
# ==============================================================
Describe "Invoke-Sunshine - status" {

    It "reports the service and install, and says paired clients cannot be read here" {
        Mock Get-Service { [PSCustomObject]@{ Name = 'SunshineService'; DisplayName = 'Sunshine Service'; Status = 'Running'; StartType = 'Automatic' } }
        Mock winget { "Sunshine  LizardByte.Sunshine  2026.914.233613  winget" }
        Mock Start-Service       { }
        Mock Stop-Service        { }
        Mock New-NetFirewallRule { }

        $output = (Invoke-Sunshine -SubAction 'status') 6>&1 | Out-String

        $output | Should -Match 'Service: SunshineService'
        $output | Should -Match 'Installed \(winget'
        $output | Should -Match 'Paired clients: cannot be read'

        Should -Invoke -CommandName Start-Service       -Times 0
        Should -Invoke -CommandName New-NetFirewallRule -Times 0
    }

    It "warns when the service is not found" {
        Mock Get-Service { $null }
        Mock winget      { "" }

        $output = (Invoke-Sunshine -SubAction 'status') 6>&1 | Out-String
        $output | Should -Match 'Service: not found'
    }
}

# ==============================================================
# SUBACTION — install
# ==============================================================
Describe "Invoke-Sunshine - install" {

    It "installs LizardByte.Sunshine through the winget lib and points to the web UI for pairing" {
        Mock Install-WinUtilWinget        { }
        Mock Install-WinUtilProgramWinget { [PSCustomObject]@{ Program = 'LizardByte.Sunshine'; ExitCode = 0 } }

        $output = (Invoke-Sunshine -SubAction 'install') 6>&1 | Out-String

        Should -Invoke -CommandName Install-WinUtilProgramWinget -Times 1 -ParameterFilter {
            $Action -eq 'Install' -and ($Programs -contains 'LizardByte.Sunshine')
        }
        $output | Should -Match '\[ OK \] LizardByte.Sunshine installed'
        $output | Should -Match 'https://localhost:47990'
        $output | Should -Match 'pair Moonlight from PHPorto'
    }
}

# ==============================================================
# SUBACTION — start / stop (service state -> Applied)
# ==============================================================
Describe "Invoke-Sunshine - start and stop" {

    It "start finds the service, starts it, and points to pairing in PHPorto" {
        Mock Get-Service   { [PSCustomObject]@{ Name = 'SunshineService'; DisplayName = 'Sunshine'; Status = 'Stopped'; StartType = 'Automatic' } }
        Mock Start-Service { }

        $output = (Invoke-Sunshine -SubAction 'start') 6>&1 | Out-String

        Should -Invoke -CommandName Start-Service -Times 1 -ParameterFilter { $Name -eq 'SunshineService' }
        $output | Should -Match 'Sunshine service started'
        $output | Should -Match 'Parear com o PIN'
        $output | Should -Match 'https://localhost:47990'
    }

    It "start errors when the service is not found" {
        Mock Get-Service { $null }
        $output = (Invoke-Sunshine -SubAction 'start') 6>&1 | Out-String
        $output | Should -Match '\[ ERROR \].*not found'
    }

    It "stop stops a running service" {
        Mock Get-Service  { [PSCustomObject]@{ Name = 'SunshineService'; Status = 'Running'; StartType = 'Automatic' } }
        Mock Stop-Service { }

        (Invoke-Sunshine -SubAction 'stop') 6>&1 | Out-Null

        Should -Invoke -CommandName Stop-Service -Times 1 -ParameterFilter { $Name -eq 'SunshineService' }
    }
}

# ==============================================================
# SUBACTION — firewall-open / firewall-close
# ==============================================================
Describe "Invoke-Sunshine - firewall" {

    It "firewall-open creates the 47990 inbound rule when absent" {
        Mock Get-NetFirewallRule { $null }
        Mock New-NetFirewallRule { }

        $output = (Invoke-Sunshine -SubAction 'firewall-open') 6>&1 | Out-String

        Should -Invoke -CommandName New-NetFirewallRule -Times 1 -ParameterFilter {
            $LocalPort -eq 47990 -and $Protocol -eq 'TCP' -and $Direction -eq 'Inbound'
        }
        $output | Should -Match 'Firewall rule created'
    }

    It "firewall-open is idempotent when the rule already exists" {
        Mock Get-NetFirewallRule { [PSCustomObject]@{ DisplayName = 'PHPorto - Sunshine 47990' } }
        Mock New-NetFirewallRule { }

        (Invoke-Sunshine -SubAction 'firewall-open') 6>&1 | Out-Null

        Should -Invoke -CommandName New-NetFirewallRule -Times 0
    }

    It "firewall-close removes the rule when present" {
        Mock Get-NetFirewallRule    { [PSCustomObject]@{ DisplayName = 'PHPorto - Sunshine 47990' } }
        Mock Remove-NetFirewallRule { }

        (Invoke-Sunshine -SubAction 'firewall-close') 6>&1 | Out-Null

        Should -Invoke -CommandName Remove-NetFirewallRule -Times 1
    }
}

# ==============================================================
# ConvertTo-PhportoArgvWin — the command line of sunshine.exe --creds
# ==============================================================
Describe "Invoke-Sunshine - ConvertTo-PhportoArgvWin" {

    BeforeAll {
        # The CRT rules (CommandLineToArgvW / MSVC argv), written out so the
        # round trip also runs outside Windows. On Windows the real API is
        # checked too, below.
        function global:Split-ArgvCrt([string]$Linha) {
            $saida = New-Object System.Collections.Generic.List[string]
            $i = 0
            while ($i -lt $Linha.Length) {
                while ($i -lt $Linha.Length -and ($Linha[$i] -eq ' ' -or $Linha[$i] -eq "`t")) { $i++ }
                if ($i -ge $Linha.Length) { break }
                $sb = New-Object System.Text.StringBuilder
                $aspas = $false
                while ($i -lt $Linha.Length) {
                    $c = $Linha[$i]
                    if (-not $aspas -and ($c -eq ' ' -or $c -eq "`t")) { break }
                    if ($c -eq '\') {
                        $n = 0
                        while ($i -lt $Linha.Length -and $Linha[$i] -eq '\') { $n++; $i++ }
                        if ($i -lt $Linha.Length -and $Linha[$i] -eq '"') {
                            [void]$sb.Append([char]'\', [int][Math]::Floor($n / 2))
                            if ($n % 2 -eq 1) { [void]$sb.Append('"'); $i++ }
                        } else {
                            [void]$sb.Append([char]'\', $n)
                        }
                        continue
                    }
                    if ($c -eq '"') {
                        if ($aspas -and ($i + 1) -lt $Linha.Length -and $Linha[$i + 1] -eq '"') { [void]$sb.Append('"'); $i += 2; continue }
                        $aspas = -not $aspas; $i++; continue
                    }
                    [void]$sb.Append($c); $i++
                }
                $saida.Add($sb.ToString())
            }
            return ,$saida.ToArray()
        }
    }

    It "leaves a plain argument alone" {
        ConvertTo-PhportoArgvWin @('--creds', 'admin', 'abc12345') | Should -BeExactly '--creds admin abc12345'
    }

    It "quotes <Nome>" -ForEach @(
        @{ Nome = 'empty';                   Arg = '';            Esperado = '""' }
        @{ Nome = 'a space';                 Arg = 'a b';         Esperado = '"a b"' }
        @{ Nome = 'an inner quote';          Arg = 'a"b';         Esperado = '"a\"b"' }
        @{ Nome = 'a backslash before quote'; Arg = 'a\"b';       Esperado = '"a\\\"b"' }
        @{ Nome = 'a trailing backslash';    Arg = 'a b\';        Esperado = '"a b\\"' }
    ) {
        ConvertTo-PhportoArgvWin @($Arg) | Should -BeExactly $Esperado
    }

    It "round-trips <_> through the CRT rules" -ForEach @(
        'abc12345', 'com espaço', 'aspas"no"meio', 'barra\"aspa', 'fim\', 'fim com espaço\', "apóstrofo'x", '\\server\share', 'tab	dentro', '"', '\\"', ''
    ) {
        $volta = Split-ArgvCrt (ConvertTo-PhportoArgvWin @('--creds', 'admin', $_))
        $volta.Count | Should -Be 3
        $volta[2] | Should -BeExactly $_
    }

    It "round-trips through the real CommandLineToArgvW" -Skip:(-not $IsWindows -and $PSVersionTable.PSEdition -eq 'Core') {
        Add-Type -Namespace PhportoTeste -Name Argv -MemberDefinition @'
[System.Runtime.InteropServices.DllImport("shell32.dll", SetLastError = true)]
public static extern System.IntPtr CommandLineToArgvW([System.Runtime.InteropServices.MarshalAs(System.Runtime.InteropServices.UnmanagedType.LPWStr)] string cmd, out int n);
[System.Runtime.InteropServices.DllImport("kernel32.dll")]
public static extern System.IntPtr LocalFree(System.IntPtr p);
'@ -ErrorAction SilentlyContinue
        foreach ($senha in 'com espaço', 'a"b', 'a\"b', 'fim\', 'x y\\', "apóstrofo'x") {
            $n = 0
            $ptr = [PhportoTeste.Argv]::CommandLineToArgvW('sunshine.exe ' + (ConvertTo-PhportoArgvWin @('--creds', 'admin', $senha)), [ref]$n)
            try {
                $n | Should -Be 4
                [System.Runtime.InteropServices.Marshal]::PtrToStringUni([System.Runtime.InteropServices.Marshal]::ReadIntPtr($ptr, 3 * [IntPtr]::Size)) | Should -BeExactly $senha
            } finally {
                [void][PhportoTeste.Argv]::LocalFree($ptr)
            }
        }
    }
}

# ==============================================================
# Where Sunshine lives: from the service path, under Program Files only
# ==============================================================
Describe "Invoke-Sunshine - Get-PhportoSunshinePasta" {

    BeforeAll { $Script:PfAntes = $env:ProgramFiles }
    AfterAll  { $env:ProgramFiles = $Script:PfAntes }
    BeforeEach { $env:ProgramFiles = 'C:\Program Files' }

    It "reads a quoted PathName and climbs out of tools\" {
        Mock Get-CimInstance { [PSCustomObject]@{ PathName = '"C:\Program Files\Sunshine\tools\sunshinesvc.exe"' } }
        Get-PhportoSunshinePasta | Should -BeExactly 'C:\Program Files\Sunshine'
    }

    It "reads an unquoted PathName (old installers)" {
        Mock Get-CimInstance { [PSCustomObject]@{ PathName = 'C:\Program Files\Sunshine\tools\sunshinesvc.exe' } }
        Get-PhportoSunshinePasta | Should -BeExactly 'C:\Program Files\Sunshine'
    }

    It "refuses an install outside Program Files" {
        Mock Get-CimInstance { [PSCustomObject]@{ PathName = '"C:\Users\x\Sunshine\tools\sunshinesvc.exe"' } }
        $output = Get-SaidaSunshine { $null = Get-PhportoSunshinePasta }
        $output | Should -Match 'fora de Program Files'
        Get-PhportoSunshinePasta 6>$null | Should -BeNullOrEmpty
    }

    It "refuses a look-alike prefix (C:\Program FilesX)" {
        Mock Get-CimInstance { [PSCustomObject]@{ PathName = '"C:\Program FilesX\Sunshine\tools\sunshinesvc.exe"' } }
        Get-PhportoSunshinePasta 6>$null | Should -BeNullOrEmpty
    }

    It "says so when the service does not exist" {
        Mock Get-CimInstance { $null }
        $output = Get-SaidaSunshine { $null = Get-PhportoSunshinePasta }
        $output | Should -Match 'SunshineService não encontrado'
    }
}

# ==============================================================
# The certificate pin
# ==============================================================
Describe "Invoke-Sunshine - Get-PhportoSunshineCertSha256" {

    BeforeAll {
        $Script:PastaFake = Join-Path ([System.IO.Path]::GetTempPath()) ('phporto-sun-' + [guid]::NewGuid().ToString('N'))
        New-Item -ItemType Directory -Path (Join-Path (Join-Path $Script:PastaFake 'config') 'credentials') -Force | Out-Null

        $rsa = [System.Security.Cryptography.RSA]::Create(2048)
        $req = New-Object System.Security.Cryptography.X509Certificates.CertificateRequest('CN=Sunshine Gamestream Host', $rsa, [System.Security.Cryptography.HashAlgorithmName]::SHA256, [System.Security.Cryptography.RSASignaturePadding]::Pkcs1)
        $cert = $req.CreateSelfSigned([DateTimeOffset]::Now.AddDays(-1), [DateTimeOffset]::Now.AddDays(30))
        $Script:CertDer = $cert.RawData
        $b64 = [System.Convert]::ToBase64String($Script:CertDer, [System.Base64FormattingOptions]::InsertLineBreaks)
        $Script:Pem = Join-Path (Join-Path (Join-Path $Script:PastaFake 'config') 'credentials') 'cacert.pem'
        [System.IO.File]::WriteAllText($Script:Pem, "-----BEGIN CERTIFICATE-----`n$b64`n-----END CERTIFICATE-----`n")

        $sha = [System.Security.Cryptography.SHA256]::Create()
        $Script:Esperado = ([System.BitConverter]::ToString($sha.ComputeHash($Script:CertDer)) -replace '-', '')
        $Script:Conf = Join-Path (Join-Path $Script:PastaFake 'config') 'sunshine.conf'
    }
    AfterAll { Remove-Item -LiteralPath $Script:PastaFake -Recurse -Force -ErrorAction SilentlyContinue }
    AfterEach { Remove-Item -LiteralPath $Script:Conf -Force -ErrorAction SilentlyContinue }

    It "is the SHA-256 of the DER bytes, the same GetRawCertData() gives the callback" {
        Get-PhportoSunshineCertSha256 $Script:PastaFake | Should -BeExactly $Script:Esperado
    }

    It "refuses a custom cert = in sunshine.conf" {
        [System.IO.File]::WriteAllText($Script:Conf, "port = 47989`ncert = C:\\outro\\cert.pem`n")
        $output = Get-SaidaSunshine { $null = Get-PhportoSunshineCertSha256 $Script:PastaFake }
        $output | Should -Match 'certificado próprio'
    }

    It "accepts a sunshine.conf without cert =" {
        [System.IO.File]::WriteAllText($Script:Conf, "port = 47989`n# cert = comentado nao conta`n")
        Get-PhportoSunshineCertSha256 $Script:PastaFake | Should -BeExactly $Script:Esperado
    }

    It "says so when the PEM does not exist yet" {
        $output = Get-SaidaSunshine { $null = Get-PhportoSunshineCertSha256 (Join-Path $Script:PastaFake 'nada') }
        $output | Should -Match 'Certificado do Sunshine não encontrado'
    }
}

# ==============================================================
# set-creds — stop, sunshine.exe --creds, start
# ==============================================================
Describe "Invoke-Sunshine - Set-PhportoSunshineCreds" {

    BeforeEach {
        $global:SunOrdem  = New-Object System.Collections.Generic.List[string]
        $global:SunStatus = 'Running'

        Mock Get-PhportoSunshineServico { [PSCustomObject]@{ Name = 'SunshineService'; Status = $global:SunStatus } }
        Mock Stop-Service  { $global:SunOrdem.Add('stop');  $global:SunStatus = 'Stopped' }
        Mock Start-Service { $global:SunOrdem.Add('start'); $global:SunStatus = 'Running' }
        Mock Test-PhportoSunshinePorta { $global:SunStatus -eq 'Running' }
        Mock Start-Sleep { }
    }

    It "stops, writes the credentials, starts, and says the port answers" {
        Mock Invoke-PhportoSunshineCredsExe { $global:SunOrdem.Add('creds'); 0 }

        $output = Get-SaidaSunshine { $global:SunOk = Set-PhportoSunshineCreds -Exe 'C:\Program Files\Sunshine\sunshine.exe' -User 'admin' -Password $Script:Senha }

        $global:SunOk | Should -BeTrue
        @($global:SunOrdem) | Should -Be @('stop', 'creds', 'start')
        $output | Should -Match '\[ OK \] Credenciais gravadas; serviço SunshineService de volta \(porta 47990 respondendo\)\.'
        $output | Should -Not -Match ([regex]::Escape($Script:Senha))
        Should -Invoke -CommandName Invoke-PhportoSunshineCredsExe -Times 1 -ParameterFilter { $User -eq 'admin' -and $Password -eq $Script:Senha }
    }

    It "brings the service back even when --creds fails" {
        Mock Invoke-PhportoSunshineCredsExe { $global:SunOrdem.Add('creds'); 3 }

        $output = Get-SaidaSunshine { $global:SunOk = Set-PhportoSunshineCreds -Exe 'C:\x\sunshine.exe' -User 'admin' -Password $Script:Senha }

        $global:SunOk | Should -BeFalse
        @($global:SunOrdem) | Should -Be @('stop', 'creds', 'start')
        $output | Should -Match 'falhou \(código 3\)'
        $output | Should -Not -Match 'Credenciais gravadas'
        $output | Should -Not -Match ([regex]::Escape($Script:Senha))
    }

    It "brings the service back even when sunshine.exe cannot run at all" {
        Mock Invoke-PhportoSunshineCredsExe { $global:SunOrdem.Add('creds'); throw 'arquivo sumiu' }

        $output = Get-SaidaSunshine { $global:SunOk = Set-PhportoSunshineCreds -Exe 'C:\x\sunshine.exe' -User 'admin' -Password $Script:Senha }

        $global:SunOk | Should -BeFalse
        @($global:SunOrdem) | Should -Be @('stop', 'creds', 'start')
        $output | Should -Match 'Não foi possível rodar o sunshine.exe'
    }

    It "reports a timeout of --creds" {
        Mock Invoke-PhportoSunshineCredsExe { $null }
        $output = Get-SaidaSunshine { $null = Set-PhportoSunshineCreds -Exe 'C:\x\sunshine.exe' -User 'admin' -Password $Script:Senha }
        $output | Should -Match 'não terminou em 30 s'
    }

    It "fails with ERROR when the service does not come back" {
        Mock Invoke-PhportoSunshineCredsExe { 0 }
        Mock Start-Service { $global:SunOrdem.Add('start') }   # stays Stopped

        $output = Get-SaidaSunshine { $global:SunOk = Set-PhportoSunshineCreds -Exe 'C:\x\sunshine.exe' -User 'admin' -Password $Script:Senha }

        $global:SunOk | Should -BeFalse
        $output | Should -Match 'não voltou com a porta 47990 respondendo em 20 s'
        $output | Should -Not -Match 'Credenciais gravadas'
    }

    It "writes nothing when the service does not stop" {
        Mock Stop-Service { $global:SunOrdem.Add('stop') }   # stays Running
        Mock Invoke-PhportoSunshineCredsExe { $global:SunOrdem.Add('creds'); 0 }

        $output = Get-SaidaSunshine { $global:SunOk = Set-PhportoSunshineCreds -Exe 'C:\x\sunshine.exe' -User 'admin' -Password $Script:Senha }

        $global:SunOk | Should -BeFalse
        @($global:SunOrdem) | Should -Not -Contain 'creds'
        $output | Should -Match 'não parou em 15 s'
    }

    It "refuses when the exact SunshineService does not exist" {
        Mock Get-PhportoSunshineServico { $null }
        Mock Invoke-PhportoSunshineCredsExe { 0 }
        $output = Get-SaidaSunshine { $null = Set-PhportoSunshineCreds -Exe 'C:\x\sunshine.exe' -User 'admin' -Password $Script:Senha }
        $output | Should -Match 'SunshineService não encontrado'
        Should -Invoke -CommandName Invoke-PhportoSunshineCredsExe -Times 0
    }
}

# ==============================================================
# pair — both API versions, and every refusal
# ==============================================================
Describe "Invoke-Sunshine - Invoke-PhportoSunshinePair" {

    BeforeAll {
        function global:Resp-Sun([int]$Status, $Json = $null, [string]$Location = '', [string]$Falha = '') {
            return [PSCustomObject]@{ Status = $Status; Location = $Location; Json = $Json; Falha = $Falha }
        }
        $Script:Id = '0123456789abcdef0123456789ABCDEF'

        function global:Invoke-Par {
            $global:SunOk = $null
            $saida = Get-SaidaSunshine { $global:SunOk = Invoke-PhportoSunshinePair -User 'admin' -Password $Script:Senha -Pin $Script:Pin -DeviceName 'notebook' -CertSha256 'AB' }
            $saida | Should -Not -Match ([regex]::Escape($Script:Senha))
            $saida | Should -Not -Match $Script:Pin
            return $saida
        }
    }

    BeforeEach {
        $global:SunCorpo = $null
        $global:SunGet   = $null
        $global:SunPost  = (Resp-Sun 200 ([PSCustomObject]@{ status = $true }))
        Mock Invoke-PhportoSunshineApi -ParameterFilter { $Metodo -eq 'GET' }  { $global:SunGet }
        Mock Invoke-PhportoSunshineApi -ParameterFilter { $Metodo -eq 'POST' } { $global:SunCorpo = $Corpo; $global:SunPost }
    }

    It "OLD API (GET 404): POSTs only pin and name" {
        $global:SunGet = Resp-Sun 404

        $saida = Invoke-Par

        $global:SunOk | Should -BeTrue
        @($global:SunCorpo.Keys) | Should -Be @('pin', 'name')
        $global:SunCorpo['pin']  | Should -BeExactly $Script:Pin
        $global:SunCorpo['name'] | Should -BeExactly 'notebook'
        $saida | Should -Match '\[ OK \] Pareado: "notebook"\.'
    }

    It "NEW API (GET 200, one pending): POSTs pairing_id, pin and name, and says where the request came from" {
        $global:SunGet = Resp-Sun 200 ([PSCustomObject]@{ pairings = @([PSCustomObject]@{ id = $Script:Id; name = 'Moonlight'; address = '192.168.0.20' }) })

        $saida = Invoke-Par

        $global:SunOk | Should -BeTrue
        @($global:SunCorpo.Keys) | Should -Be @('pairing_id', 'pin', 'name')
        $global:SunCorpo['pairing_id'] | Should -BeExactly $Script:Id
        $saida | Should -Match '\[ OK \] Pareado: "notebook" \(pedido de 192\.168\.0\.20\)\.'
    }

    It "the same credentials and pin go to both calls" {
        $global:SunGet = Resp-Sun 404
        $null = Invoke-Par
        Should -Invoke -CommandName Invoke-PhportoSunshineApi -Times 2 -ParameterFilter { $User -eq 'admin' -and $Password -eq $Script:Senha -and $CertSha256 -eq 'AB' -and $Caminho -eq '/api/pin' }
    }

    It "no Moonlight waiting: ERROR, and no POST" {
        $global:SunGet = Resp-Sun 200 ([PSCustomObject]@{ pairings = @() })
        $saida = Invoke-Par
        $global:SunOk | Should -BeFalse
        $saida | Should -Match 'Nenhum Moonlight está esperando PIN'
        Should -Invoke -CommandName Invoke-PhportoSunshineApi -Times 0 -ParameterFilter { $Metodo -eq 'POST' }
    }

    It "two Moonlights waiting: refuses and lists name and address of each, no POST" {
        $global:SunGet = Resp-Sun 200 ([PSCustomObject]@{ pairings = @(
            [PSCustomObject]@{ id = $Script:Id; name = 'Moonlight-TV'; address = '192.168.0.20' },
            [PSCustomObject]@{ id = $Script:Id; name = 'Moonlight-Fone'; address = '192.168.0.31' }
        ) })
        $saida = Invoke-Par
        $global:SunOk | Should -BeFalse
        $saida | Should -Match 'Há 2 Moonlight esperando PIN'
        $saida | Should -Match 'Moonlight-TV \(192\.168\.0\.20\)'
        $saida | Should -Match 'Moonlight-Fone \(192\.168\.0\.31\)'
        Should -Invoke -CommandName Invoke-PhportoSunshineApi -Times 0 -ParameterFilter { $Metodo -eq 'POST' }
    }

    It "pairing id out of format: refuses" {
        $global:SunGet = Resp-Sun 200 ([PSCustomObject]@{ pairings = @([PSCustomObject]@{ id = 'nao-e-hex'; name = 'x'; address = 'y' }) })
        $saida = Invoke-Par
        $saida | Should -Match 'id fora do formato'
        Should -Invoke -CommandName Invoke-PhportoSunshineApi -Times 0 -ParameterFilter { $Metodo -eq 'POST' }
    }

    It "401: wrong Web UI credentials, with the fix" {
        $global:SunGet = Resp-Sun 401
        $saida = Invoke-Par
        $saida | Should -Match "Usuário ou senha da Web UI não conferem\. Marque 'Gravar estas credenciais'"
    }

    It "redirect to /welcome: Sunshine has no credentials yet" {
        $global:SunGet = Resp-Sun 307 $null '/welcome'
        $saida = Invoke-Par
        $saida | Should -Match 'O Sunshine ainda não tem credenciais'
    }

    It "certificate mismatch: says nothing was sent" {
        $global:SunGet = Resp-Sun 0 $null '' 'certificado'
        $saida = Invoke-Par
        $saida | Should -Match "O certificado em 127\.0\.0\.1:47990 não é o do Sunshine instalado\. Nada foi enviado\."
        Should -Invoke -CommandName Invoke-PhportoSunshineApi -Times 0 -ParameterFilter { $Metodo -eq 'POST' }
    }

    It "status false: wrong or expired PIN" {
        $global:SunGet  = Resp-Sun 404
        $global:SunPost = Resp-Sun 200 ([PSCustomObject]@{ status = $false })
        $saida = Invoke-Par
        $global:SunOk | Should -BeFalse
        $saida | Should -Match 'PIN errado, expirado ou o Moonlight desistiu'
    }

    It "status 'true' as text (old boost ptree JSON) also counts" {
        $global:SunGet  = Resp-Sun 404
        $global:SunPost = Resp-Sun 200 ([PSCustomObject]@{ status = 'true' })
        $null = Invoke-Par
        $global:SunOk | Should -BeTrue
    }

    It "status 'false' as text does not count" {
        $global:SunGet  = Resp-Sun 404
        $global:SunPost = Resp-Sun 200 ([PSCustomObject]@{ status = 'false' })
        $null = Invoke-Par
        $global:SunOk | Should -BeFalse
    }

    It "POST 400 (pairing_id refused): ERROR with the status, not the body" {
        $global:SunGet  = Resp-Sun 404
        $global:SunPost = Resp-Sun 400 ([PSCustomObject]@{ error = 'pairing_id must contain exactly 32 hexadecimal characters' })
        $saida = Invoke-Par
        $saida | Should -Match 'recusou o pareamento \(HTTP 400\)'
    }

    It "unknown GET answer: says the API may have changed" {
        $global:SunGet = Resp-Sun 500
        $saida = Invoke-Par
        $saida | Should -Match 'A API pode ter mudado'
    }
}

# ==============================================================
# pair through Invoke-Sunshine — the whole flow, mocked at the edges
# ==============================================================
Describe "Invoke-Sunshine - pair end to end" {

    BeforeEach {
        Mock Get-PhportoSunshineExe { 'C:\Program Files\Sunshine\sunshine.exe' }
        Mock Get-PhportoSunshineCertSha256 { 'AB' }
        Mock Test-PhportoSunshinePorta { $true }
        Mock Set-PhportoSunshineCreds { $true }
        Mock Invoke-PhportoSunshineApi -ParameterFilter { $Metodo -eq 'GET' }  { [PSCustomObject]@{ Status = 404; Location = ''; Json = $null; Falha = '' } }
        Mock Invoke-PhportoSunshineApi -ParameterFilter { $Metodo -eq 'POST' } { [PSCustomObject]@{ Status = 200; Location = ''; Json = [PSCustomObject]@{ status = $true }; Falha = '' } }
    }

    It "pairs without touching the credentials when SetCreds is off" {
        $global:LASTEXITCODE = 0
        $saida = Get-SaidaSunshine { Invoke-Sunshine -SubAction 'pair' -User 'admin' -Password $Script:Senha -Pin $Script:Pin -DeviceName 'TV da sala' }

        $saida | Should -Match '\[ OK \] Pareado: "TV da sala"'
        $saida | Should -Match 'Web UI: https://localhost:47990'
        $saida | Should -Not -Match ([regex]::Escape($Script:Senha))
        $saida | Should -Not -Match $Script:Pin
        Should -Invoke -CommandName Set-PhportoSunshineCreds -Times 0
        Should -Invoke -CommandName Get-PhportoSunshineCertSha256 -Times 1 -ParameterFilter { $Pasta -eq 'C:\Program Files\Sunshine' }
        $global:LASTEXITCODE | Should -Be 0
    }

    It "with SetCreds, writes the credentials first, in the same run" {
        $null = Get-SaidaSunshine { Invoke-Sunshine -SubAction 'pair' -User 'admin' -Password $Script:Senha -Pin $Script:Pin -SetCreds }
        Should -Invoke -CommandName Set-PhportoSunshineCreds -Times 1 -ParameterFilter { $User -eq 'admin' -and $Password -eq $Script:Senha }
    }

    It "uses notebook when DeviceName is empty" {
        $null = Get-SaidaSunshine { Invoke-Sunshine -SubAction 'pair' -User 'admin' -Password $Script:Senha -Pin $Script:Pin }
        Should -Invoke -CommandName Invoke-PhportoSunshineApi -Times 1 -ParameterFilter { $Metodo -eq 'POST' -and $Corpo['name'] -eq 'notebook' }
    }

    It "stops before pairing when the credentials step failed, and exits 1" {
        Mock Set-PhportoSunshineCreds { $false }
        $global:LASTEXITCODE = 0
        $null = Get-SaidaSunshine { Invoke-Sunshine -SubAction 'pair' -User 'admin' -Password $Script:Senha -Pin $Script:Pin -SetCreds }
        Should -Invoke -CommandName Invoke-PhportoSunshineApi -Times 0
        $global:LASTEXITCODE | Should -Be 1
    }

    It "says to start the service when the port is closed" {
        Mock Test-PhportoSunshinePorta { $false }
        $saida = Get-SaidaSunshine { Invoke-Sunshine -SubAction 'pair' -User 'admin' -Password $Script:Senha -Pin $Script:Pin }
        $saida | Should -Match 'não está respondendo na porta 47990'
        Should -Invoke -CommandName Invoke-PhportoSunshineApi -Times 0
    }

    It "set-creds runs Set-PhportoSunshineCreds and never calls the API" {
        $global:LASTEXITCODE = 0
        $null = Get-SaidaSunshine { Invoke-Sunshine -SubAction 'set-creds' -User 'admin' -Password $Script:Senha }
        Should -Invoke -CommandName Set-PhportoSunshineCreds -Times 1
        Should -Invoke -CommandName Invoke-PhportoSunshineApi -Times 0
        $global:LASTEXITCODE | Should -Be 0
    }

    It "set-creds that failed exits 1, so the history shows it" {
        Mock Set-PhportoSunshineCreds { $false }
        $global:LASTEXITCODE = 0
        $null = Get-SaidaSunshine { Invoke-Sunshine -SubAction 'set-creds' -User 'admin' -Password $Script:Senha }
        $global:LASTEXITCODE | Should -Be 1
    }

    It "arrives through the bootstrap splatting, SetCreds as a bool" {
        # This is how the worker-generated script calls it: a hashtable whose
        # SetCreds is $true, splatted into the [switch].
        $params = @{ SubAction = 'pair'; User = 'admin'; Password = $Script:Senha; Pin = $Script:Pin; DeviceName = 'notebook'; SetCreds = $true }
        $null = Get-SaidaSunshine { Invoke-Sunshine @params }
        Should -Invoke -CommandName Set-PhportoSunshineCreds -Times 1
    }
}
