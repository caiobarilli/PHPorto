#Requires -Version 5.1

BeforeAll {
    $Script:Raiz    = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $Script:Fixture = Join-Path $PSScriptRoot 'fixtures\ollama-install.ps1'
    $linhas = @(Get-Content -LiteralPath $Script:Fixture -Encoding UTF8 | Where-Object { $_.Trim() -ne '' })
    $corpo = ($linhas[0..($linhas.Count - 2)]) -join "`r`n"
    . ([scriptblock]::Create($corpo))
    if (-not (Get-Command -Name 'whoami' -ErrorAction SilentlyContinue)) {
        function global:whoami { throw 'stand-in de whoami sem mock' }
    }
}

Describe 'Ollama - script de instalacao gerado pelo PHP' {

    BeforeEach {
        $Script:Arquivo = Join-Path ([System.IO.Path]::GetTempPath()) ('ollama-' + [guid]::NewGuid().ToString('N') + '.exe')
        [System.IO.File]::WriteAllText($Script:Arquivo, 'nao e o instalador')
        Mock Start-Process {}
    }

    AfterEach {
        Remove-Item -LiteralPath $Script:Arquivo -Force -ErrorAction SilentlyContinue
    }

    It 'o fixture tem BOM, CRLF e so ASCII' {
        $b = [System.IO.File]::ReadAllBytes($Script:Fixture)
        $b[0..2] | Should -Be @(0xEF, 0xBB, 0xBF)
        $texto = [System.Text.Encoding]::ASCII.GetString($b, 3, $b.Length - 3)
        ($texto -split "`r`n").Count | Should -BeGreaterThan 5
        ($texto -replace "`r`n", '') | Should -Not -Match "`n"
        @($b[3..($b.Length - 1)] | Where-Object { $_ -gt 0x7E -or ($_ -lt 0x20 -and $_ -ne 0x0D -and $_ -ne 0x0A -and $_ -ne 0x09) }).Count | Should -Be 0
    }

    It 'a ultima linha checa o nivel antes de instalar com o hash fixado' {
        $ultima = @(Get-Content -LiteralPath $Script:Fixture | Where-Object { $_.Trim() -ne '' })[-1]
        $ultima | Should -Match "if \(\`$n -ne 'medio'\) \{ exit 5 \}; Invoke-PhportoOllamaInstall"
        $ultima | Should -Match "-Esperado '9e3c76e54e7184c68e88a2378b909bf59bbe454f83c076cb4f4a717e946ae719'"
    }

    It 'recusa instalador com hash diferente e nao executa nada' {
        $saida = Invoke-PhportoOllamaInstall -Arquivo $Script:Arquivo -Esperado '9e3c76e54e7184c68e88a2378b909bf59bbe454f83c076cb4f4a717e946ae719'
        $saida | Should -Be 'OLLAMA=hash-divergente'
        Should -Invoke Start-Process -Times 0 -Exactly
    }

    It 'sem o arquivo nao executa nada' {
        $saida = Invoke-PhportoOllamaInstall -Arquivo ($Script:Arquivo + '.nada') -Esperado 'abc'
        $saida | Should -Be 'OLLAMA=sem-instalador'
        Should -Invoke Start-Process -Times 0 -Exactly
    }

    It 'com o hash certo abre o instalador como o usuario' {
        $h = (Get-FileHash -LiteralPath $Script:Arquivo -Algorithm SHA256).Hash
        $saida = Invoke-PhportoOllamaInstall -Arquivo $Script:Arquivo -Esperado $h
        $saida | Should -Be 'OLLAMA=instalador-aberto'
        Should -Invoke Start-Process -Times 1 -Exactly -ParameterFilter { $FilePath -eq $Script:Arquivo }
    }
}

Describe 'Ollama - nivel de integridade' {

    It 'alto quando o token tem S-1-16-12288' {
        Mock whoami { 'Mandatory Label\High Mandatory Level  Label  S-1-16-12288' }
        Test-PhportoOllamaNivel | Should -Be 'alto'
    }

    It 'alto quando o token e de sistema' {
        Mock whoami { 'Mandatory Label\System Mandatory Level  Label  S-1-16-16384' }
        Test-PhportoOllamaNivel | Should -Be 'alto'
    }

    It 'medio quando o token tem S-1-16-8192' {
        Mock whoami { 'Mandatory Label\Medium Mandatory Level  Label  S-1-16-8192' }
        Test-PhportoOllamaNivel | Should -Be 'medio'
    }

    It 'desconhecido sem rotulo' {
        Mock whoami { 'nada' }
        Test-PhportoOllamaNivel | Should -Be 'desconhecido'
    }
}
