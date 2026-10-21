$global:PhportoSshKeyPattern = '\A(?:ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp256|ecdsa-sha2-nistp384|ecdsa-sha2-nistp521) AAAA[A-Za-z0-9+/]{16,2000}={0,2}(?: [A-Za-z0-9@._+-]{1,100})?\z'

function Test-PhportoSshPublicKey {
    param([string]$Line)

    if ([string]::IsNullOrEmpty($Line) -or $Line.Length -gt 2200 -or $Line -cnotmatch $global:PhportoSshKeyPattern) {
        return $false
    }

    $partes = $Line.Split(' ')
    try {
        $blob = [Convert]::FromBase64String($partes[1])
    } catch {
        return $false
    }
    if ($blob.Length -lt 4) { return $false }

    $tamanho = ([int]$blob[0] -shl 24) -bor ([int]$blob[1] -shl 16) -bor ([int]$blob[2] -shl 8) -bor [int]$blob[3]
    if ($tamanho -ne $partes[0].Length -or $blob.Length -lt (4 + $tamanho)) { return $false }

    return ([System.Text.Encoding]::ASCII.GetString($blob, 4, $tamanho) -ceq $partes[0])
}

function Get-PhportoSshFingerprint {
    param([string]$Line)

    if (-not (Test-PhportoSshPublicKey $Line)) { return $null }

    $blob = [Convert]::FromBase64String($Line.Split(' ')[1])
    $sha  = [System.Security.Cryptography.SHA256]::Create()
    try {
        $hash = $sha.ComputeHash($blob)
    } finally {
        $sha.Dispose()
    }

    return 'SHA256:' + [Convert]::ToBase64String($hash).TrimEnd('=')
}

function Get-PhportoSshKeyKind {
    param([string]$Line)

    $tipo = $Line.Split(' ')[0]
    if ($tipo -like 'ecdsa-*') { return 'ecdsa' }
    return $tipo -replace '^ssh-', ''
}

function Get-PhportoSshPaths {
    $perfil = $env:USERPROFILE
    $dados  = $env:ProgramData
    return @{
        Private    = Join-Path (Join-Path $perfil '.ssh') 'id_ed25519'
        Public     = Join-Path (Join-Path $perfil '.ssh') 'id_ed25519.pub'
        AdminKeys  = Join-Path (Join-Path $dados 'ssh') 'administrators_authorized_keys'
        Keygen     = Join-Path $env:WINDIR 'System32\OpenSSH\ssh-keygen.exe'
        Puttygen   = Join-Path $env:ProgramFiles 'PuTTY\puttygen.exe'
    }
}

function Get-PhportoSshComment {
    $nome = ([string]$env:COMPUTERNAME) -replace '[^A-Za-z0-9._-]', ''
    if ($nome -eq '') { $nome = 'windows' }
    return $nome.ToLower()
}

function Invoke-PhportoSshKeygen {
    param([string]$Keygen, [string]$Path, [string]$Comment)

    $info = New-Object System.Diagnostics.ProcessStartInfo
    $info.FileName               = $Keygen
    $info.Arguments              = '-q -t ed25519 -N "" -C "' + $Comment + '" -f "' + $Path + '"'
    $info.UseShellExecute        = $false
    $info.RedirectStandardInput  = $true
    $info.RedirectStandardOutput = $true
    $info.RedirectStandardError  = $true
    $info.CreateNoWindow         = $true

    $proc = [System.Diagnostics.Process]::Start($info)
    $proc.StandardInput.Close()
    $erro = $proc.StandardError.ReadToEnd()
    $null = $proc.StandardOutput.ReadToEnd()
    $proc.WaitForExit()

    return @{ ExitCode = $proc.ExitCode; Error = $erro.Trim() }
}

function Set-PhportoSshAdminAcl {
    param([string]$Path)

    $acl = New-Object System.Security.AccessControl.FileSecurity
    $acl.SetAccessRuleProtection($true, $false)
    $acl.SetOwner((New-Object System.Security.Principal.SecurityIdentifier('S-1-5-32-544')))
    foreach ($sid in @('S-1-5-18', 'S-1-5-32-544')) {
        $acl.AddAccessRule((New-Object System.Security.AccessControl.FileSystemAccessRule(
            (New-Object System.Security.Principal.SecurityIdentifier($sid)), 'FullControl', 'Allow')))
    }
    Set-Acl -LiteralPath $Path -AclObject $acl
}

function Read-PhportoSshKeyFile {
    param([string]$Path)

    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) { return @() }
    return @([System.IO.File]::ReadAllLines($Path) | ForEach-Object { $_.Trim() } | Where-Object { $_ -ne '' })
}

