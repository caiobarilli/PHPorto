#Requires -Version 5.1

BeforeAll {
    $Script:RaizProjeto = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $Script:PastaWin    = Join-Path $Script:RaizProjeto 'src\Win'
    $Script:Acao        = Join-Path $Script:PastaWin 'actions\Invoke-SshKeys.ps1'

    . (Join-Path $Script:PastaWin 'bootstrap.ps1')

    if (-not (Get-Command -Name 'Get-Service' -ErrorAction SilentlyContinue)) {
        Set-Item -Path 'function:global:Get-Service' -Value { param([string]$Name) }
    }

    $Script:Chave   = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8g notebook'
    $Script:Digital = 'SHA256:mKqU+0K8OhKmA8bBQi9Rz0Q5l7/g160hIP+rJYSTNj4'
    $Script:Outra   = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGVmZ2hpamtsbW5vcHFyc3R1dnd4eXp7fH1+f4CBgoOE'
    $Script:OutraFp = 'SHA256:1W2ewS2tqP5kZ7+6mzLn3s+beB4JAo9hmPJAzbJwIZ8'
}

Describe 'Invoke-SshKeys - Sanity' {

    It 'Invoke-SshKeys.ps1 has no syntax errors' {
        $errors = $null
        [System.Management.Automation.Language.Parser]::ParseFile($Script:Acao, [ref]$null, [ref]$errors) | Out-Null
        $errors.Count | Should -Be 0
    }

    It 'does not prompt, does not auto-elevate, does not touch sshd_config nor read the private key' {
        $text = Get-Content -Path $Script:Acao -Raw
        $text | Should -Not -Match 'Read-Host'
        $text | Should -Not -Match 'RunAs'
        $text | Should -Not -Match 'sshd_config'
        $text | Should -Not -MatchExactly 'usermod|adduser| -G '
        $text | Should -Not -Match 'Read-PhportoSshKeyFile \$caminhos\.Private'
    }

    It 'the map dispatches ssh-keys to Invoke-SshKeys' {
        $global:PhportoWinActions['ssh-keys'] | Should -Be 'Invoke-SshKeys'
    }

    It 'emits [ ERROR ] and the options without a subaction' {
        $output = (Invoke-SshKeys) 6>&1 | Out-String
        $output | Should -Match '\[ ERROR \]'
        $output | Should -Match 'status, generate, puttygen, list, authorize, remove, wsl-user'
    }
}

