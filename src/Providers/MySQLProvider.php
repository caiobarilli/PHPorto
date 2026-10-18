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

final class MySQLProvider implements DatabaseProviderInterface
{
    private PDO $pdo;

    private string $table;

    private string $stateTable;

    /**
     * @param array{host: string, port: string, database: string, user: string, password: string, table: string} $config
     */
    public function __construct(array $config)
    {
        if ($config['database'] === '' || $config['user'] === '') {
            throw new StorageException('Configuração do MySQL incompleta (database/user).');
        }

        $this->table      = $this->sanitizeIdentifier($config['table']);
        $this->stateTable = $this->sanitizeIdentifier($this->table . self::STATE_SUFFIX);

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $config['host'],
            $config['port'],
            $config['database']
        );

        try {
            $this->pdo = new PDO($dsn, $config['user'], $config['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            $this->ensureTable();
        } catch (PDOException $e) {
            throw new StorageException('Falha ao inicializar o MySQL: ' . $e->getMessage(), 0, $e);
        }
    }

    public function insert(Execution $execution): Execution
    {
        $comData = $execution->createdAt !== null;

        try {
            $colunas = 'command, output, exit_code, duration_ms, kind, timed_out'
                . ($comData ? ', created_at' : '');
            $valores = ':command, :output, :exit_code, :duration_ms, :kind, :timed_out'
                . ($comData ? ', :created_at' : '');

            $stmt = $this->pdo->prepare(
                "INSERT INTO `{$this->table}` ({$colunas}) VALUES ({$valores})"
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

            return $this->readBack((int) $this->pdo->lastInsertId(), $execution);
        } catch (PDOException $e) {
            throw new StorageException('Falha ao gravar a execução: ' . $e->getMessage(), 0, $e);
        }
    }

    private function readBack(int $id, Execution $fallback): Execution
    {
        $stmt = $this->pdo->prepare("SELECT created_at FROM `{$this->table}` WHERE id = :id");
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
            [$where, $binds] = self::kindFilter($kinds);

            $stmt = $this->pdo->prepare(
                "SELECT command, output, exit_code, duration_ms, kind, timed_out, created_at
                   FROM `{$this->table}`{$where}
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
            [$where, $binds] = self::kindFilter($kinds);
            $stmt            = $this->pdo->prepare("DELETE FROM `{$this->table}`{$where}");

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
     * @param list<ExecutionKind>|null $kinds
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
            $stmt = $this->pdo->prepare(
                "INSERT INTO `{$this->stateTable}` (scope, action, payload, updated_at)
                      VALUES (:scope, :action, :payload, CURRENT_TIMESTAMP)
                 ON DUPLICATE KEY UPDATE
                        payload    = :payload_novo,
                        updated_at = CURRENT_TIMESTAMP"
            );

            $stmt->execute([
                'scope'        => $state->scope->value,
                'action'       => $state->action,
                'payload'      => $state->encodedPayload(),
                'payload_novo' => $state->encodedPayload(),
            ]);

            return $this->readBackState($state);
        } catch (PDOException $e) {
            throw new StorageException('Falha ao gravar o estado da /win: ' . $e->getMessage(), 0, $e);
        }
    }

    private function readBackState(WinState $state): WinState
    {
        $stmt = $this->pdo->prepare(
            "SELECT updated_at FROM `{$this->stateTable}`
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
            $stmt = $this->pdo->prepare(
                "SELECT scope, action, payload, updated_at
                   FROM `{$this->stateTable}`
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
                "DELETE FROM `{$this->stateTable}`
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
            exitCode: is_numeric($exitRaw) ? (int) $exitRaw : null,
            durationMs: is_numeric($duration) ? (int) $duration : 0,
            kind: ExecutionKind::fromStorage($row['kind'] ?? null),
            timedOut: is_numeric($timedOut) ? (int) $timedOut === 1 : $timedOut === true,
            createdAt: $this->formatDate($row['created_at'] ?? null),
        );
    }

    private function ensureTable(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS `{$this->table}` (
                id INT AUTO_INCREMENT PRIMARY KEY,
                command MEDIUMTEXT NOT NULL,
                output MEDIUMTEXT NOT NULL,
                exit_code INT NULL,
                duration_ms INT NOT NULL DEFAULT 0,
                kind VARCHAR(16) NOT NULL DEFAULT 'comando',
                timed_out TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_created_at (created_at, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $this->ensureStateTable();
    }

    private function ensureStateTable(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS `{$this->stateTable}` (
                scope VARCHAR(16) NOT NULL,
                action VARCHAR(32) NOT NULL,
                payload MEDIUMTEXT NOT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (scope, action)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    private function formatDate(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, new DateTimeZone('UTC'));

        return $date ? $date->format(DATE_ATOM) : $value;
    }

    private function sanitizeIdentifier(string $name): string
    {
        if ($name === '' || preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
            throw new StorageException("Nome de tabela MySQL inválido: {$name}");
        }

        return $name;
    }
}