function Write-PhportoSshKeyFile {
    param([string]$Path, [string[]]$Lines)

    $pasta = Split-Path -Parent $Path
    if (-not (Test-Path -LiteralPath $pasta -PathType Container)) {
        New-Item -ItemType Directory -Path $pasta -Force | Out-Null
    }

    $texto = if ($Lines.Count -gt 0) { ($Lines -join "`n") + "`n" } else { '' }
    [System.IO.File]::WriteAllText($Path, $texto, (New-Object System.Text.UTF8Encoding($false)))
    Set-PhportoSshAdminAcl -Path $Path
}

function Invoke-PhportoWslRoot {
    param([string]$Distro, [string]$User, [string]$Key)

    $ErrorActionPreference = 'Continue'
    $script = 'set -e; u=$1; if id -u $u >/dev/null 2>&1; then echo PHPORTO_EXISTE; exit 3; fi; ' +
        'useradd -m -s /bin/bash $u; g=$(id -gn $u); h=$(getent passwd $u | cut -d: -f6); ' +
        'install -d -m 700 -o $u -g $g $h/.ssh; head -n 1 | tr -d \\r > $h/.ssh/authorized_keys; ' +
        'chown $u:$g $h/.ssh/authorized_keys; chmod 600 $h/.ssh/authorized_keys; echo PHPORTO_OK'

    $saida = @($Key | & wsl.exe -d $Distro -u root --exec /bin/sh -c $script phporto $User 2>&1 | ForEach-Object { [string]$_ })
    return @{ ExitCode = $LASTEXITCODE; Output = ($saida -join "`n").Replace([string][char]0, '').Trim() }
}

