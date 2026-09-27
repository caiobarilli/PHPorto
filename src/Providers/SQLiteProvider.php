<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Execution;
use App\Domain\ExecutionKind;
use App\Domain\WinState;
use App\Domain\WinStateScope;
use App\Exceptions\StorageException;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;

/**
 * Implementação SQLite (PDO) do registro de execuções.
 *
 * Todo detalhe do driver fica confinado aqui: para fora, só a
 * DatabaseProviderInterface e a StorageException — nenhuma PDOException vaza.
 *
 * É o provider padrão porque não exige infraestrutura externa: o banco é um
 * arquivo local criado on-demand.
 */
final class SQLiteProvider implements DatabaseProviderInterface
{
    private PDO $pdo;

    /** Nome da tabela, já validado contra a whitelist de identificadores. */
    private string $table;

    /**
     * Nome da tabela de estado da /win.
     *
     * DERIVADA DA OUTRA, com sufixo fixo, e não uma chave nova no .env. O
     * nome da tabela de execuções já é configurável, então quem a renomeou
     * para não colidir com algo ganha a de estado renomeada junto, sem ter de
     * lembrar de uma segunda variável — e uma chave a menos é uma chave a
     * menos para ficar para trás, que é o mesmo critério do caminho do
     * bootstrap.ps1 no worker.
     *
     * Passa pelo sanitizeIdentifier de novo: o sufixo é literal aqui, mas
     * validar o resultado é o que garante que a interpolação na query só
     * recebe [A-Za-z0-9_] venha o prefixo de onde vier.
     */
    private string $stateTable;

    /**
     * @param array{path: string, table: string} $config
     */
    public function __construct(array $config)
    {
        $path = $config['path'];

        if ($path === '') {
            throw new StorageException('Configuração do SQLite incompleta (path).');
        }

        $this->table      = $this->sanitizeIdentifier($config['table']);
        $this->stateTable = $this->sanitizeIdentifier($this->table . self::STATE_SUFFIX);

        $this->ensureDirectory($path);

        try {
            $this->pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $this->ensureTable();
        } catch (PDOException $e) {
            throw new StorageException('Falha ao inicializar o SQLite: ' . $e->getMessage(), 0, $e);
        }
    }

