<?php

declare(strict_types=1);

namespace App\Win;

final class Ollama
{
    public const VERSION = 'v0.40.13';

    public const SHA256 = '9e3c76e54e7184c68e88a2378b909bf59bbe454f83c076cb4f4a717e946ae719';

    public const URL = 'https://github.com/ollama/ollama/releases/download/' . self::VERSION . '/OllamaSetup.exe';

    public const INSTALLER_BYTES = 1676266440;

    public const HOST = '127.0.0.1';

    public const PORT = 11434;

    public const DIR = 'ollama';

    public const INSTALLER = 'OllamaSetup.exe';

    public const F_DOWNLOAD = 'baixar.json';

    public const F_PULL = 'pull.json';

    public const NIVEL_MEDIO = 'medio';

    private const NIVEL_FUNCS = <<<'PS'
        function Test-PhportoOllamaNivel {
            $grupos = (& whoami /groups) -join "`n"
            if ($grupos -match 'S-1-16-12288|S-1-16-16384') { return 'alto' }
            if ($grupos -match 'S-1-16-8192') { return 'medio' }
            return 'desconhecido'
        }
        PS;

    private const STATUS_FUNCS = <<<'PS'
        function Get-PhportoOllamaStatus {
            $exe = Join-Path $env:LOCALAPPDATA 'Programs\Ollama\ollama.exe'
            if (Test-Path -LiteralPath $exe -PathType Leaf) { 'EXE=sim' } else { 'EXE=nao' }
            $p = @(Get-Process -Name 'ollama' -ErrorAction SilentlyContinue)
            'PROC=' + $p.Count
            $h = [Environment]::GetEnvironmentVariable('OLLAMA_HOST', 'User')
            if ($h) { 'HOSTENV=' + $h }
            $smi = Get-Command -Name 'nvidia-smi.exe' -ErrorAction SilentlyContinue
            if ($smi) {
                $l = & $smi.Source '--query-gpu=name,memory.total,memory.used' '--format=csv,noheader,nounits' 2>$null | Select-Object -First 1
                if ($l) { 'GPU=' + $l; return }
            }
            $v = Get-CimInstance -ClassName Win32_VideoController -ErrorAction SilentlyContinue | Sort-Object -Property AdapterRAM -Descending | Select-Object -First 1
            if ($v) { 'GPUAPROX=' + $v.Name + ',' + [math]::Floor([double]$v.AdapterRAM / 1MB) }
        }
        PS;

    private const INSTALL_FUNCS = <<<'PS'
        function Invoke-PhportoOllamaInstall {
            param([string]$Arquivo, [string]$Esperado)
            if (-not (Test-Path -LiteralPath $Arquivo -PathType Leaf)) { 'OLLAMA=sem-instalador'; return }
            $h = (Get-FileHash -LiteralPath $Arquivo -Algorithm SHA256).Hash.ToLowerInvariant()
            if ($h -ne $Esperado.ToLowerInvariant()) { 'OLLAMA=hash-divergente'; return }
            Start-Process -FilePath $Arquivo | Out-Null
            'OLLAMA=instalador-aberto'
        }
        PS;