Describe 'Invoke-SshKeys - public key line' {

    It 'accepts a valid line and computes the SHA256 fingerprint like ssh-keygen -l' {
        Test-PhportoSshPublicKey $Script:Chave | Should -BeTrue
        Get-PhportoSshFingerprint $Script:Chave | Should -Be $Script:Digital
        Get-PhportoSshKeyKind $Script:Chave | Should -Be 'ed25519'
    }

    It 'refuses <nome>' -ForEach @(
        @{ nome = 'options in front'; linha = 'command="x" ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8g' }
        @{ nome = 'a private key'; linha = '-----BEGIN OPENSSH PRIVATE KEY-----' }
        @{ nome = 'a type that does not match the blob'; linha = 'ssh-rsa AAAAC3NzaC1lZDI1NTE5AAAAIAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8g' }
        @{ nome = 'a line break'; linha = "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8g`nssh-ed25519 AAAA" }
        @{ nome = 'a comment with spaces'; linha = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8g a b' }
        @{ nome = 'an empty line'; linha = '' }
    ) {
        Test-PhportoSshPublicKey $linha | Should -BeFalse
    }
}

Describe 'Invoke-SshKeys - with files in TestDrive' {

    BeforeEach {
        $Script:Guardado = @{}
        foreach ($v in 'USERPROFILE', 'ProgramData', 'WINDIR', 'ProgramFiles', 'COMPUTERNAME') {
            $Script:Guardado[$v] = [Environment]::GetEnvironmentVariable($v)
        }
        $raiz = Join-Path $TestDrive ([guid]::NewGuid().ToString('N'))
        New-Item -ItemType Directory -Path $raiz | Out-Null
        $env:USERPROFILE  = Join-Path $raiz 'perfil'
        $env:ProgramData  = Join-Path $raiz 'dados'
        $env:WINDIR       = Join-Path $raiz 'win'
        $env:ProgramFiles = Join-Path $raiz 'pf'
        $env:COMPUTERNAME = 'MESA 01'
        $Script:Paths = Get-PhportoSshPaths
        Mock Set-PhportoSshAdminAcl { }
    }

    AfterEach {
        foreach ($v in $Script:Guardado.Keys) {
            [Environment]::SetEnvironmentVariable($v, $Script:Guardado[$v])
        }
    }

    It 'generate does not overwrite an existing key and shows only the public one' {
        New-Item -ItemType Directory -Path (Split-Path -Parent $Script:Paths.Private) -Force | Out-Null
        Set-Content -LiteralPath $Script:Paths.Private -Value 'SEGREDO-PRIVADO'
        Set-Content -LiteralPath $Script:Paths.Public -Value $Script:Chave
        Mock Invoke-PhportoSshKeygen { @{ ExitCode = 0; Error = '' } }

        $output = (Invoke-SshKeys -SubAction 'generate') 6>&1 | Out-String

        $output | Should -Match '\[ WARNING \].*already exists'
        $output | Should -Match ([regex]::Escape($Script:Digital))
        $output | Should -Not -Match 'SEGREDO-PRIVADO'
        Should -Invoke Invoke-PhportoSshKeygen -Times 0
    }

    It 'generate calls ssh-keygen with the fixed path and a sanitized comment' {
        New-Item -ItemType Directory -Path (Split-Path -Parent $Script:Paths.Keygen) -Force | Out-Null
        Set-Content -LiteralPath $Script:Paths.Keygen -Value 'x'
        Mock Invoke-PhportoSshKeygen {
            Set-Content -LiteralPath $Path -Value 'SEGREDO-PRIVADO'
            Set-Content -LiteralPath ($Path + '.pub') -Value $Script:Chave
            @{ ExitCode = 0; Error = '' }
        }

        $output = (Invoke-SshKeys -SubAction 'generate') 6>&1 | Out-String

        Should -Invoke Invoke-PhportoSshKeygen -Times 1 -ParameterFilter { $Path -eq $Script:Paths.Private -and $Comment -eq 'mesa01' }
        $output | Should -Match 'Public key: ssh-ed25519'
        $output | Should -Not -Match 'SEGREDO-PRIVADO'
    }

    It 'authorize appends once, applies the ACL, and lists the key' {
        $output = (Invoke-SshKeys -SubAction 'authorize' -PublicKey $Script:Chave) 6>&1 | Out-String
        $output2 = (Invoke-SshKeys -SubAction 'authorize' -PublicKey $Script:Chave) 6>&1 | Out-String

        @(Get-Content -LiteralPath $Script:Paths.AdminKeys) | Should -Be @($Script:Chave)
        $output  | Should -Match ('Authorized key: ed25519 ' + [regex]::Escape($Script:Digital) + ' notebook')
        $output2 | Should -Match 'already authorized'
        Should -Invoke Set-PhportoSshAdminAcl -Times 2
    }

    It 'authorize refuses a line that is not a public key, and writes nothing' {
        $output = (Invoke-SshKeys -SubAction 'authorize' -PublicKey 'command="x" ssh-ed25519 AAAA') 6>&1 | Out-String

        $output | Should -Match '\[ ERROR \]'
        Test-Path -LiteralPath $Script:Paths.AdminKeys | Should -BeFalse
    }

    It 'authorize -UseGenerated reads only the .pub' {
        New-Item -ItemType Directory -Path (Split-Path -Parent $Script:Paths.Public) -Force | Out-Null
        Set-Content -LiteralPath $Script:Paths.Private -Value 'SEGREDO-PRIVADO'
        Set-Content -LiteralPath $Script:Paths.Public -Value $Script:Outra

        (Invoke-SshKeys -SubAction 'authorize' -UseGenerated) 6>&1 | Out-Null

        @(Get-Content -LiteralPath $Script:Paths.AdminKeys) | Should -Be @($Script:Outra)
    }

    It 'remove drops only the line with that fingerprint' {
        (Invoke-SshKeys -SubAction 'authorize' -PublicKey $Script:Chave) 6>&1 | Out-Null
        (Invoke-SshKeys -SubAction 'authorize' -PublicKey $Script:Outra) 6>&1 | Out-Null

        $output = (Invoke-SshKeys -SubAction 'remove' -Fingerprint $Script:Digital) 6>&1 | Out-String

        @(Get-Content -LiteralPath $Script:Paths.AdminKeys) | Should -Be @($Script:Outra)
        $output | Should -Match 'removed'
    }

    It 'remove of an unknown fingerprint is an error' {
        $output = (Invoke-SshKeys -SubAction 'remove' -Fingerprint $Script:OutraFp) 6>&1 | Out-String
        $output | Should -Match '\[ ERROR \].*not authorized'
    }

    It 'wsl-user sends the key on stdin and reports the user' {
        Mock Invoke-PhportoWslRoot { @{ ExitCode = 0; Output = 'PHPORTO_OK' } }

        $output = (Invoke-SshKeys -SubAction 'wsl-user' -User 'dev' -Distro 'Debian' -PublicKey $Script:Chave) 6>&1 | Out-String

        Should -Invoke Invoke-PhportoWslRoot -Times 1 -ParameterFilter { $Distro -eq 'Debian' -and $User -eq 'dev' -and $Key -eq $Script:Chave }
        $output | Should -Match ('WSL user: dev Debian ' + [regex]::Escape($Script:Digital))
    }

    It 'wsl-user reports an existing user as an error' {
        Mock Invoke-PhportoWslRoot { @{ ExitCode = 3; Output = 'PHPORTO_EXISTE' } }

        $output = (Invoke-SshKeys -SubAction 'wsl-user' -User 'dev' -Distro 'Debian' -PublicKey $Script:Chave) 6>&1 | Out-String

        $output | Should -Match '\[ ERROR \].*already exists'
    }

    It 'wsl-user refuses <nome> without calling wsl' -ForEach @(
        @{ nome = 'root'; u = 'root'; d = 'Debian' }
        @{ nome = 'an uppercase user'; u = 'Dev'; d = 'Debian' }
        @{ nome = 'a user with a shell char'; u = 'dev;id'; d = 'Debian' }
        @{ nome = 'a distro with a space'; u = 'dev'; d = 'Deb ian' }
    ) {
        Mock Invoke-PhportoWslRoot { @{ ExitCode = 0; Output = 'PHPORTO_OK' } }

        $output = (Invoke-SshKeys -SubAction 'wsl-user' -User $u -Distro $d -PublicKey $Script:Chave) 6>&1 | Out-String

        $output | Should -Match '\[ ERROR \]'
        Should -Invoke Invoke-PhportoWslRoot -Times 0
    }

    It 'puttygen installs only the fixed winget ID' {
        Mock Invoke-Install { }

        (Invoke-SshKeys -SubAction 'puttygen') 6>&1 | Out-Null

        Should -Invoke Invoke-Install -Times 1 -ParameterFilter { $Apps -eq 'PuTTY.PuTTY' }
    }
}
