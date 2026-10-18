<?php

declare(strict_types=1);

namespace App\Win;

use RuntimeException;
use Throwable;

final class Elevation
{
    public const PROOF_TIMEOUT_S = 30;

    public const SHUTDOWN_WAIT_S = 5;

    public const HEARTBEAT_STALE_S = 10;

    public const IDLE_TIMEOUT_S = 600;

    public const TIME_LIMIT_MARGIN_S = 15;

    public const ONESHOT_CONSENT_S = 60;

    public const ONESHOT_START_S = 15;

    public const ONESHOT_PREFIX = 'win-oneshot-';

    public const F_HEARTBEAT = 'win-heartbeat';

    public const F_JOB = 'win-job.json';

    public const F_ORDEM_DESLIGAR = 'win-ordem-desligar';

    public const F_ORDEM_CANCELAR = 'win-ordem-cancelar';

    public const F_MARCADOR = 'win-elevation.json';

    public const F_MANIFESTO = 'win-manifesto.json';

    public const DIR_PROTEGIDA = 'win-protected';

    public const SCRIPT_WORKER = 'worker.ps1';

    public const SCRIPT_BOOTSTRAP = 'bootstrap.ps1';

    /**
     * @param string $winDir
     */
    public function __construct(
        private readonly string $filesDir,
        private readonly string $storageDir,
        private readonly string $winDir,
    ) {
    }

    public function state(): ElevationState
    {
        $blocked = $this->configProblem();

        if ($blocked !== null) {
            return new ElevationState(on: false, blocked: $blocked);
        }

        $marcador = $this->readMarker();

        if ($marcador === null) {
            return new ElevationState(on: false);
        }

        if ($marcador['php_pid'] !== getmypid()) {
            return new ElevationState(on: false);
        }

        $idade = $this->heartbeatAge();

        if ($idade === null || $idade > self::HEARTBEAT_STALE_S) {
            return new ElevationState(
                on: false,
                detail: $idade === null
                    ? sprintf(
                        'O PowerShell elevado não deixou sinal de vida — ele sai sozinho depois de %d min sem uso, ou foi encerrado por fora.',
                        intdiv(self::IDLE_TIMEOUT_S, 60),
                    )
                    : sprintf('O PowerShell elevado não responde há %d s.', $idade),
            );
        }

        return new ElevationState(
            on: true,
            psPid: $marcador['ps_pid'],
            provedAt: $marcador['provado_em'],
        );
    }

    public function enable(): ?string
    {
        $blocked = $this->configProblem();

        if ($blocked !== null) {
            return $blocked;
        }

        $nonce = bin2hex(random_bytes(8));
        $prova = $this->path($this->filesDir, 'win-prova-' . $nonce . '.txt');

        $this->cleanupChannel();

        $launcher = $this->path($this->filesDir, 'win-launcher.ps1');

        try {
            $manifesto = WinManifest::write($this->winDir, $this->path($this->filesDir, self::F_MANIFESTO));
            PsScriptBuilder::write($launcher, $this->launcherBody($nonce, $manifesto));
        } catch (Throwable $e) {
            return 'Não foi possível preparar o lançador: ' . $e->getMessage();
        }

        $runner = new PsRunner($this->filesDir);

        set_time_limit(self::PROOF_TIMEOUT_S + self::TIME_LIMIT_MARGIN_S);

        try {
            $r = $runner->run($launcher, self::PROOF_TIMEOUT_S);
        } catch (RuntimeException $e) {
            return 'Não foi possível chamar o powershell.exe: ' . $e->getMessage();
        } finally {
            @unlink($launcher);
        }

        $limite = microtime(true) + self::PROOF_TIMEOUT_S;
        $bruto  = null;

        while (microtime(true) < $limite) {
            clearstatcache(true, $prova);

            if (is_file($prova)) {
                $lido = @file_get_contents($prova);

                if (is_string($lido) && (str_contains($lido, 'PID=') || str_starts_with($lido, 'ERRO='))) {
                    $bruto = $lido;
                    break;
                }
            }

            usleep(100000);
        }

        if ($bruto === null) {
            @unlink($prova);

            $extra = trim($r->output);

            return 'A permissão de Administrador não foi concedida'
                . sprintf(' (nada respondeu em %d s).', self::PROOF_TIMEOUT_S)
                . ($extra === '' ? '' : ' O PowerShell disse: ' . $extra);
        }

        if (str_starts_with($bruto, 'ERRO=')) {
            @unlink($prova);

            return 'O PowerShell elevado recusou subir: ' . trim(substr($bruto, 5));
        }

        preg_match('/PID=(\d+)/', $bruto, $m);
        $psPid = (int) ($m[1] ?? 0);

        if ($psPid <= 0) {
            @unlink($prova);

            return 'A prova de elevação voltou sem PID — o worker respondeu algo inesperado.';
        }

        if (!$this->writeMarker($psPid, $nonce)) {
            $this->order(self::F_ORDEM_DESLIGAR);

            return 'Não foi possível gravar ' . $this->markerPath()
                . '. Verifique a permissão de escrita da pasta storage/. O PowerShell elevado foi encerrado.';
        }

        return null;
    }

