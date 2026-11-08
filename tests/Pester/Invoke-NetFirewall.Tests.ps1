#Requires -Version 5.1

BeforeAll {
    $Script:RaizProjeto = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $Script:PastaWin    = Join-Path $Script:RaizProjeto 'src\Win'
    $Script:Acao        = Join-Path $Script:PastaWin 'actions\Invoke-NetFirewall.ps1'

    . (Join-Path $Script:PastaWin 'bootstrap.ps1')

    $Script:Criadas = @()
    foreach ($nome in 'Get-NetFirewallRule', 'New-NetFirewallRule', 'Remove-NetFirewallRule') {
        $Script:Criadas += $nome
        Set-Item -Path ("function:global:" + $nome) -Value ([scriptblock]::Create(
            "[CmdletBinding()] param([Parameter(ValueFromPipeline)]`$InputObject, [string]`$Group, [string]`$DisplayName, [string]`$Direction, [string]`$Action, [string]`$Protocol, [string]`$Profile, [string]`$LocalAddress, `$LocalPort, `$RemoteAddress, [string]`$Program) process { } "
        ))
    }

    $Script:PastaPhp = Join-Path ([IO.Path]::GetTempPath()) ('phporto-fw-' + [Guid]::NewGuid().ToString('N'))
    New-Item -ItemType Directory -Path $Script:PastaPhp | Out-Null
    $Script:PhpExe = Join-Path $Script:PastaPhp 'php.exe'
    Set-Content -Path $Script:PhpExe -Value 'x'
}

