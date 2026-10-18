<?php

declare(strict_types=1);

namespace App\Win;

use Closure;
use RuntimeException;
use stdClass;

final class OneShot
{
    public const MAX_ARGLINE = 1900;

    public const MAX_TIMEOUT_S = 3600;

    public const PRAZO_FOLGA_S = 15;

    private const EXIT_CANCELADO = 2;

    private const POLL_US = 100000;

    /** @var Closure(string, int): PsResult */
    private readonly Closure $runLauncher;

    /**
     * @param (Closure(string, int): PsResult)|null $runLauncher
     * @param int $startTimeout
     */
    public function __construct(
        private readonly string $filesDir,
        private readonly string $winDir,
        private readonly Elevation $elevation,
        private readonly UacPolicy $uac,
        ?Closure $runLauncher = null,
        private readonly int $startTimeout = Elevation::ONESHOT_START_S,
    ) {
        $this->runLauncher = $runLauncher
            ?? fn (string $lancador, int $timeout): PsResult => (new PsRunner($this->filesDir))->run($lancador, $timeout);
    }

    public function blockingReason(): ?string
    {
        return $this->elevation->configProblem() ?? $this->uac->blockingReason();
    }

    /**
     * @param array<string, string|int|bool> $params
     * @throws RuntimeException
     */
    public function dispatch(WinAction $acao, array $params, int $timeoutSeconds): PsResult
    {
        if (!$acao->isSensitive($params)) {
            throw new RuntimeException('Só ação sensível passa pelo uso único.');
        }

        if (!is_dir($this->filesDir)) {
            throw new RuntimeException('A pasta de trabalho não existe: ' . $this->filesDir . '.');
        }

        $bloqueio = $this->blockingReason();

        if ($bloqueio !== null) {
            throw new RuntimeException($bloqueio);
        }

        $timeout  = min($timeoutSeconds, self::MAX_TIMEOUT_S);
        $id       = bin2hex(random_bytes(6));
        $pedido   = $this->path(Elevation::ONESHOT_PREFIX . $id . '.json');
        $estado   = $this->path(Elevation::ONESHOT_PREFIX . $id . '.estado');
        $lancador = $this->path(Elevation::ONESHOT_PREFIX . 'launcher-' . $id . '.ps1');

        try {
            $argLine = self::argLine(self::stub(
                $this->workerPath(),
                $this->workerSha(),
                $pedido,
                $this->writeRequest($pedido, $id, $acao, $params, $timeout),
            ));

            if (strlen($argLine) > self::MAX_ARGLINE) {
                throw new RuntimeException(sprintf(
                    'O caminho do projeto é longo demais para o UAC (%d caracteres na linha de comando, teto de %d). '
                    . 'Nada foi executado. Mova o projeto para uma pasta de caminho mais curto.',
                    strlen($argLine),
                    self::MAX_ARGLINE
                ));
            }

            PsScriptBuilder::write($lancador, self::launcherBody($argLine));

            $this->consent($lancador);
            $this->awaitStart($estado);

            return (new JobChannel($this->filesDir))
                ->collect($timeout, $id, Elevation::F_ORDEM_CANCELAR . '-' . $id);
        } finally {
            foreach ([$pedido, $estado, $lancador] as $arquivo) {
                if (is_file($arquivo)) {
                    @unlink($arquivo);
                }
            }
        }
    }

    public static function stub(string $worker, string $workerSha, string $pedido, string $pedidoSha): string
    {
        return '$b=[IO.File]::ReadAllBytes(' . PsScriptBuilder::literal($worker) . ');'
            . 'if((Get-FileHash -InputStream ([IO.MemoryStream]::new($b)) -Algorithm SHA256).Hash -ne '
            . PsScriptBuilder::literal($workerSha) . '){exit 97};'
            . '$i=if($b[0] -eq 239){3}else{0};'
            . '. ([scriptblock]::Create([Text.Encoding]::UTF8.GetString($b, $i, $b.Length - $i)))'
            . ' -Pedido ' . PsScriptBuilder::literal($pedido)
            . ' -PedidoSha256 ' . PsScriptBuilder::literal($pedidoSha);
    }

    public static function argLine(string $stub): string
    {
        return '-NoProfile -NonInteractive -ExecutionPolicy Bypass -WindowStyle Hidden -EncodedCommand '
            . base64_encode(mb_convert_encoding($stub, 'UTF-16LE', 'UTF-8'));
    }

