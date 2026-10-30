#Requires -Version 5.1

BeforeAll {
    $Script:RaizProjeto = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $Script:PastaWin    = Join-Path $Script:RaizProjeto 'src\Win'
    $Script:Acao        = Join-Path $Script:PastaWin 'actions\Invoke-PhpUpdate.ps1'

    . (Join-Path $Script:PastaWin 'bootstrap.ps1')

    Add-Type -AssemblyName System.IO.Compression.FileSystem

    $Script:Hash = 'ab' * 32

    function New-ListaPhp {
        param([string]$Versao = '8.5.13', [string]$Hash = $Script:Hash, [string]$Arquivo = '')
        if (-not $Arquivo) { $Arquivo = "php-$Versao-nts-Win32-vs17-x64.zip" }
        $lista = [ordered]@{
            '8.4' = [ordered]@{
                version        = '8.4.20'
                'nts-vs17-x64' = @{ zip = @{ path = 'php-8.4.20-nts-Win32-vs17-x64.zip'; sha256 = ('cd' * 32); size = '33.1MB' } }
            }
            '8.5' = [ordered]@{
                version        = $Versao
                'ts-vs17-x64'  = @{ zip = @{ path = "php-$Versao-Win32-vs17-x64.zip"; sha256 = ('ef' * 32); size = '33.5MB' } }
                'nts-vs17-x86' = @{ zip = @{ path = "php-$Versao-nts-Win32-vs17-x86.zip"; sha256 = ('12' * 32); size = '30.2MB' } }
                'nts-vs17-x64' = @{ zip = @{ path = $Arquivo; sha256 = $Hash; size = '33.4MB' }; mtime = '2026-10-21T13:20:11+00:00' }
                source         = @{ path = "php-$Versao-src.zip"; sha256 = ('34' * 32); size = '25MB' }
            }
        }
        return ($lista | ConvertTo-Json -Depth 6)
    }

    function New-PastaPhp {
        param([string]$Pasta, [string]$Versao, [string]$Ini = '')
        $null = New-Item -ItemType Directory -Path (Join-Path $Pasta 'ext') -Force
        Set-Content -LiteralPath (Join-Path $Pasta 'php.exe') -Value $Versao -NoNewline
        Set-Content -LiteralPath (Join-Path $Pasta 'php8ts.dll') -Value "dll $Versao" -NoNewline
        Set-Content -LiteralPath (Join-Path $Pasta 'ext\php_curl.dll') -Value "curl $Versao" -NoNewline
        Set-Content -LiteralPath (Join-Path $Pasta 'php.ini-development') -Value "dev $Versao" -NoNewline
        if ($Ini) { Set-Content -LiteralPath (Join-Path $Pasta 'php.ini') -Value $Ini -NoNewline }
    }
}

Describe 'Invoke-PhpUpdate - Sanity' {

    It 'Invoke-PhpUpdate.ps1 has no syntax errors' {
        $errors = $null
        [System.Management.Automation.Language.Parser]::ParseFile($Script:Acao, [ref]$null, [ref]$errors) | Out-Null
        $errors.Count | Should -Be 0
    }

    It 'does not prompt, does not elevate, and never touches PATH or ACLs' {
        $text = Get-Content -Path $Script:Acao -Raw
        $text | Should -Not -Match 'Read-Host'
        $text | Should -Not -Match 'RunAs'
        $text | Should -Not -Match 'Start-Process'
        $text | Should -Not -Match 'Set-Acl'
        $text | Should -Not -Match 'icacls'
        $text | Should -Not -Match 'SetEnvironmentVariable'
        $text | Should -Not -Match 'Env:Path'
    }

    It 'never follows redirects' {
        $text = Get-Content -Path $Script:Acao -Raw
        $text | Should -Match 'AllowAutoRedirect = \$false'
        $text | Should -Not -Match 'Invoke-WebRequest'
        $text | Should -Not -Match 'Invoke-RestMethod'
    }

    It 'the map dispatches php-update to Invoke-PhpUpdate' {
        $global:PhportoWinActions['php-update'] | Should -Be 'Invoke-PhpUpdate'
    }
}