    private const UNINSTALL_FUNCS = <<<'PS'
        function Invoke-PhportoOllamaUninstall {
            $base = Join-Path $env:LOCALAPPDATA 'Programs\Ollama'
            $chave = Get-ChildItem -Path 'HKCU:\Software\Microsoft\Windows\CurrentVersion\Uninstall' -ErrorAction SilentlyContinue |
                ForEach-Object { Get-ItemProperty -LiteralPath $_.PSPath -ErrorAction SilentlyContinue } |
                Where-Object { $_.DisplayName -like 'Ollama*' } | Select-Object -First 1
            if ($null -eq $chave) { 'OLLAMA=sem-desinstalador'; return }
            if ([string]$chave.UninstallString -notmatch '^\s*"?([^"]+\.exe)') { 'OLLAMA=desinstalador-recusado'; return }
            $exe = $Matches[1]
            $pasta = Split-Path -Path $exe -Parent
            if ($pasta.TrimEnd('\') -ne $base.TrimEnd('\') -or (Split-Path -Path $exe -Leaf) -notlike 'unins*.exe') { 'OLLAMA=desinstalador-recusado'; return }
            Get-Process -Name 'ollama', 'ollama app' -ErrorAction SilentlyContinue | Stop-Process -Force -ErrorAction SilentlyContinue
            Start-Process -FilePath $exe | Out-Null
            'OLLAMA=desinstalador-aberto'
        }
        PS;

    private const START_FUNCS = <<<'PS'
        function Start-PhportoOllama {
            $exe = Join-Path $env:LOCALAPPDATA 'Programs\Ollama\ollama.exe'
            if (-not (Test-Path -LiteralPath $exe -PathType Leaf)) { 'OLLAMA=nao-instalado'; return }
            if (Get-Process -Name 'ollama' -ErrorAction SilentlyContinue) { 'OLLAMA=ja-rodando'; return }
            $env:OLLAMA_HOST = '127.0.0.1:11434'
            Start-Process -FilePath $exe -ArgumentList 'serve' -WindowStyle Hidden | Out-Null
            'OLLAMA=iniciado'
        }
        PS;

    private const STOP_FUNCS = <<<'PS'
        function Stop-PhportoOllama {
            $p = @(Get-Process -Name 'ollama', 'ollama app' -ErrorAction SilentlyContinue)
            $p | Stop-Process -Force -ErrorAction SilentlyContinue
            'OLLAMA=parado,' + $p.Count
        }
        PS;

    private const CHILD_FUNCS = <<<'PS'
        function Start-PhportoOllamaFilho {
            param([string]$Php, [string]$Script)
            if (-not (Test-Path -LiteralPath $Php -PathType Leaf)) { 'FILHO=sem-php'; return }
            if (-not (Test-Path -LiteralPath $Script -PathType Leaf)) { 'FILHO=sem-script'; return }
            Start-Process -FilePath $Php -ArgumentList @('-f', ('"' + $Script + '"')) -WindowStyle Hidden | Out-Null
            'FILHO=iniciado'
        }
        PS;

    public static function statusScript(): string
    {
        return self::script(self::STATUS_FUNCS, 'Get-PhportoOllamaStatus');
    }

    public static function installScript(string $installer): string
    {
        return self::script(
            self::INSTALL_FUNCS,
            'Invoke-PhportoOllamaInstall -Arquivo ' . PsScriptBuilder::literal($installer)
                . ' -Esperado ' . PsScriptBuilder::literal(self::SHA256),
        );
    }

    public static function uninstallScript(): string
    {
        return self::script(self::UNINSTALL_FUNCS, 'Invoke-PhportoOllamaUninstall');
    }

    public static function startScript(): string
    {
        return self::script(self::START_FUNCS, 'Start-PhportoOllama');
    }

    public static function stopScript(): string
    {
        return self::script(self::STOP_FUNCS, 'Stop-PhportoOllama');
    }

    public static function childScript(string $php, string $script): string
    {
        return self::script(
            self::CHILD_FUNCS,
            'Start-PhportoOllamaFilho -Php ' . PsScriptBuilder::literal($php) . ' -Script ' . PsScriptBuilder::literal($script),
        );
    }

    public static function downloadChild(string $installer, string $progress): string
    {
        return '<?php' . "\n"
            . '$url = ' . var_export(self::URL, true) . ";\n"
            . '$alvo = ' . var_export($installer, true) . ";\n"
            . '$prog = ' . var_export($progress, true) . ";\n"
            . '$sha = ' . var_export(self::SHA256, true) . ";\n"
            . '$max = ' . self::INSTALLER_BYTES . ";\n"
            . <<<'PHP'
                $parcial = $alvo . '.part';
                $grava = static function (string $estado, int $n) use ($prog): void {
                    @file_put_contents($prog, (string) json_encode(['estado' => $estado, 'bytes' => $n]), LOCK_EX);
                };
                $grava('baixando', 0);
                $ctx = stream_context_create([
                    'http' => ['follow_location' => 1, 'max_redirects' => 5, 'timeout' => 60, 'header' => "User-Agent: PHPorto\r\n"],
                    'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
                ]);
                $in  = @fopen($url, 'rb', false, $ctx);
                $out = @fopen($parcial, 'wb');
                if ($in === false || $out === false) {
                    $grava('erro', 0);
                    exit(1);
                }
                $n = 0;
                $marca = 0;
                $falhou = false;
                while (!feof($in)) {
                    $b = fread($in, 1048576);
                    if ($b === false || stream_get_meta_data($in)['timed_out']) {
                        $falhou = true;
                        break;
                    }
                    $n += strlen($b);
                    if ($n > $max) {
                        $falhou = true;
                        break;
                    }
                    fwrite($out, $b);
                    if ($n - $marca >= 16777216) {
                        $marca = $n;
                        $grava('baixando', $n);
                    }
                }
                fclose($in);
                fclose($out);
                if ($falhou || !hash_equals($sha, (string) hash_file('sha256', $parcial))) {
                    @unlink($parcial);
                    $grava($falhou ? 'erro' : 'hash-divergente', $n);
                    exit(1);
                }
                @unlink($alvo);
                rename($parcial, $alvo);
                $grava('pronto', $n);
                @unlink(__FILE__);

                PHP;
    }

    public static function pullChild(OllamaModel $model, string $progress): string
    {
        return '<?php' . "\n"
            . '$prog = ' . var_export($progress, true) . ";\n"
            . '$url = ' . var_export(self::apiUrl('/api/pull'), true) . ";\n"
            . '$corpo = ' . var_export((string) json_encode(['model' => $model->value, 'stream' => true]), true) . ";\n"
            . <<<'PHP'
                $ctx = stream_context_create(['http' => [
                    'method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $corpo,
                    'timeout' => 600, 'ignore_errors' => true, 'follow_location' => 0,
                ]]);
                $h = @fopen($url, 'r', false, $ctx);
                if ($h === false) {
                    @file_put_contents($prog, '{"error":"sem conexao com 127.0.0.1:11434"}', LOCK_EX);
                    exit(1);
                }
                while (($l = fgets($h)) !== false) {
                    $l = trim($l);
                    if ($l !== '') {
                        @file_put_contents($prog, $l, LOCK_EX);
                    }
                }
                fclose($h);
                @unlink(__FILE__);

                PHP;
    }

    public static function apiUrl(string $path): string
    {
        if (!in_array($path, ['/api/version', '/api/tags', '/api/pull', '/api/delete', '/api/ps'], true)) {
            throw new \InvalidArgumentException('Caminho de API fora da lista.');
        }

        return 'http://' . self::HOST . ':' . self::PORT . $path;
    }

    public static function installerMatches(string $path): bool
    {
        if (!is_file($path)) {
            return false;
        }

        $hash = @hash_file('sha256', $path);

        return is_string($hash) && hash_equals(self::SHA256, $hash);
    }

    public static function nivel(string $output): string
    {
        return preg_match('/^NIVEL=(alto|medio|desconhecido)\s*$/m', $output, $m) === 1 ? $m[1] : 'desconhecido';
    }

    public static function marker(string $output, string $chave): ?string
    {
        return preg_match('/^' . preg_quote($chave, '/') . '=([^\r\n]*)\r?$/m', $output, $m) === 1 ? trim($m[1]) : null;
    }

    public static function vramRefusal(OllamaModel $model, ?OllamaStatus $status): ?string
    {
        $min = $model->minVramMb();

        if ($min === null) {
            return null;
        }

        if ($status === null || $status->vramMb === null) {
            return 'Não deu para medir a memória da placa de vídeo. Nada foi baixado.';
        }

        if ($status->vramMb < $min) {
            return 'O ' . $model->value . ' pede ' . $min . ' MiB de memória de vídeo e a placa tem '
                . $status->vramMb . ' MiB. Nada foi baixado.';
        }

        return null;
    }

    /**
     * @return array<string, int>
     */
    public static function parseTags(string $json): array
    {
        $dados = json_decode($json, true);
        $saida = [];

        if (!is_array($dados) || !is_array($dados['models'] ?? null)) {
            return $saida;
        }

        foreach ($dados['models'] as $m) {
            if (is_array($m) && is_string($m['name'] ?? null)) {
                $saida[$m['name']] = is_int($m['size'] ?? null) ? $m['size'] : 0;
            }
        }

        return $saida;
    }

    /**
     * @return list<array{name: string, size: int, size_vram: int, expires_at: ?string}>
     */
    public static function parsePs(string $json): array
    {
        $dados = json_decode($json, true);
        $saida = [];

        if (!is_array($dados) || !is_array($dados['models'] ?? null)) {
            return $saida;
        }

        foreach ($dados['models'] as $m) {
            if (!is_array($m) || !is_string($m['name'] ?? null)) {
                continue;
            }

            $saida[] = [
                'name'       => $m['name'],
                'size'       => is_int($m['size'] ?? null) ? $m['size'] : 0,
                'size_vram'  => is_int($m['size_vram'] ?? null) ? $m['size_vram'] : 0,
                'expires_at' => is_string($m['expires_at'] ?? null) ? $m['expires_at'] : null,
            ];
        }

        return $saida;
    }

    public static function versionMatches(?string $running): ?bool
    {
        if ($running === null || $running === '') {
            return null;
        }

        return ltrim($running, 'vV') === ltrim(self::VERSION, 'vV');
    }

    /**
     * @return array{estado: string, bytes: int, total: int}|null
     */
    public static function parseProgress(string $json): ?array
    {
        $d = json_decode($json, true);

        if (!is_array($d)) {
            return null;
        }

        if (is_string($d['error'] ?? null)) {
            return ['estado' => 'erro: ' . $d['error'], 'bytes' => 0, 'total' => 0];
        }

        $estado = is_string($d['estado'] ?? null) ? $d['estado'] : (is_string($d['status'] ?? null) ? $d['status'] : null);

        if ($estado === null) {
            return null;
        }

        return [
            'estado' => $estado,
            'bytes'  => is_int($d['bytes'] ?? null) ? $d['bytes'] : (is_int($d['completed'] ?? null) ? $d['completed'] : 0),
            'total'  => is_int($d['total'] ?? null) ? $d['total'] : 0,
        ];
    }

    private static function script(string $funcs, string $call): string
    {
        return '$ErrorActionPreference = ' . "'Continue'\n"
            . self::NIVEL_FUNCS . "\n"
            . $funcs . "\n"
            . '$n = Test-PhportoOllamaNivel; "NIVEL=$n"; if ($n -ne ' . "'medio'" . ') { exit 5 }; ' . $call . "\n";
    }
}