function Invoke-SshKeys {
    param(
        [string]$SubAction,
        [string]$PublicKey,
        [switch]$UseGenerated,
        [string]$Fingerprint,
        [string]$User,
        [string]$Distro
    )

    $options  = 'status, generate, puttygen, list, authorize, remove, wsl-user'
    $wingetId = 'PuTTY.PuTTY'

    function Show-PhportoSshPublic {
        $linha = @(Read-PhportoSshKeyFile $caminhos.Public | Select-Object -First 1)
        if ($linha.Count -eq 0) {
            Write-Status INFO "No key at $($caminhos.Public)."
            return
        }
        $digital = Get-PhportoSshFingerprint $linha[0]
        if ($null -eq $digital) {
            Write-Status WARNING "$($caminhos.Public) is not a public key line this screen accepts."
            return
        }
        Write-Status OK "Public key: $($linha[0])"
        Write-Status OK "Fingerprint: $digital"
    }

    function Get-PhportoSshChosenKey {
        if ($UseGenerated) {
            $linha = @(Read-PhportoSshKeyFile $caminhos.Public | Select-Object -First 1)
            if ($linha.Count -eq 0) {
                Write-Status ERROR "No generated key at $($caminhos.Public); use generate first."
                return $null
            }
            $chave = $linha[0]
            if (-not (Test-PhportoSshPublicKey $chave)) {
                $chave = ($chave.Split(' ') | Select-Object -First 2) -join ' '
            }
        } else {
            $chave = [string]$PublicKey
        }

        if (-not (Test-PhportoSshPublicKey $chave)) {
            Write-Status ERROR 'Not a public key line (ssh-ed25519, ssh-rsa or ecdsa, no options, no private key).'
            return $null
        }
        return $chave
    }

    function Show-PhportoSshStatus {
        $svc = Get-Service -Name 'sshd' -ErrorAction SilentlyContinue
        if ($null -eq $svc) {
            Write-Status WARNING "OpenSSH Server: service 'sshd' absent. Turn it on in Acesso Remoto."
        } else {
            Write-Status INFO "OpenSSH Server: service 'sshd' $($svc.Status)."
        }
        Show-PhportoSshPublic
        if (Test-Path -LiteralPath $caminhos.Puttygen -PathType Leaf) {
            Write-Status OK 'PuTTYgen: installed.'
        } else {
            Write-Status INFO 'PuTTYgen: not installed.'
        }
        Write-Status INFO "Authorized keys: $(@(Read-PhportoSshKeyFile $caminhos.AdminKeys).Count)."
    }

    function New-PhportoSshKey {
        if ((Test-Path -LiteralPath $caminhos.Private) -or (Test-Path -LiteralPath $caminhos.Public)) {
            Write-Status WARNING "A key already exists at $($caminhos.Private); nothing was overwritten."
            Show-PhportoSshPublic
            return
        }
        if (-not (Test-Path -LiteralPath $caminhos.Keygen -PathType Leaf)) {
            Write-Status ERROR "ssh-keygen not found at $($caminhos.Keygen); install OpenSSH in Acesso Remoto."
            return
        }
        $pasta = Split-Path -Parent $caminhos.Private
        if (-not (Test-Path -LiteralPath $pasta -PathType Container)) {
            New-Item -ItemType Directory -Path $pasta -Force | Out-Null
        }
        $r = Invoke-PhportoSshKeygen -Keygen $caminhos.Keygen -Path $caminhos.Private -Comment (Get-PhportoSshComment)
        if ($r.ExitCode -ne 0) {
            Write-Status ERROR "ssh-keygen failed (exit $($r.ExitCode)): $($r.Error)"
            return
        }
        Write-Status OK "ed25519 key created at $($caminhos.Private); the private key stays on disk and is not shown."
        Show-PhportoSshPublic
    }

    function Show-PhportoSshAuthorized {
        $linhas = @(Read-PhportoSshKeyFile $caminhos.AdminKeys)
        if ($linhas.Count -eq 0) {
            Write-Status INFO 'No authorized key on this Windows.'
            return
        }
        foreach ($l in $linhas) {
            $digital = Get-PhportoSshFingerprint $l
            if ($null -eq $digital) {
                Write-Status WARNING 'Authorized file has a line this screen does not read (left as is).'
                continue
            }
            $partes     = $l.Split(' ', 3)
            $comentario = if ($partes.Count -ge 3) { $partes[2] } else { '' }
            Write-Status INFO ("Authorized key: {0} {1} {2}" -f (Get-PhportoSshKeyKind $l), $digital, $comentario).TrimEnd()
        }
    }

    function Add-PhportoSshAuthorized {
        $chave = Get-PhportoSshChosenKey
        if ($null -eq $chave) { return }
        $digital = Get-PhportoSshFingerprint $chave
        $linhas  = @(Read-PhportoSshKeyFile $caminhos.AdminKeys)
        if (@($linhas | Where-Object { (Get-PhportoSshFingerprint $_) -eq $digital }).Count -gt 0) {
            Write-Status INFO "Key $digital is already authorized."
            Set-PhportoSshAdminAcl -Path $caminhos.AdminKeys
            Show-PhportoSshAuthorized
            return
        }
        try {
            Write-PhportoSshKeyFile -Path $caminhos.AdminKeys -Lines (@($linhas) + $chave)
        } catch {
            Write-Status ERROR "Could not write $($caminhos.AdminKeys): $($_.Exception.Message)"
            return
        }
        Write-Status OK "Key $digital authorized; file restricted to SYSTEM and Administrators."
        Show-PhportoSshAuthorized
    }

    function Remove-PhportoSshAuthorized {
        $linhas = @(Read-PhportoSshKeyFile $caminhos.AdminKeys)
        $ficam  = @($linhas | Where-Object { (Get-PhportoSshFingerprint $_) -ne $Fingerprint })
        if ($ficam.Count -eq $linhas.Count) {
            Write-Status ERROR "Key $Fingerprint is not authorized here."
            return
        }
        try {
            Write-PhportoSshKeyFile -Path $caminhos.AdminKeys -Lines $ficam
        } catch {
            Write-Status ERROR "Could not write $($caminhos.AdminKeys): $($_.Exception.Message)"
            return
        }
        Write-Status OK "Key $Fingerprint removed."
        Show-PhportoSshAuthorized
    }

    function New-PhportoWslUser {
        if ($User -cnotmatch '\A[a-z][a-z0-9-]{0,31}\z' -or $User -in @('root', 'daemon', 'bin', 'sys', 'sync', 'nobody', 'sshd')) {
            Write-Status ERROR 'Invalid user name.'
            return
        }
        if ($Distro -cnotmatch '\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z') {
            Write-Status ERROR 'Invalid distro name.'
            return
        }
        $chave = Get-PhportoSshChosenKey
        if ($null -eq $chave) { return }
        $r = Invoke-PhportoWslRoot -Distro $Distro -User $User -Key $chave
        if ($r.Output -match 'PHPORTO_EXISTE') {
            Write-Status ERROR "User '$User' already exists in $Distro; choose another name."
            return
        }
        if ($r.ExitCode -ne 0 -or $r.Output -notmatch 'PHPORTO_OK') {
            Write-Status ERROR "Could not create '$User' in $Distro (exit $($r.ExitCode)): $($r.Output)"
            return
        }
        Write-Status OK "WSL user: $User $Distro $(Get-PhportoSshFingerprint $chave)"
        Write-Status OK 'No sudo; ~/.ssh is 700 and authorized_keys is 600.'
    }

    if (-not $SubAction) {
        Write-Status ERROR 'No subaction specified.'
        Write-Status INFO "Options: $options"
        return
    }

    $caminhos = Get-PhportoSshPaths

    switch ($SubAction.ToLower()) {
        'status'    { Show-PhportoSshStatus }
        'generate'  { New-PhportoSshKey }
        'puttygen'  { Invoke-Install -Apps $wingetId }
        'list'      { Show-PhportoSshAuthorized }
        'authorize' { Add-PhportoSshAuthorized }
        'remove'    { Remove-PhportoSshAuthorized }
        'wsl-user'  { New-PhportoWslUser }
        default {
            Write-Status ERROR "Unknown subaction: '$SubAction'."
            Write-Status INFO "Options: $options"
        }
    }
}