    public static function launcherBody(string $argLine): string
    {
        return implode("\n", [
            'try {',
            '    $p = Start-Process -FilePath ' . PsScriptBuilder::literal('powershell.exe')
                . ' -Verb RunAs -WindowStyle Hidden -PassThru -ArgumentList ' . PsScriptBuilder::literal($argLine),
            "    'PID=' + \$p.Id",
            '} catch {',
            '    $e = $_.Exception',
            '    while ($null -ne $e.InnerException) { $e = $e.InnerException }',
            "    if (\$e.NativeErrorCode -eq 1223) { 'CANCELADO'; exit " . self::EXIT_CANCELADO . ' }',
            "    'ERRO=' + \$_.Exception.Message; exit 1",
            '}',
        ]);
    }

    /**
     * @param array<string, string|int|bool> $params
     */
    private function writeRequest(string $caminho, string $id, WinAction $acao, array $params, int $timeout): string
    {
        $agora     = time();
        $manifesto = WinManifest::of($this->winDir);

        $json = json_encode([
            'v'         => 1,
            'id'        => $id,
            'acao'      => $acao->value,
            'params'    => $params === [] ? new stdClass() : $params,
            'php_pid'   => getmypid(),
            'raiz_win'  => $this->winDir,
            'dir'       => $this->filesDir,
            'criado_em' => $agora,
            'expira_em' => $agora + Elevation::ONESHOT_CONSENT_S + Elevation::ONESHOT_START_S,
            'prazo_s'   => $timeout + self::PRAZO_FOLGA_S,
            'manifesto' => $manifesto === [] ? new stdClass() : $manifesto,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (!is_string($json) || @file_put_contents($caminho, $json, LOCK_EX) === false) {
            throw new RuntimeException('Não foi possível gravar o pedido em ' . $this->filesDir . '.');
        }

        return hash('sha256', $json);
    }

    /**
     * @throws RuntimeException
     */
    private function consent(string $lancador): void
    {
        set_time_limit(Elevation::ONESHOT_CONSENT_S + Elevation::TIME_LIMIT_MARGIN_S);

        $r     = ($this->runLauncher)($lancador, Elevation::ONESHOT_CONSENT_S);
        $saida = trim(PsScriptBuilder::stripBom($r->output));

        if ($r->timedOut) {
            throw new RuntimeException(sprintf(
                'Ninguém respondeu ao UAC em %d s. Nada foi executado: o pedido foi apagado, e aceitar o prompt agora não roda nada.',
                Elevation::ONESHOT_CONSENT_S
            ));
        }

        if ($r->exitCode === self::EXIT_CANCELADO || str_contains($saida, 'CANCELADO')) {
            throw new RuntimeException('Você recusou o UAC; nada foi executado.');
        }

        if (preg_match('/^PID=\d+/m', $saida) !== 1) {
            $erro = preg_match('/^ERRO=(.*)$/m', $saida, $m) === 1 ? trim($m[1]) : $saida;

            throw new RuntimeException(
                'O Windows não abriu o PowerShell elevado. Nada foi executado.'
                . ($erro === '' ? '' : ' O PowerShell disse: ' . $erro)
            );
        }
    }

    /**
     * @throws RuntimeException
     */
    private function awaitStart(string $estado): void
    {
        set_time_limit($this->startTimeout + Elevation::TIME_LIMIT_MARGIN_S);

        $limite = microtime(true) + $this->startTimeout;

        do {
            clearstatcache(true, $estado);

            $lido = is_file($estado) ? @file_get_contents($estado) : false;
            $lido = is_string($lido) ? trim(PsScriptBuilder::stripBom($lido)) : '';

            if (str_starts_with($lido, 'ACEITO=')) {
                return;
            }

            if (str_starts_with($lido, 'ERRO=')) {
                throw new RuntimeException('O PowerShell elevado recusou: ' . trim(substr($lido, 5)) . ' Nada foi executado.');
            }

            usleep(self::POLL_US);
        } while (microtime(true) < $limite);

        throw new RuntimeException(sprintf(
            'O PowerShell elevado abriu e não respondeu em %d s — o worker.ps1 mudou depois do clique? '
            . 'O pedido foi apagado. Se ele ainda assim chegou a rodar, o resultado aparece no histórico como execução recuperada.',
            $this->startTimeout
        ));
    }

    private function workerPath(): string
    {
        return $this->path(Elevation::SCRIPT_WORKER, $this->winDir);
    }

    private function workerSha(): string
    {
        $hash = @hash_file('sha256', $this->workerPath());

        if (!is_string($hash)) {
            throw new RuntimeException('Não foi possível ler ' . $this->workerPath() . '.');
        }

        return $hash;
    }

    private function path(string $nome, ?string $dir = null): string
    {
        return rtrim($dir ?? $this->filesDir, '\\/') . DIRECTORY_SEPARATOR . $nome;
    }
}