AfterAll {
    foreach ($nome in $Script:Criadas) { Remove-Item -Path ('Function:\' + $nome) -Force -ErrorAction SilentlyContinue }
    Remove-Item -Recurse -Force $Script:PastaPhp -ErrorAction SilentlyContinue
}

Describe "Invoke-NetFirewall - Sanity" {

    It "Invoke-NetFirewall.ps1 has no syntax errors" {
        $errors = $null
        [System.Management.Automation.Language.Parser]::ParseFile($Script:Acao, [ref]$null, [ref]$errors) | Out-Null
        $errors.Count | Should -Be 0
    }

    It "does not prompt and does not auto-elevate" {
        $text = Get-Content -Path $Script:Acao -Raw
        $text | Should -Not -Match 'Read-Host'
        $text | Should -Not -Match 'RunAs'
    }

    It "never opens a profile other than Private, nor any address" {
        $text = Get-Content -Path $Script:Acao -Raw
        $text | Should -Match '-Profile Private'
        $text | Should -Not -Match '-Profile (Any|Public|Domain)'
        $text | Should -Not -Match "RemoteAddress\s+'?(Any|\*)"
    }
}

Describe "Invoke-NetFirewall - SubAction validation" {

    It "emits [ ERROR ] and lists the options when no subaction is given" {
        $output = (Invoke-NetFirewall) 6>&1 | Out-String
        $output | Should -Match '\[ ERROR \]'
        $output | Should -Match 'add, remove'
    }

    It "emits [ ERROR ] for an unrecognised subaction" {
        $output = (Invoke-NetFirewall -SubAction 'open') 6>&1 | Out-String
        $output | Should -Match '\[ ERROR \]'
    }
}

Describe "Invoke-NetFirewall - add" {

    BeforeEach {
        Mock Get-NetFirewallRule    { }
        Mock Remove-NetFirewallRule { }
        Mock New-NetFirewallRule    { }
    }

    It "creates the rule only for Private, the bind address, the port, the allowlist and the php.exe" {
        $output = (Invoke-NetFirewall -SubAction add -BindIp '192.168.1.20' -Port 4001 -Allow '192.168.1.0/24,10.0.0.8' -Program $Script:PhpExe) 6>&1 | Out-String

        $output | Should -Match '\[ OK \]'
        Should -Invoke -CommandName New-NetFirewallRule -Times 1 -Exactly -ParameterFilter {
            $Profile -eq 'Private' -and $LocalAddress -eq '192.168.1.20' -and $LocalPort -eq 4001 -and
            $Direction -eq 'Inbound' -and $Action -eq 'Allow' -and $Protocol -eq 'TCP' -and
            $Program -eq $Script:PhpExe -and $DisplayName -eq 'PHPorto Rede' -and
            (($RemoteAddress -join ',') -eq '192.168.1.0/24,10.0.0.8')
        }
    }

    It "replaces a rule that already exists, so the rule never doubles" {
        Mock Get-NetFirewallRule { [PSCustomObject]@{ Name = 'x' } }
        (Invoke-NetFirewall -SubAction add -BindIp '192.168.1.20' -Port 4001 -Allow '192.168.1.0/24' -Program $Script:PhpExe) 6>&1 | Out-Null
        Should -Invoke -CommandName Remove-NetFirewallRule -Times 1 -Exactly
        Should -Invoke -CommandName New-NetFirewallRule    -Times 1 -Exactly
    }

    It "refuses (<Caso>) without creating anything" -ForEach @(
        @{ Caso = 'public bind';   Bind = '8.8.8.8';      Porta = 4001;  Allow = '192.168.1.0/24'; Php = 'ok' }
        @{ Caso = 'loopback bind'; Bind = '127.0.0.1';    Porta = 4001;  Allow = '192.168.1.0/24'; Php = 'ok' }
        @{ Caso = 'any bind';      Bind = '0.0.0.0';      Porta = 4001;  Allow = '192.168.1.0/24'; Php = 'ok' }
        @{ Caso = 'port zero';     Bind = '192.168.1.20'; Porta = 0;     Allow = '192.168.1.0/24'; Php = 'ok' }
        @{ Caso = 'port high';     Bind = '192.168.1.20'; Porta = 70000; Allow = '192.168.1.0/24'; Php = 'ok' }
        @{ Caso = 'empty allow';   Bind = '192.168.1.20'; Porta = 4001;  Allow = '';               Php = 'ok' }
        @{ Caso = 'not php.exe';   Bind = '192.168.1.20'; Porta = 4001;  Allow = '192.168.1.0/24'; Php = 'notepad' }
    ) {
        $programa = if ($Php -eq 'ok') { $Script:PhpExe } else { Join-Path $Script:PastaPhp 'notepad.exe' }
        $output = (Invoke-NetFirewall -SubAction add -BindIp $Bind -Port $Porta -Allow $Allow -Program $programa) 6>&1 | Out-String
        $output | Should -Match '\[ ERROR \]'
        Should -Invoke -CommandName New-NetFirewallRule -Times 0 -Exactly
    }

    It "reports the failure when the cmdlet throws" {
        Mock New-NetFirewallRule { throw 'sem permissao' }
        $output = (Invoke-NetFirewall -SubAction add -BindIp '192.168.1.20' -Port 4001 -Allow '192.168.1.0/24' -Program $Script:PhpExe) 6>&1 | Out-String
        $output | Should -Match '\[ ERROR \]'
        $output | Should -Not -Match '\[ OK \]'
    }
}

Describe "Invoke-NetFirewall - allowlist validated on the elevated side" {

    BeforeEach {
        Mock Get-NetFirewallRule    { [PSCustomObject]@{ Name = 'antiga' } }
        Mock Remove-NetFirewallRule { }
        Mock New-NetFirewallRule    { }
    }

    It "accepts <Caso>" -ForEach @(
        @{ Caso = 'a private IP';              Allow = '192.168.1.20' }
        @{ Caso = 'a private CIDR';            Allow = '192.168.1.0/24' }
        @{ Caso = '10/8';                      Allow = '10.0.0.0/8' }
        @{ Caso = '172.16/12';                 Allow = '172.16.0.0/12' }
        @{ Caso = '192.168/16';                Allow = '192.168.0.0/16' }
        @{ Caso = '172.31 single host';        Allow = '172.31.255.255' }
        @{ Caso = 'a CIDR with unaligned base'; Allow = '192.168.1.17/24' }
        @{ Caso = 'a mix of valid entries';    Allow = '192.168.1.0/24, 10.0.0.8,172.16.5.0/24' }
    ) {
        $output = (Invoke-NetFirewall -SubAction add -BindIp '192.168.1.20' -Port 4001 -Allow $Allow -Program $Script:PhpExe) 6>&1 | Out-String
        $output | Should -Match '\[ OK \]'
        Should -Invoke -CommandName New-NetFirewallRule -Times 1 -Exactly
    }

    It "refuses <Caso> and keeps the existing rule" -ForEach @(
        @{ Caso = 'a public IP';                 Allow = '8.8.8.8' }
        @{ Caso = '0.0.0.0/0';                   Allow = '0.0.0.0/0' }
        @{ Caso = 'a CIDR that escapes 172.16/12'; Allow = '172.16.0.0/11' }
        @{ Caso = 'a CIDR that escapes 10/8';    Allow = '10.0.0.0/7' }
        @{ Caso = 'a CIDR that escapes 192.168'; Allow = '192.168.0.0/15' }
        @{ Caso = 'a prefix below /8';           Allow = '10.0.0.0/7' }
        @{ Caso = 'a /0 prefix';                 Allow = '192.168.1.0/0' }
        @{ Caso = 'a prefix above /32';          Allow = '192.168.1.0/33' }
        @{ Caso = 'an octet above 255';          Allow = '192.168.1.256' }
        @{ Caso = 'garbage';                     Allow = 'qualquer coisa' }
        @{ Caso = 'a word';                      Allow = 'Any' }
        @{ Caso = 'a dotted mask';               Allow = '192.168.1.0/255.255.255.0' }
        @{ Caso = 'an IPv6 address';             Allow = 'fe80::1' }
        @{ Caso = 'an empty list';               Allow = '' }
        @{ Caso = 'a blank list';                Allow = '   ' }
        @{ Caso = 'a trailing comma';            Allow = '192.168.1.0/24,' }
        @{ Caso = 'a leading comma';             Allow = ',192.168.1.0/24' }
        @{ Caso = 'a doubled comma';             Allow = '192.168.1.0/24,,10.0.0.8' }
    ) {
        $output = (Invoke-NetFirewall -SubAction add -BindIp '192.168.1.20' -Port 4001 -Allow $Allow -Program $Script:PhpExe) 6>&1 | Out-String
        $output | Should -Match '\[ ERROR \]'
        $output | Should -Not -Match '\[ OK \]'
        Should -Invoke -CommandName Remove-NetFirewallRule -Times 0 -Exactly
        Should -Invoke -CommandName New-NetFirewallRule    -Times 0 -Exactly
    }

    It "one invalid entry among valid ones refuses everything and preserves the existing rule" {
        $output = (Invoke-NetFirewall -SubAction add -BindIp '192.168.1.20' -Port 4001 -Allow '192.168.1.0/24,8.8.8.8,10.0.0.8' -Program $Script:PhpExe) 6>&1 | Out-String
        $output | Should -Match '\[ ERROR \]'
        $output | Should -Match '8\.8\.8\.8'
        Should -Invoke -CommandName Remove-NetFirewallRule -Times 0 -Exactly
        Should -Invoke -CommandName New-NetFirewallRule    -Times 0 -Exactly
    }

    It "refuses more than 16 entries and keeps the existing rule" {
        $muitas = (1..17 | ForEach-Object { "10.0.0.$_" }) -join ','
        $output = (Invoke-NetFirewall -SubAction add -BindIp '192.168.1.20' -Port 4001 -Allow $muitas -Program $Script:PhpExe) 6>&1 | Out-String
        $output | Should -Match '\[ ERROR \]'
        Should -Invoke -CommandName Remove-NetFirewallRule -Times 0 -Exactly

        $dezesseis = (1..16 | ForEach-Object { "10.0.0.$_" }) -join ','
        $ok = (Invoke-NetFirewall -SubAction add -BindIp '192.168.1.20' -Port 4001 -Allow $dezesseis -Program $Script:PhpExe) 6>&1 | Out-String
        $ok | Should -Match '\[ OK \]'
    }
}
Describe "Invoke-NetFirewall - remove" {

    It "removes the rule by its fixed group" {
        Mock Get-NetFirewallRule    { [PSCustomObject]@{ Name = 'x' } }
        Mock Remove-NetFirewallRule { }
        $output = (Invoke-NetFirewall -SubAction remove) 6>&1 | Out-String
        $output | Should -Match '\[ OK \]'
        Should -Invoke -CommandName Get-NetFirewallRule -Times 1 -Exactly -ParameterFilter { $Group -eq 'PHPorto Rede' }
        Should -Invoke -CommandName Remove-NetFirewallRule -Times 1 -Exactly
    }

    It "is idempotent: without the rule it is OK and removes nothing" {
        Mock Get-NetFirewallRule    { }
        Mock Remove-NetFirewallRule { }
        $output = (Invoke-NetFirewall -SubAction remove) 6>&1 | Out-String
        $output | Should -Match '\[ OK \]'
        $output | Should -Not -Match '\[ ERROR \]'
        Should -Invoke -CommandName Remove-NetFirewallRule -Times 0 -Exactly
    }
}
