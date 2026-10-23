function Test-PhportoPhpUrl {
    param([string]$Url)

    if (-not $Url) { return $false }
    if ($Url -cnotmatch '\Ahttps://downloads\.php\.net/~windows/releases/(?:releases\.json|php-[0-9]{1,2}\.[0-9]{1,2}\.[0-9]{1,3}-nts-Win32-v[sc][0-9]{1,3}-x64\.zip)\z') { return $false }

    $uri = $null
    if (-not [System.Uri]::TryCreate($Url, [System.UriKind]::Absolute, [ref]$uri)) { return $false }

    return ($uri.Scheme -ceq 'https' -and $uri.Host -ceq 'downloads.php.net' -and $uri.IsDefaultPort -and -not $uri.UserInfo -and -not $uri.Query -and -not $uri.Fragment)
}

function Test-PhportoPhpDir {
    param([string]$PhpDir)

    if (-not $PhpDir -or $PhpDir.Length -gt 200) { return $false }
    if ($PhpDir -cnotmatch '\A[A-Za-z]:(?:\x5C(?!\.{1,2}(?:\x5C|\z))[^\x5C\x2F:*?"<>|\x00-\x1F]+)+\z') { return $false }
    if (-not (Test-Path -LiteralPath $PhpDir -PathType Container)) { return $false }

    $item = Get-Item -LiteralPath $PhpDir -Force
    return (-not ($item.Attributes -band [System.IO.FileAttributes]::ReparsePoint))
}

function Invoke-PhportoPhpGet {
    param([string]$Url, [string]$OutFile)

    if (-not (Test-PhportoPhpUrl $Url)) { throw "URL outside the official list: $Url" }

    [System.Net.ServicePointManager]::SecurityProtocol = [System.Net.ServicePointManager]::SecurityProtocol -bor [System.Net.SecurityProtocolType]::Tls12

    $pedido = [System.Net.HttpWebRequest]::Create($Url)
    $pedido.AllowAutoRedirect = $false
    $pedido.Timeout           = 60000
    $pedido.ReadWriteTimeout  = 300000
    $pedido.UserAgent         = 'PHPorto'

    $resposta = $null
    try {
        try {
            $resposta = $pedido.GetResponse()
        } catch [System.Net.WebException] {
            if ($null -eq $_.Exception.Response) { throw }
            $resposta = $_.Exception.Response
        }

        $codigo = [int]$resposta.StatusCode
        if ($codigo -ne 200) { throw "HTTP $codigo from $Url. Redirects are not followed." }
        if ($resposta.ResponseUri.Host -cne 'downloads.php.net') { throw "The answer came from another host: $($resposta.ResponseUri.Host)" }

        $entrada = $resposta.GetResponseStream()

        if ($OutFile) {
            $saida = [System.IO.File]::Create($OutFile)
            try { $entrada.CopyTo($saida) } finally { $saida.Dispose() }
            return $OutFile
        }

        $leitor = New-Object System.IO.StreamReader($entrada, [System.Text.Encoding]::UTF8)
        try { return $leitor.ReadToEnd() } finally { $leitor.Dispose() }
    } finally {
        if ($null -ne $resposta) { $resposta.Dispose() }
    }
}

function Select-PhportoPhpRelease {
    param([string]$Json, [string]$Minor)

    $dados = $null
    try { $dados = $Json | ConvertFrom-Json -ErrorAction Stop } catch { return $null }
    if ($null -eq $dados) { return $null }

    $prop = $dados.PSObject.Properties[$Minor]
    if ($null -eq $prop) { return $null }

    $linha  = $prop.Value
    $versao = [string]$linha.version
    if ($versao -cnotmatch '\A([0-9]{1,2})\.([0-9]{1,2})\.([0-9]{1,3})\z') { return $null }
    if (($Matches[1] + '.' + $Matches[2]) -cne $Minor) { return $null }

    $builds = @($linha.PSObject.Properties | Where-Object { $_.Name -cmatch '\Ants-v[sc][0-9]{1,3}-x64\z' } | Sort-Object -Property Name -Descending)

    foreach ($build in $builds) {
        $sufixo = $build.Name.Substring(4)
        $zip    = $build.Value.zip
        if ($null -eq $zip) { continue }

        $arquivo = [string]$zip.path
        $hash    = ([string]$zip.sha256).ToLowerInvariant()

        if ($arquivo -cne ('php-' + $versao + '-nts-Win32-' + $sufixo + '.zip')) { continue }
        if ($hash -cnotmatch '\A[0-9a-f]{64}\z') { continue }

        $url = 'https://downloads.php.net/~windows/releases/' + $arquivo
        if (-not (Test-PhportoPhpUrl $url)) { continue }

        return [PSCustomObject]@{ Version = $versao; Sha256 = $hash; File = $arquivo; Url = $url }
    }

    return $null
}