Describe 'Invoke-PhpUpdate - official URL' {

    It 'accepts <Url>' -ForEach @(
        @{ Url = 'https://downloads.php.net/~windows/releases/releases.json' }
        @{ Url = 'https://downloads.php.net/~windows/releases/php-8.5.13-nts-Win32-vs17-x64.zip' }
        @{ Url = 'https://downloads.php.net/~windows/releases/php-8.3.9-nts-Win32-vs16-x64.zip' }
    ) {
        Test-PhportoPhpUrl $Url | Should -BeTrue
    }

    It 'refuses <Url>' -ForEach @(
        @{ Url = 'http://downloads.php.net/~windows/releases/releases.json' }
        @{ Url = 'https://windows.php.net/downloads/releases/releases.json' }
        @{ Url = 'https://downloads.php.net:8443/~windows/releases/releases.json' }
        @{ Url = 'https://user@downloads.php.net/~windows/releases/releases.json' }
        @{ Url = 'https://downloads.php.net/~windows/releases/releases.json?x=1' }
        @{ Url = 'https://downloads.php.net/~windows/releases/releases.json#x' }
        @{ Url = 'https://downloads.php.net/~windows/releases/php-8.5.13-Win32-vs17-x64.zip' }
        @{ Url = 'https://downloads.php.net/~windows/releases/php-8.5.13-nts-Win32-vs17-x86.zip' }
        @{ Url = 'https://downloads.php.net/~windows/releases/archives/php-8.5.11-nts-Win32-vs17-x64.zip' }
        @{ Url = 'https://downloads.php.net/~windows/releases/../evil.zip' }
        @{ Url = 'https://downloads.php.net.evil.example/~windows/releases/releases.json' }
        @{ Url = '' }
    ) {
        Test-PhportoPhpUrl $Url | Should -BeFalse
    }
}

Describe 'Invoke-PhpUpdate - official list' {

    It 'takes the version, the NTS x64 zip and its hash from the line' {
        $r = Select-PhportoPhpRelease -Json (New-ListaPhp) -Minor '8.5'
        $r.Version | Should -Be '8.5.13'
        $r.Sha256  | Should -Be $Script:Hash
        $r.File    | Should -Be 'php-8.5.13-nts-Win32-vs17-x64.zip'
        $r.Url     | Should -Be 'https://downloads.php.net/~windows/releases/php-8.5.13-nts-Win32-vs17-x64.zip'
    }

    It 'lowers an uppercase hash' {
        $r = Select-PhportoPhpRelease -Json (New-ListaPhp -Hash ('AB' * 32)) -Minor '8.5'
        $r.Sha256 | Should -Be $Script:Hash
    }

    It 'refuses a zip whose name does not match the version' {
        Select-PhportoPhpRelease -Json (New-ListaPhp -Arquivo 'php-8.5.12-nts-Win32-vs17-x64.zip') -Minor '8.5' | Should -BeNullOrEmpty
    }

    It 'refuses a hash that is not 64 hex' {
        Select-PhportoPhpRelease -Json (New-ListaPhp -Hash 'zz') -Minor '8.5' | Should -BeNullOrEmpty
    }

    It 'returns nothing for a missing line or broken JSON' {
        Select-PhportoPhpRelease -Json (New-ListaPhp) -Minor '9.0' | Should -BeNullOrEmpty
        Select-PhportoPhpRelease -Json '{' -Minor '8.5' | Should -BeNullOrEmpty
    }
}

