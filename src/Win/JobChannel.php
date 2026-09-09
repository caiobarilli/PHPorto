<?php

declare(strict_types=1);

namespace App\Win;

use RuntimeException;

/**
 * O canal de trabalho com o worker elevado: manda a ação e espera a saída.
 *
 * É ARQUIVO E NÃO PIPE, por medição. O pipe nomeado até abre do lado do PHP
 * (fopen em 'r+b' abriu na primeira tentativa, round-trip de 20 ms), mas o
 * pipe criado pelo processo elevado nasce com rótulo de integridade Alta e
 * política No-Write-Up, e o PHP em integridade Média é recusado com
 * "Permission denied" — e montar um SACL explícito para contornar exige
 * privilégio que o próprio elevado não tem. Por arquivo funciona nas duas
 * direções.
 *
 * O QUE VIAJA É {acao, params}, NUNCA POWERSHELL. Quem monta a chamada é o
 * worker, contra a allowlist dele. Este lado não tem como pedir "execute este
 * texto" nem que quisesse.
 *
 * SEPARADO EM send() E collect() de propósito, e não só por gosto de testar:
 * a espera é a parte que pode ser cancelada, e ter as duas metades explícitas
 * é o que deixa o cancelamento ser uma decisão de quem espera, não um efeito
 * escondido dentro de um método que faz tudo.
 */
final class JobChannel
{
    /** Intervalo de sondagem do arquivo de conclusão. */
    private const POLL_US = 200000;

    /**
     * Espera pelo arquivo de conclusão DEPOIS de mandar a ordem de cancelar.
     *
     * O worker obedece no próprio laço de 500 ms — medido em 975 ms entre a
     * ordem e a conclusão. Três segundos é folga; passado isso, a saída
     * parcial que houver é o que se registra.
     */
    private const CANCEL_GRACE_S = 3;

    public function __construct(
        private readonly string $filesDir,
    ) {
    }