function Invoke-PhportoPhpExe {
    param([string]$Exe, [string[]]$Argumentos)

    $ErrorActionPreference = 'Continue'
    $saida = @(& $Exe @Argumentos 2>&1 | ForEach-Object { [string]$_ })
    return @{ ExitCode = $LASTEXITCODE; Output = ($saida -join "`n").Trim() }
}

function Get-PhportoPhpExeInfo {
    param([string]$Exe)

    $r = Invoke-PhportoPhpExe -Exe $Exe -Argumentos @('-v')
    if ($r.ExitCode -ne 0 -or [string]$r.Output -cnotmatch '(?m)^PHP ([0-9]{1,2}\.[0-9]{1,2}\.[0-9]{1,3}) \(cli\)(.*)$') { return $null }

    $resto = $Matches[2]
    return [PSCustomObject]@{
        Version = $Matches[1]
        Nts     = ($resto -cmatch '\(NTS\b')
        X64     = ($resto -cmatch '\bx64\)')
        Output  = [string]$r.Output
    }
}

function Get-PhportoPhpHash {
    param([string]$Path)

    return (Get-FileHash -Algorithm SHA256 -LiteralPath $Path).Hash.ToLowerInvariant()
}

function Get-PhportoPhpBackupPath {
    param([string]$PhpDir, [string]$OldVersion)

    $base = $PhpDir + '-' + $OldVersion
    $candidatos = @($base) + @(2..9 | ForEach-Object { $base + '-' + $_ })

    foreach ($c in $candidatos) {
        if (-not (Test-Path -LiteralPath $c)) { return $c }
    }

    return $null
}

