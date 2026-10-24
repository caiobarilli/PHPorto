<?php

declare(strict_types=1);

namespace App\Win;

use RuntimeException;

final class JobChannel
{
    private const POLL_US = 200000;

    private const CANCEL_GRACE_S = 3;

    public const ID_PATTERN = '/^[0-9a-f]{12}$/D';

    public const MAX_IMPORTADAS = 500;

    private const MAX_IMPORTADAS_BYTES = 65536;

    /** @var array<string, true>|null */
    private ?array $preexisting = null;

    private ?string $expectedAcao = null;

    /** @var list<string> */
    private array $cleanupFailures = [];

    private const UNLINK_TENTATIVAS = 5;

    private const UNLINK_PAUSA_US = 100000;

    /** @var \Closure(string): bool */
    private readonly \Closure $unlinker;

    /** @var \Closure(int): void */
    private readonly \Closure $sleeper;

    /**
     * @param (\Closure(string): bool)|null $unlinker
     * @param (\Closure(int): void)|null $sleeper
     */
    public function __construct(
        private readonly string $filesDir,
        ?\Closure $unlinker = null,
        ?\Closure $sleeper = null,
    ) {
        $this->unlinker = $unlinker ?? static fn (string $caminho): bool => unlink($caminho);
        $this->sleeper  = $sleeper ?? static function (int $us): void {
            usleep($us);
        };
    }

