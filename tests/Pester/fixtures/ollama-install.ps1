$ErrorActionPreference = 'Continue'
function Test-PhportoOllamaNivel {
    $grupos = (& whoami /groups) -join "`n"
    if ($grupos -match 'S-1-16-12288|S-1-16-16384') { return 'alto' }
    if ($grupos -match 'S-1-16-8192') { return 'medio' }
    return 'desconhecido'
}
function Invoke-PhportoOllamaInstall {
    param([string]$Arquivo, [string]$Esperado)
    if (-not (Test-Path -LiteralPath $Arquivo -PathType Leaf)) { 'OLLAMA=sem-instalador'; return }
    $h = (Get-FileHash -LiteralPath $Arquivo -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($h -ne $Esperado.ToLowerInvariant()) { 'OLLAMA=hash-divergente'; return }
    Start-Process -FilePath $Arquivo | Out-Null
    'OLLAMA=instalador-aberto'
}
$n = Test-PhportoOllamaNivel; "NIVEL=$n"; if ($n -ne 'medio') { exit 5 }; Invoke-PhportoOllamaInstall -Arquivo 'C:\PHPorto\files\ollama\OllamaSetup.exe' -Esperado '9e3c76e54e7184c68e88a2378b909bf59bbe454f83c076cb4f4a717e946ae719'