function Expand-PhportoPhpZip {
    param([string]$Zip, [string]$Destino)

    Add-Type -AssemblyName System.IO.Compression.FileSystem

    $null = New-Item -ItemType Directory -Path $Destino -Force
    $raiz = [System.IO.Path]::GetFullPath($Destino).TrimEnd('\', '/') + [System.IO.Path]::DirectorySeparatorChar

    $arquivo = [System.IO.Compression.ZipFile]::OpenRead($Zip)
    try {
        foreach ($entrada in $arquivo.Entries) {
            $alvo = [System.IO.Path]::GetFullPath([System.IO.Path]::Combine($raiz, $entrada.FullName))
            if (-not $alvo.StartsWith($raiz, [System.StringComparison]::OrdinalIgnoreCase)) {
                throw "Zip entry outside the target folder: $($entrada.FullName)"
            }

            if ($entrada.FullName.EndsWith('/') -or $entrada.FullName.EndsWith('\')) {
                $null = New-Item -ItemType Directory -Path $alvo -Force
                continue
            }

            $null = New-Item -ItemType Directory -Path ([System.IO.Path]::GetDirectoryName($alvo)) -Force
            [System.IO.Compression.ZipFileExtensions]::ExtractToFile($entrada, $alvo, $true)
        }
    } finally {
        $arquivo.Dispose()
    }
}

function Copy-PhportoPhpBackup {
    param([string]$PhpDir, [string]$Backup)

    if (Test-Path -LiteralPath $Backup) { throw "$Backup already exists" }

    Copy-Item -LiteralPath $PhpDir -Destination $Backup -Recurse -ErrorAction Stop

    if (-not (Test-Path -LiteralPath (Join-Path $Backup 'php.exe') -PathType Leaf)) { throw "php.exe is missing in $Backup" }
}

function Install-PhportoPhpArquivos {
    param([string]$Origem, [string]$Destino, [System.Collections.Generic.List[object]]$Feitos)

    $raiz = [System.IO.Path]::GetFullPath($Origem).TrimEnd('\', '/')

    foreach ($arquivo in @(Get-ChildItem -LiteralPath $Origem -Recurse -File -Force)) {
        $relativo = $arquivo.FullName.Substring($raiz.Length + 1)
        $alvo     = Join-Path $Destino $relativo
        $pasta    = Split-Path -Parent $alvo

        if (-not (Test-Path -LiteralPath $pasta)) { $null = New-Item -ItemType Directory -Path $pasta -Force }

        $velho = $null
        if (Test-Path -LiteralPath $alvo) {
            $velho = $alvo + '.phporto-old'
            if (Test-Path -LiteralPath $velho) { Remove-Item -LiteralPath $velho -Force -ErrorAction Stop }
            Move-Item -LiteralPath $alvo -Destination $velho -ErrorAction Stop
        }

        $Feitos.Add([PSCustomObject]@{ Alvo = $alvo; Velho = $velho })
        Copy-Item -LiteralPath $arquivo.FullName -Destination $alvo -Force -ErrorAction Stop
    }
}

function Undo-PhportoPhpArquivos {
    param([object[]]$Feitos)

    $falhas = 0
    for ($i = $Feitos.Count - 1; $i -ge 0; $i--) {
        $feito = $Feitos[$i]
        try {
            if (Test-Path -LiteralPath $feito.Alvo) { Remove-Item -LiteralPath $feito.Alvo -Force -ErrorAction Stop }
            if ($feito.Velho) { Move-Item -LiteralPath $feito.Velho -Destination $feito.Alvo -ErrorAction Stop }
        } catch {
            $falhas++
        }
    }

    return $falhas
}

function Restore-PhportoPhpBackup {
    param([string]$Backup, [string]$PhpDir)

    $raiz   = [System.IO.Path]::GetFullPath($Backup).TrimEnd('\', '/')
    $falhas = 0

    foreach ($arquivo in @(Get-ChildItem -LiteralPath $Backup -Recurse -File -Force)) {
        $alvo  = Join-Path $PhpDir $arquivo.FullName.Substring($raiz.Length + 1)
        $pasta = Split-Path -Parent $alvo
        try {
            if (-not (Test-Path -LiteralPath $pasta)) { $null = New-Item -ItemType Directory -Path $pasta -Force -ErrorAction Stop }
            Copy-Item -LiteralPath $arquivo.FullName -Destination $alvo -Force -ErrorAction Stop
        } catch {
            $falhas++
        }
    }

    return $falhas
}

function Remove-PhportoPhpVelhos {
    param([object[]]$Feitos)

    $presos = 0
    foreach ($feito in $Feitos) {
        if (-not $feito.Velho) { continue }
        Remove-Item -LiteralPath $feito.Velho -Force -ErrorAction SilentlyContinue
        if (Test-Path -LiteralPath $feito.Velho) { $presos++ }
    }

    return $presos
}

function Invoke-PhpUpdate {
    param([string]$Version, [string]$Sha256, [string]$PhpDir)

    $global:LASTEXITCODE = 1

    if ([string]$Version -cnotmatch '\A([0-9]{1,2})\.([0-9]{1,2})\.([0-9]{1,3})\z') {
        Write-Status ERROR "Version must be x.y.z. Nothing was changed."
        return
    }
    $minor = $Matches[1] + '.' + $Matches[2]

    $Sha256 = ([string]$Sha256).ToLowerInvariant()
    if ($Sha256 -cnotmatch '\A[0-9a-f]{64}\z') {
        Write-Status ERROR "Sha256 must be 64 hex characters. Nothing was changed."
        return
    }

    if (-not (Test-PhportoPhpDir $PhpDir)) {
        Write-Status ERROR "PhpDir is not an accepted local folder: $PhpDir. Nothing was changed."
        return
    }

    $exe = Join-Path $PhpDir 'php.exe'
    if (-not (Test-Path -LiteralPath $exe -PathType Leaf)) {
        Write-Status ERROR "php.exe not found in $PhpDir. Nothing was changed."
        return
    }

    $atual = Get-PhportoPhpExeInfo $exe
    if ($null -eq $atual) {
        Write-Status ERROR "php.exe -v did not answer with a version in $PhpDir. Nothing was changed."
        return
    }

    if ($atual.Version -ceq $Version) {
        Write-Status OK "php.exe in $PhpDir is already $Version. Nothing was changed."
        Write-Status INFO "Close php -S and start it again to use it."
        $global:LASTEXITCODE = 0
        return
    }

    $null = $atual.Version -cmatch '\A([0-9]{1,2})\.([0-9]{1,2})\.'
    if (($Matches[1] + '.' + $Matches[2]) -cne $minor) {
        Write-Status ERROR "php.exe in $PhpDir is $($atual.Version). This update stays inside $minor. Nothing was changed."
        return
    }

    if ([version]$atual.Version -ge [version]$Version) {
        Write-Status ERROR "php.exe in $PhpDir is $($atual.Version), not older than $Version. Nothing was changed."
        return
    }

    if (-not $atual.Nts -or -not $atual.X64) {
        Write-Status ERROR "php.exe in $PhpDir is not an NTS x64 build. Only the official NTS x64 zip is installed here. Nothing was changed."
        return
    }

    $listagem = $null
    try {
        $listagem = Invoke-PhportoPhpGet -Url 'https://downloads.php.net/~windows/releases/releases.json'
    } catch {
        Write-Status ERROR "Could not read the official list: $($_.Exception.Message) Nothing was changed."
        return
    }

    $release = Select-PhportoPhpRelease -Json $listagem -Minor $minor
    if ($null -eq $release) {
        Write-Status ERROR "The official list has no NTS x64 zip for $minor. Nothing was changed."
        return
    }

    if ($release.Version -cne $Version -or $release.Sha256 -cne $Sha256) {
        Write-Status ERROR "The official list now has $($release.Version) with hash $($release.Sha256). Reload the PHP page. Nothing was changed."
        return
    }

    $backup = Get-PhportoPhpBackupPath -PhpDir $PhpDir -OldVersion $atual.Version
    if (-not $backup) {
        Write-Status ERROR "$PhpDir-$($atual.Version) and its numbered copies up to -9 already exist. Remove an old backup first. Nothing was changed."
        return
    }

    $temp = Join-Path ([System.IO.Path]::GetTempPath()) ('phporto-php-' + [guid]::NewGuid().ToString('N'))

    try {
        $null = New-Item -ItemType Directory -Path $temp -Force
        $zip  = Join-Path $temp $release.File

        Write-Status INFO "Downloading $($release.Url)"
        try {
            $null = Invoke-PhportoPhpGet -Url $release.Url -OutFile $zip
        } catch {
            Write-Status ERROR "Download failed: $($_.Exception.Message) Nothing was changed."
            return
        }

        $hash = Get-PhportoPhpHash $zip
        if ($hash -cne $Sha256) {
            Write-Status ERROR "SHA256 mismatch: expected $Sha256, got $hash. The zip was discarded. Nothing was changed."
            return
        }
        Write-Status OK "SHA256 checked: $hash"

        $novo = Join-Path $temp 'novo'
        try {
            Expand-PhportoPhpZip -Zip $zip -Destino $novo
        } catch {
            Write-Status ERROR "Could not extract the zip: $($_.Exception.Message) Nothing was changed."
            return
        }

        if (-not (Test-Path -LiteralPath (Join-Path $novo 'php.exe') -PathType Leaf)) {
            Write-Status ERROR "The zip has no php.exe at the top. Nothing was changed."
            return
        }

        try {
            Copy-PhportoPhpBackup -PhpDir $PhpDir -Backup $backup
        } catch {
            Write-Status ERROR "Backup to $backup failed: $($_.Exception.Message) Nothing was changed."
            return
        }
        Write-Status OK "Backup: $backup"

        $feitos = New-Object System.Collections.Generic.List[object]
        $falha  = $null

        try {
            Install-PhportoPhpArquivos -Origem $novo -Destino $PhpDir -Feitos $feitos
        } catch {
            $falha = "Replacing the files failed: $($_.Exception.Message)"
        }

        if (-not $falha) {
            $iniVelho = Join-Path $backup 'php.ini'
            if (Test-Path -LiteralPath $iniVelho -PathType Leaf) {
                try {
                    Copy-Item -LiteralPath $iniVelho -Destination (Join-Path $PhpDir 'php.ini') -Force -ErrorAction Stop
                    Write-Status OK "php.ini copied back from the backup."
                } catch {
                    $falha = "Could not copy php.ini back: $($_.Exception.Message)"
                }
            }
        }

        if (-not $falha) {
            $depois = Get-PhportoPhpExeInfo $exe
            if ($null -eq $depois -or $depois.Version -cne $Version) {
                $falha = "php.exe -v did not report $Version after the update."
            }
        }

        if ($falha) {
            Write-Status ERROR $falha
            $sobra = Undo-PhportoPhpArquivos -Feitos $feitos.ToArray()
            if ($sobra -gt 0) {
                $resto = Restore-PhportoPhpBackup -Backup $backup -PhpDir $PhpDir
                Write-Status WARNING "$sobra file(s) could not be put back in place; copied again from $backup ($resto still failing). Check $PhpDir."
            } else {
                Write-Status INFO "The previous PHP is back in $PhpDir. The backup stays at $backup."
            }
            return
        }

        $presos = Remove-PhportoPhpVelhos -Feitos $feitos.ToArray()

        Write-Status OK "PHP $($atual.Version) -> $Version in $PhpDir."
        if ($presos -gt 0) {
            Write-Status INFO "$presos old file(s) are still in use by the running php -S and stay as *.phporto-old until the next update."
        }
        Write-Status INFO "PATH and permissions were not changed. Close php -S and start it again to use PHP $Version."
        $global:LASTEXITCODE = 0
    } finally {
        Remove-Item -LiteralPath $temp -Recurse -Force -ErrorAction SilentlyContinue
    }
}
