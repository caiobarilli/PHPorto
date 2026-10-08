<?php

declare(strict_types=1);

namespace App\Win;

use Closure;
use RuntimeException;
use stdClass;

/**
 * A ação sensível: um prompt de UAC, um job, e o processo elevado sai.
 *
 * POR QUE EXISTE: com o PowerShell elevado longo de pé, qualquer processo do
 * usuário que lesse o nonce do marcador (o WSL inclusive) mandava job para
 * ele — e conseguia, sem prompt nenhum, instalar qualquer pacote do winget,
 * ligar o RDP ou abrir porta no firewall. O worker longo agora RECUSA essas
 * ações (ver WinAction::SENSITIVE e o $SENSIVEIS do worker.ps1), e cada uma
 * passa por aqui: um powershell.exe novo, aberto por Start-Process -Verb RunAs,
 * ou seja, com um prompt de UAC só para ela.
 *
 * O FLUXO, de ponta a ponta:
 *
 *   1. o PHP gera o id (e por isso sabe exatamente qual conclusão esperar) e
 *      grava o PEDIDO em files/win-oneshot-<id>.json: o job e o manifesto de
 *      src/Win tirado AGORA — para ação sensível, o momento de confiar em
 *      src/Win é o clique mais o prompt, e não a ligação de horas atrás;
 *   2. o SHA-256 desse arquivo e o do worker.ps1 vão na linha de comando,
 *      dentro de um stub em -EncodedCommand. O texto do usuário NUNCA entra
 *      ali: só caminhos que este código escreveu e dois hashes;
 *   3. o lançador roda em integridade Média e bloqueia enquanto o prompt está
 *      na tela. Recusado, sem resposta ou com erro, o pedido é apagado, e
 *      aceitar o prompt depois não roda nada;
 *   4. o stub confere o worker.ps1, o worker confere e CONSOME o pedido,
 *      prepara a pasta protegida e escreve ACEITO no .estado;
 *   5. o resultado chega pela pasta protegida, pelo id, como no worker longo.
 *
 * NÃO DEPENDE DO POWERSHELL ELEVADO LONGO: o pedido é autossuficiente. Só
 * depende do checkout (Elevation::configProblem) e de a política do UAC
 * garantir um prompt (UacPolicy).
 */
final class OneShot
{
    /**
     * Teto da linha de comando do processo elevado, em caracteres.
     *
     * O -Verb RunAs passa pelo ShellExecuteEx, que corta em ~2048 sem avisar.
     * Com folga: acima disto a ação é recusada antes de abrir o prompt, em
     * vez de chegar truncada do outro lado.
     */
    public const MAX_ARGLINE = 1900;

    /**
     * O maior timeout que o pedido carrega. O worker recusa prazo_s fora de
     * 60-3615 s; um PHPORTO_WINUTIL_TIMEOUT maior que isto vale até aqui para
     * a ação sensível, em vez de a tornar inalcançável.
     */
    public const MAX_TIMEOUT_S = 3600;

    /** Folga do prazo do processo elevado sobre o timeout da ação. */
    public const PRAZO_FOLGA_S = 15;

    /** Código do lançador quando a pessoa recusou o prompt (ERROR_CANCELLED). */
    private const EXIT_CANCELADO = 2;

    private const POLL_US = 100000;

    /** @var Closure(string, int): PsResult */
    private readonly Closure $runLauncher;

    /**
     * @param (Closure(string, int): PsResult)|null $runLauncher roda o
     *        lançador e devolve a saída dele; injetável para teste, e por
     *        padrão o PsRunner
     * @param int $startTimeout quanto esperar o ACEITO; só o teste muda
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

    /** O que impede uma ação sensível agora, ou null. */
    public function blockingReason(): ?string
    {
        return $this->elevation->configProblem() ?? $this->uac->blockingReason();
    }

