<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\OllamaExecution;
use App\Domain\OllamaExecutionAction;
use App\Domain\OllamaExecutionStatus;
use App\Exceptions\StorageException;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;

final class OllamaExecutionsStore
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $open,
        private readonly string $close,
    ) {
    }

    public function ensure(): void
    {
        $t = $this->qi(OllamaExecution::TABLE);

        try {
            $this->pdo->exec(
                "CREATE TABLE IF NOT EXISTS {$t} (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    action TEXT NOT NULL,
                    model TEXT NULL,
                    prompt TEXT NULL,
                    output TEXT NOT NULL DEFAULT '',
                    status TEXT NOT NULL,
                    duration_ms INTEGER NOT NULL DEFAULT 0,
                    user TEXT NOT NULL DEFAULT '—',
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT NULL
                )"
            );
            $this->pdo->exec(
                "CREATE INDEX IF NOT EXISTS {$this->qi('idx_ollama_executions_created')}
                    ON {$t} (created_at DESC, id DESC)"
            );
            $this->pdo->exec(
                "CREATE INDEX IF NOT EXISTS {$this->qi('idx_ollama_executions_status')}
                    ON {$t} (status, action, id)"
            );
        } catch (PDOException $e) {
            throw new StorageException('Falha ao preparar ollama_executions: ' . $e->getMessage(), 0, $e);
        }
    }

    public function ensureMysql(): void
    {
        $t = $this->qi(OllamaExecution::TABLE);

        try {
            $this->pdo->exec(
                "CREATE TABLE IF NOT EXISTS {$t} (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    action VARCHAR(32) NOT NULL,
                    model VARCHAR(128) NULL,
                    prompt MEDIUMTEXT NULL,
                    output MEDIUMTEXT NOT NULL,
                    status VARCHAR(16) NOT NULL,
                    duration_ms INT NOT NULL DEFAULT 0,
                    user VARCHAR(8) NOT NULL DEFAULT '—',
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NULL DEFAULT NULL,
                    INDEX idx_ollama_created (created_at, id),
                    INDEX idx_ollama_status (status, action, id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        } catch (PDOException $e) {
            throw new StorageException('Falha ao preparar ollama_executions: ' . $e->getMessage(), 0, $e);
        }
    }

    public function insert(OllamaExecution $execution): OllamaExecution
    {
        $safe = $execution->withRedaction();
        $t = $this->qi(OllamaExecution::TABLE);
        $comData = $safe->createdAt !== null;

        try {
            $colunas = 'action, model, prompt, output, status, duration_ms, user'
                . ($comData ? ', created_at' : '');
            $valores = ':action, :model, :prompt, :output, :status, :duration_ms, :user'
                . ($comData ? ', :created_at' : '');

            $stmt = $this->pdo->prepare(
                "INSERT INTO {$t} ({$colunas}) VALUES ({$valores})"
            );

            $dados = [
                'action'      => $safe->action->value,
                'model'       => $safe->model,
                'prompt'      => $safe->prompt,
                'output'      => $safe->output,
                'status'      => $safe->status->value,
                'duration_ms' => $safe->durationMs,
                'user'        => OllamaExecution::USER,
            ];

            if ($comData) {
                $dados['created_at'] = $safe->createdAt;
            }

            $stmt->execute($dados);

            return $this->readBack((int) $this->pdo->lastInsertId(), $safe);
        } catch (PDOException $e) {
            throw new StorageException('Falha ao gravar ollama_executions: ' . $e->getMessage(), 0, $e);
        }
    }

    public function finalize(int $id, OllamaExecutionStatus $status, string $output, int $durationMs): ?OllamaExecution
    {
        $t = $this->qi(OllamaExecution::TABLE);
        $output = OllamaExecution::redact($output);

        try {
            $stmt = $this->pdo->prepare(
                "UPDATE {$t}
                    SET status = :status,
                        output = :output,
                        duration_ms = :duration_ms,
                        updated_at = CURRENT_TIMESTAMP
                  WHERE id = :id AND status = :iniciado"
            );
            $stmt->execute([
                'status'      => $status->value,
                'output'      => $output,
                'duration_ms' => max(0, $durationMs),
                'id'          => $id,
                'iniciado'    => OllamaExecutionStatus::Iniciado->value,
            ]);

            if ($stmt->rowCount() < 1) {
                return null;
            }

            return $this->findById($id);
        } catch (PDOException $e) {
            throw new StorageException('Falha ao finalizar ollama_executions: ' . $e->getMessage(), 0, $e);
        }
    }

    public function openPull(?string $model = null): ?OllamaExecution
    {
        $t = $this->qi(OllamaExecution::TABLE);

        try {
            $sql = "SELECT id, action, model, prompt, output, status, duration_ms, user, created_at, updated_at
                      FROM {$t}
                     WHERE status = :status AND action = :action";
            $binds = [
                'status' => OllamaExecutionStatus::Iniciado->value,
                'action' => OllamaExecutionAction::Pull->value,
            ];

            if ($model !== null) {
                $sql .= ' AND model = :model';
                $binds['model'] = $model;
            }

            $sql .= ' ORDER BY id DESC LIMIT 1';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($binds);
            $row = $stmt->fetch();

            return is_array($row) ? $this->hydrate($this->assoc($row)) : null;
        } catch (PDOException $e) {
            throw new StorageException('Falha ao buscar pull aberto: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @return list<OllamaExecution>
     */
    public function recent(int $limit, int $offset = 0): array
    {
        $t = $this->qi(OllamaExecution::TABLE);

        try {
            $stmt = $this->pdo->prepare(
                "SELECT id, action, model, prompt, output, status, duration_ms, user, created_at, updated_at
                   FROM {$t}
                  ORDER BY created_at DESC, id DESC
                  LIMIT :limit OFFSET :offset"
            );
            $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
            $stmt->execute();

            $rows = [];
            foreach ($stmt->fetchAll() as $row) {
                if (is_array($row)) {
                    $rows[] = $this->hydrate($this->assoc($row));
                }
            }

            return $rows;
        } catch (PDOException $e) {
            throw new StorageException('Falha ao listar ollama_executions: ' . $e->getMessage(), 0, $e);
        }
    }

    public function count(): int
    {
        $t = $this->qi(OllamaExecution::TABLE);

        try {
            $stmt = $this->pdo->query("SELECT COUNT(*) FROM {$t}");
            $n = $stmt === false ? false : $stmt->fetchColumn();

            return is_numeric($n) ? (int) $n : 0;
        } catch (PDOException $e) {
            throw new StorageException('Falha ao contar ollama_executions: ' . $e->getMessage(), 0, $e);
        }
    }

    public function clear(): int
    {
        $t = $this->qi(OllamaExecution::TABLE);

        try {
            $n = $this->count();
            $this->pdo->exec("DELETE FROM {$t}");

            return $n;
        } catch (PDOException $e) {
            throw new StorageException('Falha ao limpar ollama_executions: ' . $e->getMessage(), 0, $e);
        }
    }

    public function trim(int $keep, bool $mysql = false): int
    {
        if ($keep < 1) {
            throw new StorageException('Retenção ollama_executions inválida.');
        }

        $t = $this->qi(OllamaExecution::TABLE);
        $total = $this->count();

        if ($total <= $keep) {
            return 0;
        }

        $excess = $total - $keep;

        try {
            if ($mysql) {
                $stmt = $this->pdo->prepare(
                    "DELETE FROM {$t} ORDER BY created_at ASC, id ASC LIMIT :excess"
                );
            } else {
                $stmt = $this->pdo->prepare(
                    "DELETE FROM {$t}
                      WHERE id IN (
                        SELECT id FROM (
                          SELECT id FROM {$t}
                           ORDER BY created_at ASC, id ASC
                           LIMIT :excess
                        ) old
                      )"
                );
            }
            $stmt->bindValue('excess', $excess, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->rowCount();
        } catch (PDOException $e) {
            throw new StorageException('Falha ao reter ollama_executions: ' . $e->getMessage(), 0, $e);
        }
    }

    private function readBack(int $id, OllamaExecution $fallback): OllamaExecution
    {
        $found = $this->findById($id);

        return $found ?? $fallback;
    }

    private function findById(int $id): ?OllamaExecution
    {
        $t = $this->qi(OllamaExecution::TABLE);
        $stmt = $this->pdo->prepare(
            "SELECT id, action, model, prompt, output, status, duration_ms, user, created_at, updated_at
               FROM {$t} WHERE id = :id"
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->hydrate($this->assoc($row)) : null;
    }

    /**
     * @param array<array-key, mixed> $row
     * @return array<string, mixed>
     */
    private function assoc(array $row): array
    {
        $assoc = [];
        foreach ($row as $key => $value) {
            $assoc[(string) $key] = $value;
        }

        return $assoc;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): OllamaExecution
    {
        $model = $row['model'] ?? null;
        $prompt = $row['prompt'] ?? null;
        $output = $row['output'] ?? '';
        $duration = $row['duration_ms'] ?? 0;
        $user = $row['user'] ?? OllamaExecution::USER;
        $id = $row['id'] ?? null;

        return new OllamaExecution(
            action: OllamaExecutionAction::fromStorage($row['action'] ?? null),
            output: is_string($output) ? $output : '',
            status: OllamaExecutionStatus::fromStorage($row['status'] ?? null),
            durationMs: is_numeric($duration) ? (int) $duration : 0,
            model: is_string($model) && $model !== '' ? $model : null,
            prompt: is_string($prompt) ? $prompt : null,
            user: is_string($user) && $user !== '' ? $user : OllamaExecution::USER,
            createdAt: $this->formatDate($row['created_at'] ?? null),
            updatedAt: $this->formatDate($row['updated_at'] ?? null),
            id: is_numeric($id) ? (int) $id : null,
        );
    }

    private function formatDate(mixed $value): ?string
    {
        if ($value instanceof DateTimeImmutable) {
            return $value->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
        }

        if (!is_string($value) || $value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, new DateTimeZone('UTC'));

        return $date ? $date->format(DATE_ATOM) : $value;
    }

    private function qi(string $name): string
    {
        return $this->open . $name . $this->close;
    }
}