Describe 'Invoke-PhpUpdate - php -v' {

    It 'reads the version, NTS and x64' {
        Mock Invoke-PhportoPhpExe { @{ ExitCode = 0; Output = "PHP 8.5.12 (cli) (built: Oct 21 2026 13:20:11) (NTS Visual C++ 2022 x64)`nCopyright (c) The PHP Group" } }
        $info = Get-PhportoPhpExeInfo 'C:\php\php.exe'
        $info.Version | Should -Be '8.5.12'
        $info.Nts     | Should -BeTrue
        $info.X64     | Should -BeTrue
    }

    It 'sees a ZTS build as not NTS' {
        Mock Invoke-PhportoPhpExe { @{ ExitCode = 0; Output = 'PHP 8.5.12 (cli) (built: Oct 21 2026 13:20:11) (ZTS Visual C++ 2022 x64)' } }
        (Get-PhportoPhpExeInfo 'C:\php\php.exe').Nts | Should -BeFalse
    }

    It 'returns nothing when php -v fails' {
        Mock Invoke-PhportoPhpExe { @{ ExitCode = 255; Output = '' } }
        Get-PhportoPhpExeInfo 'C:\php\php.exe' | Should -BeNullOrEmpty
    }
}

Describe 'Invoke-PhpUpdate - backup name' {

    It 'uses the sibling with the old version, then -2, and never an existing one' {
        $pasta = Join-Path $TestDrive 'php'
        Get-PhportoPhpBackupPath -PhpDir $pasta -OldVersion '8.5.11' | Should -Be "$pasta-8.5.11"

        $null = New-Item -ItemType Directory -Path "$pasta-8.5.11"
        Get-PhportoPhpBackupPath -PhpDir $pasta -OldVersion '8.5.11' | Should -Be "$pasta-8.5.11-2"

        2..9 | ForEach-Object { $null = New-Item -ItemType Directory -Path "$pasta-8.5.11-$_" }
        Get-PhportoPhpBackupPath -PhpDir $pasta -OldVersion '8.5.11' | Should -BeNullOrEmpty
    }
}

Describe 'Invoke-PhpUpdate - zip' {

    It 'refuses an entry that leaves the target folder' {
        $zip = Join-Path $TestDrive 'mau.zip'
        $arquivo = [System.IO.Compression.ZipFile]::Open($zip, [System.IO.Compression.ZipArchiveMode]::Create)
        try {
            $entrada = $arquivo.CreateEntry('../fora.txt')
            $escrita = New-Object System.IO.StreamWriter($entrada.Open())
            $escrita.Write('x')
            $escrita.Dispose()
        } finally {
            $arquivo.Dispose()
        }

        { Expand-PhportoPhpZip -Zip $zip -Destino (Join-Path $TestDrive 'saida') } | Should -Throw -ExpectedMessage '*outside the target folder*'
        Test-Path -LiteralPath (Join-Path $TestDrive 'fora.txt') | Should -BeFalse
    }
}

Describe 'Invoke-PhpUpdate - parameters' {

    BeforeEach {
        Mock Invoke-PhportoPhpGet { throw 'no network in tests' }
        Mock Test-PhportoPhpDir { $true }
    }

    It 'refuses <Nome> before any download' -ForEach @(
        @{ Nome = 'a version that is not x.y.z'; V = '8.5'; S = ('ab' * 32); D = 'C:\php'; M = 'Version must be x.y.z' }
        @{ Nome = 'a short hash'; V = '8.5.13'; S = 'abc'; D = 'C:\php'; M = 'Sha256 must be 64 hex' }
    ) {
        $output = (Invoke-PhpUpdate -Version $V -Sha256 $S -PhpDir $D) 6>&1 | Out-String
        $output | Should -Match ([regex]::Escape($M))
        $global:LASTEXITCODE | Should -Be 1
        Should -Invoke Invoke-PhportoPhpGet -Times 0
    }

    It 'refuses a folder outside the format' {
        Mock Test-PhportoPhpDir { $false }
        $output = (Invoke-PhpUpdate -Version '8.5.13' -Sha256 $Script:Hash -PhpDir 'C:\..\x') 6>&1 | Out-String
        $output | Should -Match '\[ ERROR \].*PhpDir is not an accepted local folder'
        $global:LASTEXITCODE | Should -Be 1
    }
}

