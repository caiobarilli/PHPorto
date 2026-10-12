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

    /**
     * O formato do id que o worker gera: 12 dígitos hexadecimais minúsculos.
     *
     * O id vira parte do caminho de arquivos que este lado lê e APAGA, e quem
     * escreve o `win-done` pode ser qualquer processo do usuário. Fora deste
     * formato, nenhum caminho é montado.
     */
    public const ID_PATTERN = '/^[0-9a-f]{12}$/D';

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
     *
     * COM $id, ESPERA SÓ AQUELA CONCLUSÃO. É o caminho do worker de uso único:
     * quem gera o id é o PHP, então ele sabe exatamente qual `win-done` é o
     * seu, e qualquer outro é ignorado. A ordem de cancelar, nesse caso, também
     * é a daquele id ($cancelOrder), para não colidir com o worker longo.
     * Sem $id, o comportamento é o de sempre: a primeira conclusão válida.
     */
    public function collect(
        int $timeoutSeconds,
        ?string $id = null,
        string $cancelOrder = Elevation::F_ORDEM_CANCELAR,
    ): PsResult {
        if ($id !== null && !self::validId($id)) {
            throw new RuntimeException('Id de execução fora do formato: ' . $id);
        }

        // Ver TIME_LIMIT_MARGIN_S na Elevation: o php -S corta a requisição em
        // 30 s por padrão (SAPI cli-server usa o php.ini, não o 0 do CLI), e
        // sem levantar isso NENHUMA ação longa terminaria. A guarda do PHP
        // continua existindo, só acima da nossa.
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
     * A nota que abre a saída de uma execução recolhida depois do fato.
     *
     * NO INÍCIO da saída, e não no fim: quem abre o histórico e vê uma linha
     * com hora de ontem precisa saber, na primeira linha, por que ela apareceu
     * só agora. É nota de TEXTO e não coluna nova — informação para quem lê, e
     * não dado para consultar.
     */
    private const NOTA_RECOLHIDA = '[phporto] Execução recuperada depois do fato: ela terminou no '
        . 'PowerShell elevado, mas o servidor saiu no meio da espera e a linha nunca foi gravada. '
        . "Registrada agora, com a hora real de término.\n";

    /**
     * As execuções que terminaram e nunca viraram linha no banco.
     *
     * O PAR DE ARQUIVOS É A FILA, e não há tabela nem coluna de controle:
     * gravar o resultado de um job é o mesmo ato que apagar o `win-out-<id>` e
     * o `win-done-<id>`. Logo, par que sobrou é, por definição, trabalho
     * concluído e não registrado. É o espírito do flags.json — a ausência do
     * arquivo é a informação.
     *
     * SÓ PAR COMPLETO. `win-out` sem `win-done` é trabalho EM ANDAMENTO: o
     * worker cria a saída antes de largar o filho, e o arquivo de conclusão é
     * o único sinal de fim. Recolher a saída sozinha registraria como
     * terminado algo que está rodando com privilégio de Administrador.
     *
     * NÃO FILTRA POR NONCE, e isso é o oposto do que o `send()` faz. Lá o
     * nonce é guarda de obsolescência: job de ENTRADA de outra execução do
     * servidor não deve ser executado. Aqui, na saída, o órfão que interessa é
     * justamente o da execução anterior do servidor — é ele que perdeu o
     * registro. Filtrar por nonce apagaria a razão de este método existir.
     *
     * NÃO HÁ CORRIDA A TRATAR. O `php -S` é um processo só e atende em série
     * (medido: nove requisições, cinco em série e quatro simultâneas, todas com
     * o mesmo PID), e o worker roda uma ação por vez. Ninguém está lendo estes
     * arquivos ao mesmo tempo, e não há tranca a acrescentar aqui.
     *
     * @return list<OrphanRun> na ordem em que o disco devolveu
     */
    public function collectOrphans(): array
    {
        clearstatcache();

        $orfas = [];

        foreach (glob($this->resultPath('win-done-*.json')) ?: [] as $arquivo) {
            $done = self::parseDone($arquivo);

            if ($done === null) {
                continue;
            }

            // O par tem de estar completo: sem a saída não há execução a
            // registrar, só um arquivo de conclusão perdido.
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

    /**
     * Apaga o par de um órfão. Chamar SÓ DEPOIS de a linha estar no banco.
     *
     * Se o registro falhar, o par tem de ficar no disco: apagar antes de
     * gravar transformaria uma falha de banco na perda definitiva da execução,
     * que é exatamente o problema que o recolhimento existe para resolver.
     */
    public function discardOrphan(string $id): void
    {
        if (!self::validId($id)) {
            return;
        }

        foreach (['win-done-' . $id . '.json', 'win-out-' . $id . '.txt'] as $nome) {
            $caminho = $this->resultPath($nome);

            if (is_file($caminho)) {
                @unlink($caminho);
            }
        }
    }

    /**
     * O arquivo de conclusão, se houver: o daquele id, ou o primeiro válido.
     *
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

            if ($done !== null) {
                return $done;
            }
        }

        return null;
    }

    /**
     * Um arquivo de conclusão em array tipado, ou null se não der para ler.
     *
     * Os campos `acao`, `params` e `fim` só interessam a quem RECOLHE — quem
     * espera já sabe o que pediu. Estão lidos aqui, e não num segundo parser,
     * porque dois leitores do mesmo arquivo é onde um deles fica para trás.
     *
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

        // O nome do arquivo tem de ser o do id: um win-done com id de outro
        // arquivo faria este lado ler e apagar o par errado.
        if (!is_string($id) || !self::validId($id) || basename($arquivo) !== 'win-done-' . $id . '.json') {
            return null;
        }

        $exit = $data['exit'] ?? null;
        $ms   = $data['ms'] ?? 0;
        $nota = $data['nota'] ?? '';
        $acao = $data['acao'] ?? '';
        $fim  = $data['fim'] ?? null;

        $params = [];

        // Só escalar entra: o worker manda o que a allowlist dele validou, mas
        // este lado não vive de confiança em arquivo — um valor aninhado aqui
        // quebraria o rótulo na hora de montar a linha.
        if (isset($data['params']) && is_array($data['params'])) {
            foreach ($data['params'] as $nome => $valor) {
                if (!is_string($nome)) {
                    continue;
                }

                if (is_string($valor) || is_int($valor) || is_bool($valor)) {
                    $params[$nome] = $valor;
                } elseif (is_array($valor) && array_is_list($valor) && array_filter($valor, 'is_string') === $valor) {
                    // Lista de itens (-Items, -Packages): volta à forma de
                    // texto separado por vírgula que a WinAction produz.
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
     * A saída do filho, com o teto do PsRunner.
     *
     * @return array{0: string, 1: bool}
     */
    private function readOutput(string $id): array
    {
        return PsRunner::readBounded($this->resultPath('win-out-' . $id . '.txt'));
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
            foreach (glob($this->resultPath($padrao)) ?: [] as $arquivo) {
                @unlink($arquivo);
            }
        }
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
        // O worker grava saída e conclusão na pasta protegida, onde este lado
        // só lê e apaga: um win-done forjado em files/ nem é olhado.
        return $this->path(Elevation::DIR_PROTEGIDA) . DIRECTORY_SEPARATOR . $nome;
    }

    private static function withNewline(string $s): string
    {
        return $s === '' || str_ends_with($s, "\n") ? $s : $s . "\n";
    }
}