    public function disable(): string
    {
        $marcador = $this->readMarker();

        if (is_file($this->markerPath())) {
            @unlink($this->markerPath());
        }

        if (!$this->order(self::F_ORDEM_DESLIGAR)) {
            return 'Não foi possível escrever a ordem de desligar em ' . $this->filesDir
                . '. O PowerShell elevado pode continuar de pé até o servidor reiniciar.';
        }

        $limite = microtime(true) + self::SHUTDOWN_WAIT_S;

        while (microtime(true) < $limite) {
            $idade = $this->heartbeatAge();

            if ($idade === null || $idade > self::HEARTBEAT_STALE_S) {
                break;
            }

            usleep(150000);
        }

        $idade = $this->heartbeatAge();
        $pid   = $marcador['ps_pid'] ?? null;

        if ($idade === null) {
            return 'PowerShell desligado' . ($pid === null ? '' : ' (PID ' . $pid . ')') . '.';
        }

        return 'Ordem de desligar deixada. O PowerShell elevado sai no próximo tique — '
            . 'o PHP não pode encerrá-lo direto, porque roda em integridade menor.';
    }

    public function jobPath(): string
    {
        return $this->path($this->filesDir, self::F_JOB);
    }

    public function nonce(): ?string
    {
        return $this->readMarker()['nonce'] ?? null;
    }

    public function markerPath(): string
    {
        return $this->path($this->storageDir, self::F_MARCADOR);
    }

    public function order(string $nome): bool
    {
        return @file_put_contents($this->path($this->filesDir, $nome), "1\n") !== false;
    }

    public function configProblem(): ?string
    {
        foreach ([self::SCRIPT_WORKER, self::SCRIPT_BOOTSTRAP] as $nome) {
            $caminho = $this->scriptPath($nome);

            if (!is_file($caminho)) {
                return sprintf(
                    'Instalação incompleta: %s não foi encontrado em %s. '
                    . 'Sem ele não há o que executar — o motor do Windows mora no próprio '
                    . 'repositório, e este arquivo faz parte dele.',
                    $nome,
                    $this->winDir
                );
            }
        }

        return null;
    }

    private function scriptPath(string $nome): string
    {
        return rtrim($this->winDir, '\\/') . DIRECTORY_SEPARATOR . $nome;
    }

    /**
     * @return array{php_pid: int, ps_pid: int, nonce: string, provado_em: string}|null
     */
    private function readMarker(): ?array
    {
        $caminho = $this->markerPath();

        if (!is_file($caminho)) {
            return null;
        }

        $raw = @file_get_contents($caminho);

        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $data = json_decode($raw, true);

        if (!is_array($data)) {
            return null;
        }

        $phpPid = $data['php_pid'] ?? null;
        $psPid  = $data['ps_pid'] ?? null;
        $nonce  = $data['nonce'] ?? null;
        $em     = $data['provado_em'] ?? null;

        if (!is_int($phpPid) || !is_int($psPid) || !is_string($nonce) || !is_string($em)) {
            return null;
        }

        if ($phpPid <= 0 || $psPid <= 0 || $nonce === '') {
            return null;
        }

        return ['php_pid' => $phpPid, 'ps_pid' => $psPid, 'nonce' => $nonce, 'provado_em' => $em];
    }

    private function writeMarker(int $psPid, string $nonce): bool
    {
        $json = json_encode([
            'php_pid'    => getmypid(),
            'ps_pid'     => $psPid,
            'nonce'      => $nonce,
            'provado_em' => gmdate(DATE_ATOM),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (!is_string($json)) {
            return false;
        }

        if (!is_dir($this->storageDir) && !@mkdir($this->storageDir, 0o775, true) && !is_dir($this->storageDir)) {
            return false;
        }

        return @file_put_contents($this->markerPath(), $json . "\n", LOCK_EX) !== false;
    }

    private function heartbeatAge(): ?int
    {
        $caminho = $this->path($this->filesDir, self::F_HEARTBEAT);

        clearstatcache(true, $caminho);

        if (!is_file($caminho)) {
            return null;
        }

        $mt = filemtime($caminho);

        return is_int($mt) ? max(0, time() - $mt) : null;
    }

    private function cleanupChannel(): void
    {
        foreach ([self::F_ORDEM_DESLIGAR, self::F_ORDEM_CANCELAR, self::F_JOB, self::F_HEARTBEAT, self::F_MANIFESTO] as $nome) {
            @unlink($this->path($this->filesDir, $nome));
        }

        foreach (glob($this->filesDir . DIRECTORY_SEPARATOR . 'win-prova-*.txt') ?: [] as $antiga) {
            @unlink($antiga);
        }

        foreach (glob($this->filesDir . DIRECTORY_SEPARATOR . self::ONESHOT_PREFIX . '*') ?: [] as $antiga) {
            @unlink($antiga);
        }
    }

    private function launcherBody(string $nonce, string $manifestoSha256): string
    {
        $argLine = implode(' ', [
            '-NoProfile -NonInteractive -ExecutionPolicy Bypass',
            '-File ' . self::quoted($this->scriptPath(self::SCRIPT_WORKER)),
            '-ParentPid ' . getmypid(),
            '-Dir ' . self::quoted($this->filesDir),
            '-Nonce ' . $nonce,
            '-ManifestoSha256 ' . $manifestoSha256,
        ]);

        return sprintf(
            'Start-Process -FilePath %s -Verb RunAs -WindowStyle Hidden -ArgumentList %s',
            PsScriptBuilder::literal('powershell.exe'),
            PsScriptBuilder::literal($argLine)
        );
    }

    private static function quoted(string $caminho): string
    {
        return '"' . rtrim($caminho, '\\/') . '"';
    }

    private function path(string $dir, string $nome): string
    {
        return rtrim($dir, '\\/') . DIRECTORY_SEPARATOR . $nome;
    }
}