    public function insert(Execution $execution): Execution
    {
        // O created_at é do BANCO quando não vem preenchido, e é assim no
        // caminho normal: a execução acabou de acontecer, e CURRENT_TIMESTAMP
        // é mais confiável que o relógio do PHP.
        //
        // Vem preenchido só no recolhimento de execução órfã, e aí o padrão
        // seria errado: carimbaria a hora em que a página foi aberta numa
        // execução que terminou antes. Como a listagem ordena por created_at,
        // isso poria a linha de ontem no topo do histórico de hoje.
        $comData = $execution->createdAt !== null;

        try {
            $colunas = 'command, output, exit_code, duration_ms, kind, timed_out'
                . ($comData ? ', created_at' : '');
            $valores = ':command, :output, :exit_code, :duration_ms, :kind, :timed_out'
                . ($comData ? ', :created_at' : '');

            $stmt = $this->pdo->prepare(
                "INSERT INTO \"{$this->table}\" ({$colunas}) VALUES ({$valores})"
            );

            $dados = [
                'command'     => $execution->command,
                'output'      => $execution->output,
                'exit_code'   => $execution->exitCode,
                'duration_ms' => $execution->durationMs,
                'kind'        => $execution->kind->value,
                'timed_out'   => $execution->timedOut ? 1 : 0,
            ];

            if ($comData) {
                $dados['created_at'] = $execution->createdAt;
            }

            $stmt->execute($dados);

            // Lê de volta pelo id recém-gerado, e não pelo conteúdo: dois
            // registros idênticos no mesmo segundo são legítimos aqui, então
            // buscar por comando devolveria o do vizinho.
            return $this->readBack((int) $this->pdo->lastInsertId(), $execution);
        } catch (PDOException $e) {
            throw new StorageException('Falha ao gravar a execução: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Devolve o registro com o created_at que o banco atribuiu.
     *
     * Se a leitura de volta não achar a linha, devolve o que foi gravado em vez
     * de estourar: o dado ESTÁ no banco, e perder a data é menos grave que
     * perder a confirmação da gravação.
     */
    private function readBack(int $id, Execution $fallback): Execution
    {
        $stmt = $this->pdo->prepare(
            "SELECT created_at FROM \"{$this->table}\" WHERE id = :id"
        );
        $stmt->execute(['id' => $id]);
        $createdAt = $this->formatDate($stmt->fetchColumn());

        if ($createdAt === null) {
            return $fallback;
        }

        return new Execution(
            command: $fallback->command,
            output: $fallback->output,
            exitCode: $fallback->exitCode,
            durationMs: $fallback->durationMs,
            kind: $fallback->kind,
            timedOut: $fallback->timedOut,
            createdAt: $createdAt,
        );
    }

    public function recent(int $limit = 100, ?array $kinds = null): array
    {
        try {
            // O filtro entra na query e não depois: ver a nota na interface.
            // Não há índice por (kind, created_at) de propósito — a consulta
            // usa o índice de ordenação e descarta por tipo enquanto varre,
            // parando no LIMIT, e num log local de uma pessoa isso não paga um
            // segundo índice. No MySQL pagaria ainda menos: lá não existe
            // CREATE INDEX IF NOT EXISTS, então criá-lo em tabela já existente
            // exigiria consultar o INFORMATION_SCHEMA a cada abertura.
            [$where, $binds] = self::kindFilter($kinds);

            // Desempate por id: duas execuções no mesmo segundo compartilham o
            // created_at, e sem isso a ordem entre elas ficaria a critério do banco.
            $stmt = $this->pdo->prepare(
                "SELECT command, output, exit_code, duration_ms, kind, timed_out, created_at
                   FROM \"{$this->table}\"{$where}
                  ORDER BY created_at DESC, id DESC
                  LIMIT :limit"
            );
            $stmt->bindValue('limit', $limit, PDO::PARAM_INT);

            foreach ($binds as $nome => $valor) {
                $stmt->bindValue($nome, $valor, PDO::PARAM_STR);
            }

            $stmt->execute();

            $rows = [];
            foreach ($stmt->fetchAll() as $row) {
                if (!is_array($row)) {
                    continue;
                }

                // O PDO devolve chaves mistas para o analisador; normaliza para
                // string antes de entregar ao hydrate, que trabalha por nome.
                $assoc = [];
                foreach ($row as $key => $value) {
                    $assoc[(string) $key] = $value;
                }

                $rows[] = $this->hydrate($assoc);
            }

            return $rows;
        } catch (PDOException $e) {
            throw new StorageException('Falha ao listar as execuções: ' . $e->getMessage(), 0, $e);
        }
    }

    public function clear(?array $kinds = null): int
    {
        try {
            // prepare() nos dois casos, e não exec() no caminho sem filtro: com
            // parâmetro o exec() não serve, e manter dois mecanismos para a
            // mesma operação é onde um deles fica para trás numa mudança futura.
            [$where, $binds] = self::kindFilter($kinds);
            $stmt            = $this->pdo->prepare("DELETE FROM \"{$this->table}\"{$where}");

            foreach ($binds as $nome => $valor) {
                $stmt->bindValue($nome, $valor, PDO::PARAM_STR);
            }

            $stmt->execute();

            return $stmt->rowCount();
        } catch (PDOException $e) {
            throw new StorageException('Falha ao limpar as execuções: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * O WHERE do filtro por tipos e os valores a ligar nele.
     *
     * Recebe a lista de tipos, ou null para todos. Devolve a cláusula (vazia
     * para todos, sempre falsa para lista vazia) e os parâmetros nomeados.
     *
     * @param list<ExecutionKind>|null $kinds
     *
     * @return array{string, array<string, string>}
     */
    private static function kindFilter(?array $kinds): array
    {
        if ($kinds === null) {
            return ['', []];
        }

        if ($kinds === []) {
            return [' WHERE 1 = 0', []];
        }

        $binds = [];
        foreach (array_values(array_unique(array_map(static fn (ExecutionKind $k): string => $k->value, $kinds))) as $i => $valor) {
            $binds['kind' . $i] = $valor;
        }

        return [' WHERE kind IN (:' . implode(', :', array_keys($binds)) . ')', $binds];
    }

    public function putWinState(WinState $state): WinState
    {
        try {
            // UPSERT NATIVO, e não SELECT-depois-INSERT-ou-UPDATE. Medido: o
            // SQLite desta máquina é 3.53.4, e o ON CONFLICT existe desde o
            // 3.24 (2018). A alternativa em dois passos abriria uma janela
            // entre a leitura e a escrita — que aqui não teria consequência,
            // porque o php -S atende em série, mas custaria uma ida a mais ao
            // banco para resolver um problema que o banco resolve sozinho.
            $stmt = $this->pdo->prepare(
                "INSERT INTO \"{$this->stateTable}\" (scope, action, payload, updated_at)
                      VALUES (:scope, :action, :payload, CURRENT_TIMESTAMP)
                 ON CONFLICT(scope, action) DO UPDATE
                        SET payload    = excluded.payload,
                            updated_at = CURRENT_TIMESTAMP"
            );

            $stmt->execute([
                'scope'   => $state->scope->value,
                'action'  => $state->action,
                'payload' => $state->encodedPayload(),
            ]);

            return $this->readBackState($state);
        } catch (PDOException $e) {
            throw new StorageException('Falha ao gravar o estado da /win: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Lê de volta só o updated_at, pelo mesmo motivo do readBack() do insert():
     * quem chama não tem outra forma de saber a hora que o banco atribuiu.
     *
     * Falha na leitura devolve o que foi gravado em vez de estourar — o dado
     * ESTÁ no banco, e perder a hora é menos grave que fazer o chamador
     * concluir que a gravação falhou.
     */
    private function readBackState(WinState $state): WinState
    {
        $stmt = $this->pdo->prepare(
            "SELECT updated_at FROM \"{$this->stateTable}\"
              WHERE scope = :scope AND action = :action"
        );
        $stmt->execute(['scope' => $state->scope->value, 'action' => $state->action]);
        $updatedAt = $this->formatDate($stmt->fetchColumn());

        if ($updatedAt === null) {
            return $state;
        }

        return new WinState(
            scope: $state->scope,
            action: $state->action,
            payload: $state->payload,
            updatedAt: $updatedAt,
        );
    }

    public function winStates(WinStateScope $scope): array
    {
        try {
            // O filtro na consulta, como no recent(): ver a nota na interface.
            $stmt = $this->pdo->prepare(
                "SELECT scope, action, payload, updated_at
                   FROM \"{$this->stateTable}\"
                  WHERE scope = :scope"
            );
            $stmt->bindValue('scope', $scope->value, PDO::PARAM_STR);
            $stmt->execute();

            $estados = [];

            foreach ($stmt->fetchAll() as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $assoc = [];
                foreach ($row as $key => $value) {
                    $assoc[(string) $key] = $value;
                }

                $estado = $this->hydrateState($assoc);

                // Linha ilegível é descartada, não é erro: ver a nota na
                // interface. A tela sem estado se comporta como antes desta
                // fatia, que é o lado que não afirma nada.
                if ($estado !== null) {
                    $estados[$estado->action] = $estado;
                }
            }

            return $estados;
        } catch (PDOException $e) {
            throw new StorageException('Falha ao ler o estado da /win: ' . $e->getMessage(), 0, $e);
        }
    }

    public function forgetWinState(WinStateScope $scope, string $action): int
    {
        try {
            $stmt = $this->pdo->prepare(
                "DELETE FROM \"{$this->stateTable}\"
                  WHERE scope = :scope AND action = :action"
            );
            $stmt->execute(['scope' => $scope->value, 'action' => $action]);

            return $stmt->rowCount();
        } catch (PDOException $e) {
            throw new StorageException('Falha ao esquecer o estado da /win: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateState(array $row): ?WinState
    {
        $scope   = WinStateScope::fromStorage($row['scope'] ?? null);
        $action  = $row['action'] ?? null;
        $payload = WinState::decodePayload($row['payload'] ?? null);

        if ($scope === null || !is_string($action) || $action === '' || $payload === null) {
            return null;
        }

        return new WinState(
            scope: $scope,
            action: $action,
            payload: $payload,
            updatedAt: $this->formatDate($row['updated_at'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Execution
    {
        $command  = $row['command'] ?? '';
        $output   = $row['output'] ?? '';
        $exitRaw  = $row['exit_code'] ?? null;
        $duration = $row['duration_ms'] ?? 0;
        $timedOut = $row['timed_out'] ?? 0;

        return new Execution(
            command: is_string($command) ? $command : '',
            output: is_string($output) ? $output : '',
            // O nulo é preservado como nulo: 0 significa sucesso, null significa
            // que não houve código de saída. Um cast cru colapsaria os dois.
            exitCode: is_numeric($exitRaw) ? (int) $exitRaw : null,
            durationMs: is_numeric($duration) ? (int) $duration : 0,
            kind: ExecutionKind::fromStorage($row['kind'] ?? null),
            timedOut: is_numeric($timedOut) ? (int) $timedOut === 1 : $timedOut === true,
            createdAt: $this->formatDate($row['created_at'] ?? null),
        );
    }

    /**
     * Recusa abrir quando a pasta-pai do arquivo .sqlite não existe.
     *
     * Recebe o caminho do arquivo. Não cria pasta: o arquivo o SQLite cria
     * sozinho, a pasta tem de existir.
     *
     * @throws StorageException dizendo qual pasta falta.
     */
    private function ensureDirectory(string $path): void
    {
        $dir = dirname($path);

        if ($dir !== '' && !is_dir($dir)) {
            throw new StorageException(
                "A pasta do banco não existe: {$dir}. Confira o SQLITE_PATH no .env, ou crie a pasta."
            );
        }
    }

    /** Cria a tabela se não existir. Idempotente. Sem UNIQUE: ver a interface. */
    private function ensureTable(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS \"{$this->table}\" (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                command TEXT NOT NULL,
                output TEXT NOT NULL,
                exit_code INTEGER NULL,
                duration_ms INTEGER NOT NULL DEFAULT 0,
                kind TEXT NOT NULL DEFAULT 'comando',
                timed_out INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )"
        );
        $this->pdo->exec(
            "CREATE INDEX IF NOT EXISTS \"idx_{$this->table}_created_at\"
                ON \"{$this->table}\" (created_at DESC, id DESC)"
        );
        $this->ensureStateTable();
    }

    /**
     * Cria a tabela de estado da /win. Idempotente, e no MESMO acesso.
     *
     * SEM MIGRATION, porque o projeto não tem esse mecanismo: a tabela de
     * execuções também nasce no CREATE TABLE IF NOT EXISTS de toda abertura de
     * conexão, e inventar um versionador de schema para a segunda tabela
     * criaria uma peça nova que só ela usaria. Quem já tem banco ganha a
     * tabela na próxima página que abrir, vazia — e tabela de estado vazia
     * significa exatamente o que tem de significar: nada aplicado que esta
     * ferramenta saiba.
     *
     * CHAVE COMPOSTA (scope, action) E SEM id, ao contrário da tabela de
     * execuções. Lá não há unicidade de propósito, porque duas execuções
     * iguais são dois fatos; aqui é o oposto — é uma linha por par, e gravar
     * duas vezes é substituir. A chave composta é o que torna o upsert
     * possível sem um SELECT antes.
     *
     * NÃO HÁ ÍNDICE ALÉM DA CHAVE, e pelo mesmo motivo registrado no recent():
     * a tabela tem no máximo uma linha por ação por escopo — 26 linhas no
     * limite, com as treze ações —, e varrer isso é mais rápido que abrir um
     * índice.
     */
    private function ensureStateTable(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS \"{$this->stateTable}\" (
                scope TEXT NOT NULL,
                action TEXT NOT NULL,
                payload TEXT NOT NULL DEFAULT '{}',
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (scope, action)
            )"
        );
    }

    /**
     * Converte o created_at do SQLite ('YYYY-MM-DD HH:MM:SS', UTC) em ISO 8601
     * — mesmo formato que os outros providers devolvem. Tolera ausência.
     */
    private function formatDate(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, new DateTimeZone('UTC'));

        return $date ? $date->format(DATE_ATOM) : $value;
    }

    /**
     * O nome da tabela vem de env e não pode ser bind param (identificador,
     * não valor). Restringe a [A-Za-z0-9_] para evitar SQL injection ao
     * interpolar na query.
     */
    private function sanitizeIdentifier(string $name): string
    {
        if ($name === '' || preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
            throw new StorageException("Nome de tabela SQLite inválido: {$name}");
        }

        return $name;
    }
}