    /**
     * Enfileira a ação. O worker pega no próximo tique.
     *
     * @param array<string, string|int|bool> $params já validados pela WinAction
     *
     * @throws RuntimeException se o arquivo de trabalho não puder ser escrito
     */
    public function send(WinAction $acao, array $params, string $nonce): void
    {
        // Checa a pasta antes de escrever: sem ela o file_put_contents falha
        // com warning e uma mensagem que fala de caminho, não do problema. E
        // não criamos a pasta aqui de propósito — se files/ não existe, quem
        // deveria tê-la criado é o Elevation, ao ligar; criá-la agora
        // esconderia que o worker nunca subiu.
        if (!is_dir($this->filesDir)) {
            throw new RuntimeException(
                'A pasta de trabalho não existe: ' . $this->filesDir
                . '. O PowerShell elevado provavelmente nunca subiu.'
            );
        }

        $this->cleanupArtifacts();

        // A ordem de cancelar é apagada AQUI, e só aqui: uma ordem esquecida
        // de uma ação anterior cancelaria esta no primeiro tique do worker,
        // antes de ela chegar a executar.
        $ordem = $this->path(Elevation::F_ORDEM_CANCELAR);

        if (is_file($ordem)) {
            @unlink($ordem);
        }

        // JSON_FORCE_OBJECT nos params, e isto é correção de defeito medido:
        // um array PHP vazio virava "[]" no JSON, e PSObject.Properties de um
        // array vazio no PowerShell expõe Count e Length — que chegavam à
        // allowlist do worker como parâmetros inventados e faziam uma ação
        // legítima sem parâmetros (audit) ser RECUSADA por "parametro fora da
        // allowlist: 'Count'".
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

    /**
     * Espera a conclusão e devolve a saída.
     *
     * NO TIMEOUT NÃO MATA NINGUÉM: o processo do outro lado está em
     * integridade Alta, e taskkill do PHP contra ele devolve "Acesso negado"
     * (medido, rc=128). Escreve a ordem de cancelar, e é o worker que mata o
     * próprio filho.
     */
    public function collect(int $timeoutSeconds): PsResult
    {
        // Ver TIME_LIMIT_MARGIN_S na Elevation: o php -S corta a requisição em
        // 30 s por padrão (SAPI cli-server usa o php.ini, não o 0 do CLI), e
        // sem levantar isso NENHUMA ação longa terminaria. A guarda do PHP
        // continua existindo, só acima da nossa.
        set_time_limit($timeoutSeconds + Elevation::TIME_LIMIT_MARGIN_S);

        $started = microtime(true);
        $done    = null;

        while ((microtime(true) - $started) < $timeoutSeconds) {
            $done = $this->findDone();

            if ($done !== null) {
                break;
            }

            usleep(self::POLL_US);
        }

        if ($done === null) {
            $this->order(Elevation::F_ORDEM_CANCELAR);

            $limite = microtime(true) + self::CANCEL_GRACE_S;

            while (microtime(true) < $limite && $done === null) {
                $done = $this->findDone();
                usleep(self::POLL_US);
            }

            $durationMs = (int) round((microtime(true) - $started) * 1000);

            if ($done === null) {
                // Nem a conclusão do cancelamento voltou. A ação pode ter
                // ficado de pé do outro lado, e dizer isso é o mínimo.
                $this->cleanupArtifacts();

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
            // A allowlist do worker recusou. A saída já traz o motivo dele.
            $saida = self::withNewline($saida)
                . "[phporto] A allowlist do PowerShell elevado recusou esta ação.\n";
        }

        $this->cleanupArtifacts();

        return new PsResult(
            output: $saida,
            // A duração medida aqui é a do canal, não a do filho: inclui os
            // tiques de 500 ms do worker. É a que a pessoa esperou.
            exitCode: $done['exit'],
            timedOut: $cancelada,
            durationMs: $durationMs,
            truncated: $truncada,
        );
    }

    /**
     * Manda e espera. É o caminho normal de quem chama.
     *
     * @param array<string, string|int|bool> $params
     *
     * @throws RuntimeException se o arquivo de trabalho não puder ser escrito
     */
    public function dispatch(WinAction $acao, array $params, string $nonce, int $timeoutSeconds): PsResult
    {
        $this->send($acao, $params, $nonce);

        return $this->collect($timeoutSeconds);
    }

    /**
     * O arquivo de conclusão, se houver.
     *
     * @return array{id: string, exit: int|null, ms: int, nota: string}|null
     */
    private function findDone(): ?array
    {
        clearstatcache();

        foreach (glob($this->filesDir . DIRECTORY_SEPARATOR . 'win-done-*.json') ?: [] as $arquivo) {
            $raw = @file_get_contents($arquivo);

            if (!is_string($raw) || trim($raw) === '') {
                continue;
            }

            $data = json_decode($raw, true);

            if (!is_array($data)) {
                continue;
            }

            $id = $data['id'] ?? null;

            if (!is_string($id) || $id === '') {
                continue;
            }

            $exit = $data['exit'] ?? null;
            $ms   = $data['ms'] ?? 0;
            $nota = $data['nota'] ?? '';

            return [
                'id'   => $id,
                'exit' => is_int($exit) ? $exit : null,
                'ms'   => is_int($ms) ? $ms : 0,
                'nota' => is_string($nota) ? $nota : '',
            ];
        }

        return null;
    }

    /**
     * A saída do filho, com o teto do PsRunner.
     *
     * @return array{0: string, 1: bool}
     */
    private function readOutput(string $id): array
    {
        return PsRunner::readBounded($this->path('win-out-' . $id . '.txt'));
    }

    /**
     * Some com os arquivos de uma ação: saída, conclusão e auxiliares.
     *
     * A limpeza é ANTES de mandar e DEPOIS de coletar: um arquivo de
     * conclusão esquecido faria a ação seguinte devolver na hora o resultado
     * da anterior — e a pessoa leria a saída errada acreditando nela.
     *
     * NÃO TOCA NA ORDEM DE CANCELAR, e essa separação corrigiu um defeito
     * grave: a versão anterior apagava a ordem no fim do caminho de timeout,
     * logo depois de escrevê-la. O worker só lê as ordens no próprio tique de
     * 500 ms, então apagá-la significava que a ação NÃO era cancelada e o
     * processo filho seguia rodando com privilégio de Administrador — com a
     * tela dizendo que havia cancelado. Quem apaga a ordem é o worker, ao
     * obedecê-la; o único outro lugar é o send(), para não cancelar a ação
     * seguinte com uma ordem velha.
     */
    private function cleanupArtifacts(): void
    {
        foreach (['win-done-*.json', 'win-out-*.txt', 'win-err-*.txt', 'win-exit-*.txt'] as $padrao) {
            foreach (glob($this->filesDir . DIRECTORY_SEPARATOR . $padrao) ?: [] as $arquivo) {
                @unlink($arquivo);
            }
        }
    }

    private function order(string $nome): void
    {
        @file_put_contents($this->path($nome), "1\n");
    }

    private function path(string $nome): string
    {
        return rtrim($this->filesDir, '\\/') . DIRECTORY_SEPARATOR . $nome;
    }

    private static function withNewline(string $s): string
    {
        return $s === '' || str_ends_with($s, "\n") ? $s : $s . "\n";
    }
}