    /**
     * Abre o prompt, roda a ação e devolve a saída dela.
     *
     * @param array<string, string|int|bool> $params já validados pela WinAction
     *
     * @throws RuntimeException com a frase da tela, quando nada foi executado
     */
    public function dispatch(WinAction $acao, array $params, int $timeoutSeconds): PsResult
    {
        if (!$acao->isSensitive($params)) {
            throw new RuntimeException('Só ação sensível passa pelo uso único.');
        }

        if (!is_dir($this->filesDir)) {
            throw new RuntimeException('A pasta de trabalho não existe: ' . $this->filesDir . '.');
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
            // O pedido sai em todo caminho: se o prompt for aceito depois, não
            // há o que rodar. Os resultados, na pasta protegida, saem pelo
            // collect() — ou ficam para o recolhimento de órfãs.
            foreach ([$pedido, $estado, $lancador] as $arquivo) {
                if (is_file($arquivo)) {
                    @unlink($arquivo);
                }
            }
        }
    }

    /**
     * O stub que o processo elevado roda: confere o worker.ps1 e chama.
     *
     * Lê os bytes do worker UMA vez, confere o SHA-256 deles e roda o texto
     * desses mesmos bytes — nada é carregado pelo caminho depois de conferido.
     * Arquivo trocado depois do clique sai com 97, sem rodar nada.
     *
     * DOT-SOURCE (o ponto), e não &: medido no pwsh, com & o scriptblock ganha
     * um escopo próprio, e o $script:filho que as funções do worker gravam
     * deixa de ser o $filho que o código de cima lê. Com o ponto, os dois são o
     * mesmo escopo, como quando o worker roda por -File.
     *
     * Público para o teste conferir o texto; o OneShot.Tests.ps1 roda um igual.
     */
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

    /**
     * A linha de comando do processo elevado: flags fixos e o stub em base64.
     *
     * -EncodedCommand é UTF-16LE em base64. O alfabeto não tem espaço nem
     * aspa, então um caminho com espaço deixa de partir a linha.
     */
    public static function argLine(string $stub): string
    {
        return '-NoProfile -NonInteractive -ExecutionPolicy Bypass -WindowStyle Hidden -EncodedCommand '
            . base64_encode(mb_convert_encoding($stub, 'UTF-16LE', 'UTF-8'));
    }

    /**
     * O lançador, que roda em integridade Média e pede a elevação.
     *
     * O -ArgumentList vai como UMA string: como array, o 5.1 junta os itens
     * sem aspas. 1223 é ERROR_CANCELLED, o "Não" do prompt; ele vem como
     * exceção interna, e o laço desce até ela.
     */
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
     * Grava o pedido e devolve o SHA-256 dos bytes gravados.
     *
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
            // Objeto sempre, e nunca lista: ver o JSON_FORCE_OBJECT do
            // JobChannel::send(), o mesmo defeito medido.
            'params'    => $params === [] ? new stdClass() : $params,
            'php_pid'   => getmypid(),
            'raiz_win'  => $this->winDir,
            'dir'       => $this->filesDir,
            'criado_em' => $agora,
            // Depois disto o pedido não vale, mesmo com o prompt aceito.
            'expira_em' => $agora + Elevation::ONESHOT_CONSENT_S + Elevation::ONESHOT_START_S,
            // O teto de vida do processo elevado, se o PHP sumir sem cancelar.
            'prazo_s'   => $timeout + self::PRAZO_FOLGA_S,
            'manifesto' => $manifesto === [] ? new stdClass() : $manifesto,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (!is_string($json) || @file_put_contents($caminho, $json, LOCK_EX) === false) {
            throw new RuntimeException('Não foi possível gravar o pedido em ' . $this->filesDir . '.');
        }

        // O hash é dos bytes gravados: é ele que o worker confere.
        return hash('sha256', $json);
    }

    /**
     * Roda o lançador e espera a resposta ao prompt.
     *
     * @throws RuntimeException quando o prompt foi recusado, ficou sem
     *                          resposta, ou o Windows não abriu o processo
     */
    private function consent(string $lancador): void
    {
        // ANTES do lançador: o Start-Process -Verb RunAs bloqueia enquanto o
        // prompt está na tela, e no Windows o max_execution_time do cli-server
        // conta tempo de relógio. Ver Elevation::TIME_LIMIT_MARGIN_S.
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
     * Espera o processo elevado dizer que aceitou o pedido.
     *
     * @throws RuntimeException quando ele recusou, ou não deu sinal
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