    /**
     * @param array<string, string|int|bool> $params
     * @throws RuntimeException
     */
    public function send(WinAction $acao, array $params, string $nonce): void
    {
        if (!is_dir($this->filesDir)) {
            throw new RuntimeException(
                'A pasta de trabalho não existe: ' . $this->filesDir
                . '. O PowerShell elevado provavelmente nunca subiu.'
            );
        }

        $this->preexisting  = $this->doneIds();
        $this->expectedAcao = $acao->value;

        $ordem = $this->path(Elevation::F_ORDEM_CANCELAR);

        if (is_file($ordem)) {
            @unlink($ordem);
        }

        $payload = json_encode([
            'nonce'  => $nonce,
            'acao'   => $acao->value,
            'params' => $params === [] ? new \stdClass() : $params,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT);

        if (!is_string($payload)) {
            throw new RuntimeException('Não foi possível montar o arquivo de trabalho.');
        }

        if (@file_put_contents($this->path(Elevation::F_JOB), $payload, LOCK_EX) === false) {
            throw new RuntimeException(
                'Não foi possível escrever o arquivo de trabalho em ' . $this->filesDir . '.'
            );
        }
    }

    public function collect(
        int $timeoutSeconds,
        ?string $id = null,
        string $cancelOrder = Elevation::F_ORDEM_CANCELAR,
    ): PsResult {
        if ($id !== null && !self::validId($id)) {
            throw new RuntimeException('Id de execução fora do formato: ' . $id);
        }

        set_time_limit($timeoutSeconds + Elevation::TIME_LIMIT_MARGIN_S);

        $started = microtime(true);
        $done    = null;

        while ((microtime(true) - $started) < $timeoutSeconds) {
            $done = $this->findDone($id);

            if ($done !== null) {
                break;
            }

            usleep(self::POLL_US);
        }

        if ($done === null) {
            $this->order($cancelOrder);

            $limite = microtime(true) + self::CANCEL_GRACE_S;

            while (microtime(true) < $limite && $done === null) {
                $done = $this->findDone($id);
                usleep(self::POLL_US);
            }

            $durationMs = (int) round((microtime(true) - $started) * 1000);

            if ($done === null) {
                $this->forgetSend();

                return new PsResult(
                    output: sprintf(
                        "[phporto] TIMEOUT: %ds estourados. Ordem de cancelar deixada, mas o "
                        . "PowerShell elevado não confirmou em %ds — a ação pode ainda estar rodando.\n",
                        $timeoutSeconds,
                        self::CANCEL_GRACE_S
                    ),
                    exitCode: null,
                    timedOut: true,
                    durationMs: $durationMs,
                );
            }
        }

        $durationMs = (int) round((microtime(true) - $started) * 1000);
        [$saida, $truncada] = $this->readOutput($done['id']);

        $cancelada = $done['nota'] === 'cancelado';

        if ($cancelada) {
            $saida = self::withNewline($saida) . sprintf(
                "[phporto] TIMEOUT: %ds estourados, ação cancelada pelo PowerShell elevado.\n",
                $timeoutSeconds
            );
        } elseif ($done['nota'] === 'recusado') {
            $saida = self::withNewline($saida)
                . "[phporto] A allowlist do PowerShell elevado recusou esta ação.\n";
        }

        $falhas = $this->discardPair($done['id']);

        if ($falhas !== []) {
            $this->noteImported($done['id']);

            $saida = self::withNewline($saida) . sprintf(
                "[phporto] Não foi possível apagar %s da pasta protegida; o worker apaga na próxima execução.\n",
                implode(', ', $falhas)
            );
        }

        $this->forgetSend();

        return new PsResult(
            output: $saida,
            exitCode: $done['exit'],
            timedOut: $cancelada,
            durationMs: $durationMs,
            truncated: $truncada,
        );
    }

    /**
     * @param array<string, string|int|bool> $params
     * @throws RuntimeException
     */
    public function dispatch(WinAction $acao, array $params, string $nonce, int $timeoutSeconds): PsResult
    {
        $this->send($acao, $params, $nonce);

        return $this->collect($timeoutSeconds);
    }

    private const NOTA_RECOLHIDA = '[phporto] Execução recuperada depois do fato: ela terminou no '
        . 'PowerShell elevado, mas o servidor saiu no meio da espera e a linha nunca foi gravada. '
        . "Registrada agora, com a hora real de término.\n";

    /**
     * @return list<OrphanRun>
     */
    public function collectOrphans(): array
    {
        clearstatcache();

        $orfas      = [];
        $importadas = $this->importedIds();

        foreach (glob($this->resultPath('win-done-*.json')) ?: [] as $arquivo) {
            $done = self::parseDone($arquivo);

            if ($done === null || isset($importadas[$done['id']])) {
                continue;
            }

            if (!is_file($this->resultPath('win-out-' . $done['id'] . '.txt'))) {
                continue;
            }

            [$saida, $truncada] = $this->readOutput($done['id']);

            $orfas[] = new OrphanRun(
                id: $done['id'],
                acao: $done['acao'],
                params: $done['params'],
                result: new PsResult(
                    output: self::NOTA_RECOLHIDA . $saida,
                    exitCode: $done['exit'],
                    timedOut: $done['nota'] === 'cancelado',
                    durationMs: $done['ms'],
                    truncated: $truncada,
                ),
                finishedAt: $done['fim'],
            );
        }

        return $orfas;
    }

    public function discardOrphan(string $id): bool
    {
        if (!self::validId($id)) {
            return false;
        }

        $sobrou = false;

        foreach (['win-done-' . $id . '.json', 'win-out-' . $id . '.txt'] as $nome) {
            $caminho = $this->resultPath($nome);

            if (is_file($caminho) && !$this->tryUnlink($caminho)) {
                $sobrou = true;
            }
        }

        return !$sobrou || $this->noteImported($id);
    }

    /**
     * @return array<string, true>
     */
    public function importedIds(): array
    {
        $caminho = $this->path(Elevation::F_IMPORTADAS);

        if (!is_file($caminho)) {
            return [];
        }

        $raw = @file_get_contents($caminho, false, null, 0, self::MAX_IMPORTADAS_BYTES);

        return is_string($raw) ? self::parseImported($raw) : [];
    }

    private function noteImported(string $id): bool
    {
        set_error_handler(static fn (): bool => true);

        try {
            $handle = fopen($this->path(Elevation::F_IMPORTADAS), 'c+');
        } finally {
            restore_error_handler();
        }

        if ($handle === false) {
            return false;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return false;
            }

            $raw = stream_get_contents($handle, self::MAX_IMPORTADAS_BYTES);
            $ids = self::parseImported(is_string($raw) ? $raw : '');

            unset($ids[$id]);

            $vivas = array_filter(
                array_keys($ids),
                fn (string $velha): bool => is_file($this->resultPath('win-done-' . $velha . '.json'))
                    || is_file($this->resultPath('win-out-' . $velha . '.txt'))
            );

            $lista   = array_slice([...$vivas, $id], -self::MAX_IMPORTADAS);
            $gravado = ftruncate($handle, 0)
                && rewind($handle)
                && fwrite($handle, implode("\n", $lista) . "\n") !== false
                && fflush($handle);

            flock($handle, LOCK_UN);

            return $gravado;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return array<string, true>
     */
    private static function parseImported(string $raw): array
    {
        $ids = [];

        foreach (preg_split('/\r?\n/', $raw) ?: [] as $linha) {
            if (self::validId($linha)) {
                $ids[$linha] = true;
            }
        }

        return $ids;
    }

    /**
     * @return array{id: string, exit: int|null, ms: int, nota: string, acao: string, params: array<string, string|int|bool>, fim: string|null}|null
     */
    private function findDone(?string $id = null): ?array
    {
        clearstatcache();

        if ($id !== null) {
            $arquivo = $this->resultPath('win-done-' . $id . '.json');

            return is_file($arquivo) ? self::parseDone($arquivo) : null;
        }

        foreach (glob($this->resultPath('win-done-*.json')) ?: [] as $arquivo) {
            $done = self::parseDone($arquivo);

            if ($done === null || isset($this->preexisting[$done['id']])) {
                continue;
            }

            if (
                $this->expectedAcao !== null
                && $done['acao'] !== $this->expectedAcao
                && !($done['acao'] === '' && $done['nota'] === 'recusado')
            ) {
                continue;
            }

            return $done;
        }

        return null;
    }

    /**
     * @return array{id: string, exit: int|null, ms: int, nota: string, acao: string, params: array<string, string|int|bool>, fim: string|null}|null
     */
    private static function parseDone(string $arquivo): ?array
    {
        $raw = @file_get_contents($arquivo);

        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $data = json_decode($raw, true);

        if (!is_array($data)) {
            return null;
        }

        $id = $data['id'] ?? null;

        if (!is_string($id) || !self::validId($id) || basename($arquivo) !== 'win-done-' . $id . '.json') {
            return null;
        }

        $exit = $data['exit'] ?? null;
        $ms   = $data['ms'] ?? 0;
        $nota = $data['nota'] ?? '';
        $acao = $data['acao'] ?? '';
        $fim  = $data['fim'] ?? null;

        $params = [];

        if (isset($data['params']) && is_array($data['params'])) {
            foreach ($data['params'] as $nome => $valor) {
                if (!is_string($nome)) {
                    continue;
                }

                if (is_string($valor) || is_int($valor) || is_bool($valor)) {
                    $params[$nome] = $valor;
                } elseif (is_array($valor) && array_is_list($valor) && array_filter($valor, 'is_string') === $valor) {
                    $params[$nome] = implode(',', $valor);
                }
            }
        }

        return [
            'id'     => $id,
            'exit'   => is_int($exit) ? $exit : null,
            'ms'     => is_int($ms) ? $ms : 0,
            'nota'   => is_string($nota) ? $nota : '',
            'acao'   => is_string($acao) ? $acao : '',
            'params' => $params,
            'fim'    => is_string($fim) && trim($fim) !== '' ? $fim : null,
        ];
    }

    /**
     * @return array{0: string, 1: bool}
     */
    private function readOutput(string $id): array
    {
        return PsRunner::readBounded($this->resultPath('win-out-' . $id . '.txt'));
    }

    /**
     * @return array<string, true>
     */
    private function doneIds(): array
    {
        clearstatcache();

        $ids = [];

        foreach (glob($this->resultPath('win-done-*.json')) ?: [] as $arquivo) {
            $id = substr(basename($arquivo), strlen('win-done-'), -strlen('.json'));

            if (self::validId($id)) {
                $ids[$id] = true;
            }
        }

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function discardPair(string $id): array
    {
        $falhas = [];

        foreach (['win-done-' . $id . '.json', 'win-out-' . $id . '.txt'] as $nome) {
            $caminho = $this->resultPath($nome);

            if (is_file($caminho) && !$this->tryUnlink($caminho)) {
                $falhas[] = $nome;
            }
        }

        $this->cleanupFailures = $falhas;

        return $falhas;
    }

    /**
     * @return list<string>
     */
    public function cleanupFailures(): array
    {
        return $this->cleanupFailures;
    }

    private function tryUnlink(string $caminho): bool
    {
        for ($tentativa = 1; $tentativa <= self::UNLINK_TENTATIVAS; $tentativa++) {
            if ($tentativa > 1) {
                ($this->sleeper)(self::UNLINK_PAUSA_US);
            }

            set_error_handler(static fn (): bool => true);

            try {
                if (($this->unlinker)($caminho)) {
                    return true;
                }
            } finally {
                restore_error_handler();
            }

            clearstatcache(true, $caminho);

            if (!file_exists($caminho)) {
                return true;
            }
        }

        return false;
    }

    private function forgetSend(): void
    {
        $this->preexisting  = null;
        $this->expectedAcao = null;
    }

    public static function validId(string $id): bool
    {
        return preg_match(self::ID_PATTERN, $id) === 1;
    }

    private function order(string $nome): void
    {
        @file_put_contents($this->path($nome), "1\n");
    }

    private function path(string $nome): string
    {
        return rtrim($this->filesDir, '\\/') . DIRECTORY_SEPARATOR . $nome;
    }

    private function resultPath(string $nome): string
    {
        return $this->path(Elevation::DIR_PROTEGIDA) . DIRECTORY_SEPARATOR . $nome;
    }

    private static function withNewline(string $s): string
    {
        return $s === '' || str_ends_with($s, "\n") ? $s : $s . "\n";
    }
}
