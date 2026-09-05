<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Execution;
use App\Domain\ExecutionKind;
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
     * @param array{path: string, table: string} $config
     */
    public function __construct(array $config)
    {
        $path = $config['path'];

        if ($path === '') {
            throw new StorageException('Configuração do SQLite incompleta (path).');
        }

        $this->table = $this->sanitizeIdentifier($config['table']);

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
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO \"{$this->table}\"
                    (command, output, exit_code, duration_ms, kind, timed_out)
                 VALUES (:command, :output, :exit_code, :duration_ms, :kind, :timed_out)"
            );
            $stmt->execute([
                'command'     => $execution->command,
                'output'      => $execution->output,
                'exit_code'   => $execution->exitCode,
                'duration_ms' => $execution->durationMs,
                'kind'        => $execution->kind->value,
                'timed_out'   => $execution->timedOut ? 1 : 0,
            ]);

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

    public function recent(int $limit = 100): array
    {
        try {
            // Desempate por id: duas execuções no mesmo segundo compartilham o
            // created_at, e sem isso a ordem entre elas ficaria a critério do banco.
            $stmt = $this->pdo->prepare(
                "SELECT command, output, exit_code, duration_ms, kind, timed_out, created_at
                   FROM \"{$this->table}\"
                  ORDER BY created_at DESC, id DESC
                  LIMIT :limit"
            );
            $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
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

    public function clear(): int
    {
        try {
            return (int) $this->pdo->exec("DELETE FROM \"{$this->table}\"");
        } catch (PDOException $e) {
            throw new StorageException('Falha ao limpar as execuções: ' . $e->getMessage(), 0, $e);
        }
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
     * Garante que a pasta-pai do arquivo .sqlite exista. O SQLite cria o
     * arquivo sozinho, mas falha se o diretório não existir.
     */
    private function ensureDirectory(string $path): void
    {
        $dir = dirname($path);

        if ($dir !== '' && !is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new StorageException("Não foi possível criar a pasta do SQLite: {$dir}");
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