Describe 'Invoke-PhpUpdate - folder check' {

    It 'refuses <Pasta> before looking at the disk' -ForEach @(
        @{ Pasta = 'C:\' }
        @{ Pasta = 'C:/php' }
        @{ Pasta = 'C:\php\' }
        @{ Pasta = 'C:\..\x' }
        @{ Pasta = '\\srv\php' }
        @{ Pasta = 'C:\' + ('a' * 200) }
    ) {
        Mock Test-Path { $true }
        Test-PhportoPhpDir $Pasta | Should -BeFalse
        Should -Invoke Test-Path -Times 0
    }
}

Describe 'Invoke-PhpUpdate - the swap' {

    BeforeEach {
        $Script:Pasta = Join-Path $TestDrive ('php' + [guid]::NewGuid().ToString('N').Substring(0, 6))
        $Script:Fonte = Join-Path $TestDrive ('zip' + [guid]::NewGuid().ToString('N').Substring(0, 6))

        New-PastaPhp -Pasta $Script:Pasta -Versao '8.5.11' -Ini 'memory_limit = 512M'
        New-PastaPhp -Pasta $Script:Fonte -Versao '8.5.13'
        Set-Content -LiteralPath (Join-Path $Script:Fonte 'php8phpdbg.dll') -Value 'novo' -NoNewline

        Mock Test-PhportoPhpDir { $true }
        Mock Get-PhportoPhpExeInfo {
            $v = (Get-Content -Raw -LiteralPath $Exe).Trim()
            [PSCustomObject]@{ Version = $v; Nts = $true; X64 = $true; Output = "PHP $v (cli)" }
        }
        Mock Get-PhportoPhpHash { $Script:Hash }
        Mock Invoke-PhportoPhpGet {
            if ($OutFile) {
                [System.IO.Compression.ZipFile]::CreateFromDirectory($Script:Fonte, $OutFile)
                return $OutFile
            }
            return (New-ListaPhp)
        }
    }

    It 'backs up, extracts in place, keeps php.ini and reports the backup' {
        $output = (Invoke-PhpUpdate -Version '8.5.13' -Sha256 $Script:Hash -PhpDir $Script:Pasta) 6>&1 | Out-String

        $global:LASTEXITCODE | Should -Be 0
        $output | Should -Match ([regex]::Escape("[ OK ] Backup: $($Script:Pasta)-8.5.11"))
        $output | Should -Match ([regex]::Escape('PHP 8.5.11 -> 8.5.13'))
        $output | Should -Match 'Close php -S and start it again'

        Get-Content -Raw -LiteralPath (Join-Path $Script:Pasta 'php.exe') | Should -Be '8.5.13'
        Get-Content -Raw -LiteralPath (Join-Path $Script:Pasta 'ext\php_curl.dll') | Should -Be 'curl 8.5.13'
        Get-Content -Raw -LiteralPath (Join-Path $Script:Pasta 'php8phpdbg.dll') | Should -Be 'novo'
        Get-Content -Raw -LiteralPath (Join-Path $Script:Pasta 'php.ini') | Should -Be 'memory_limit = 512M'

        Get-Content -Raw -LiteralPath (Join-Path "$($Script:Pasta)-8.5.11" 'php.exe') | Should -Be '8.5.11'
        Get-Content -Raw -LiteralPath (Join-Path "$($Script:Pasta)-8.5.11" 'php.ini') | Should -Be 'memory_limit = 512M'

        @(Get-ChildItem -LiteralPath $Script:Pasta -Recurse -Filter '*.phporto-old').Count | Should -Be 0
    }

    It 'never overwrites an existing backup' {
        $null = New-Item -ItemType Directory -Path "$($Script:Pasta)-8.5.11"
        Set-Content -LiteralPath (Join-Path "$($Script:Pasta)-8.5.11" 'marca.txt') -Value 'antigo' -NoNewline

        $output = (Invoke-PhpUpdate -Version '8.5.13' -Sha256 $Script:Hash -PhpDir $Script:Pasta) 6>&1 | Out-String

        $global:LASTEXITCODE | Should -Be 0
        $output | Should -Match ([regex]::Escape("Backup: $($Script:Pasta)-8.5.11-2"))
        @(Get-ChildItem -LiteralPath "$($Script:Pasta)-8.5.11").Count | Should -Be 1
        Get-Content -Raw -LiteralPath (Join-Path "$($Script:Pasta)-8.5.11-2" 'php.exe') | Should -Be '8.5.11'
    }

    It 'aborts on a SHA256 mismatch and changes nothing' {
        Mock Get-PhportoPhpHash { 'cd' * 32 }

        $output = (Invoke-PhpUpdate -Version '8.5.13' -Sha256 $Script:Hash -PhpDir $Script:Pasta) 6>&1 | Out-String

        $global:LASTEXITCODE | Should -Be 1
        $output | Should -Match '\[ ERROR \] SHA256 mismatch'
        $output | Should -Match 'Nothing was changed'
        Get-Content -Raw -LiteralPath (Join-Path $Script:Pasta 'php.exe') | Should -Be '8.5.11'
        Test-Path -LiteralPath "$($Script:Pasta)-8.5.11" | Should -BeFalse
    }

    It 'aborts when the official list moved to another patch' {
        Mock Invoke-PhportoPhpGet { New-ListaPhp -Versao '8.5.14' }

        $output = (Invoke-PhpUpdate -Version '8.5.13' -Sha256 $Script:Hash -PhpDir $Script:Pasta) 6>&1 | Out-String

        $global:LASTEXITCODE | Should -Be 1
        $output | Should -Match 'now has 8\.5\.14'
        Should -Invoke Invoke-PhportoPhpGet -Times 1 -Exactly
        Test-Path -LiteralPath "$($Script:Pasta)-8.5.11" | Should -BeFalse
    }

    It 'aborts when the hash in the list is not the one the page showed' {
        Mock Invoke-PhportoPhpGet { New-ListaPhp -Hash ('cd' * 32) }

        $output = (Invoke-PhpUpdate -Version '8.5.13' -Sha256 $Script:Hash -PhpDir $Script:Pasta) 6>&1 | Out-String

        $global:LASTEXITCODE | Should -Be 1
        $output | Should -Match 'Reload the PHP page'
        Should -Invoke Invoke-PhportoPhpGet -Times 1 -Exactly
    }

    It 'aborts when the list cannot be read' {
        Mock Invoke-PhportoPhpGet { throw 'HTTP 302 from x. Redirects are not followed.' }

        $output = (Invoke-PhpUpdate -Version '8.5.13' -Sha256 $Script:Hash -PhpDir $Script:Pasta) 6>&1 | Out-String

        $global:LASTEXITCODE | Should -Be 1
        $output | Should -Match 'Could not read the official list: HTTP 302'
    }

    It 'restores the previous files when the new php -v fails' {
        Mock Get-PhportoPhpExeInfo {
            $v = (Get-Content -Raw -LiteralPath $Exe).Trim()
            if ($v -eq '8.5.13') { return $null }
            [PSCustomObject]@{ Version = $v; Nts = $true; X64 = $true; Output = "PHP $v (cli)" }
        }

        $output = (Invoke-PhpUpdate -Version '8.5.13' -Sha256 $Script:Hash -PhpDir $Script:Pasta) 6>&1 | Out-String

        $global:LASTEXITCODE | Should -Be 1
        $output | Should -Match '\[ ERROR \] php\.exe -v did not report 8\.5\.13'
        $output | Should -Match 'The previous PHP is back'
        Get-Content -Raw -LiteralPath (Join-Path $Script:Pasta 'php.exe') | Should -Be '8.5.11'
        Get-Content -Raw -LiteralPath (Join-Path $Script:Pasta 'ext\php_curl.dll') | Should -Be 'curl 8.5.11'
        Test-Path -LiteralPath (Join-Path $Script:Pasta 'php8phpdbg.dll') | Should -BeFalse
        Get-Content -Raw -LiteralPath (Join-Path $Script:Pasta 'php.ini') | Should -Be 'memory_limit = 512M'
        @(Get-ChildItem -LiteralPath $Script:Pasta -Recurse -Filter '*.phporto-old').Count | Should -Be 0
        Test-Path -LiteralPath "$($Script:Pasta)-8.5.11" | Should -BeTrue
    }

    It 'restores the previous files when a copy fails halfway' {
        Mock Copy-Item -ParameterFilter { $Destination -like '*php8ts.dll' } { throw 'locked' }

        $output = (Invoke-PhpUpdate -Version '8.5.13' -Sha256 $Script:Hash -PhpDir $Script:Pasta) 6>&1 | Out-String

        $global:LASTEXITCODE | Should -Be 1
        $output | Should -Match 'Replacing the files failed: locked'
        Get-Content -Raw -LiteralPath (Join-Path $Script:Pasta 'php.exe') | Should -Be '8.5.11'
        Get-Content -Raw -LiteralPath (Join-Path $Script:Pasta 'php8ts.dll') | Should -Be 'dll 8.5.11'
        @(Get-ChildItem -LiteralPath $Script:Pasta -Recurse -Filter '*.phporto-old').Count | Should -Be 0
    }

    It 'refuses to cross to another minor' {
        Mock Get-PhportoPhpExeInfo { [PSCustomObject]@{ Version = '8.4.20'; Nts = $true; X64 = $true; Output = '' } }

        $output = (Invoke-PhpUpdate -Version '8.5.13' -Sha256 $Script:Hash -PhpDir $Script:Pasta) 6>&1 | Out-String

        $global:LASTEXITCODE | Should -Be 1
        $output | Should -Match 'stays inside 8\.5'
        Should -Invoke Invoke-PhportoPhpGet -Times 0
    }

    It 'refuses a newer or equal current version and a TS build' {
        Mock Get-PhportoPhpExeInfo { [PSCustomObject]@{ Version = '8.5.14'; Nts = $true; X64 = $true; Output = '' } }
        $output = (Invoke-PhpUpdate -Version '8.5.13' -Sha256 $Script:Hash -PhpDir $Script:Pasta) 6>&1 | Out-String
        $output | Should -Match 'not older than 8\.5\.13'

        Mock Get-PhportoPhpExeInfo { [PSCustomObject]@{ Version = '8.5.11'; Nts = $false; X64 = $true; Output = '' } }
        $output = (Invoke-PhpUpdate -Version '8.5.13' -Sha256 $Script:Hash -PhpDir $Script:Pasta) 6>&1 | Out-String -Width 4096
        $output | Should -Match 'not an NTS x64 build'

        $global:LASTEXITCODE | Should -Be 1
        Should -Invoke Invoke-PhportoPhpGet -Times 0
    }

    It 'says so when the folder is already at the target' {
        Set-Content -LiteralPath (Join-Path $Script:Pasta 'php.exe') -Value '8.5.13' -NoNewline

        $output = (Invoke-PhpUpdate -Version '8.5.13' -Sha256 $Script:Hash -PhpDir $Script:Pasta) 6>&1 | Out-String -Width 4096

        $global:LASTEXITCODE | Should -Be 0
        $output | Should -Match 'already 8\.5\.13\. Nothing was changed'
        Should -Invoke Invoke-PhportoPhpGet -Times 0
    }
}
