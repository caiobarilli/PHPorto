<?php

declare(strict_types=1);

namespace App\Providers;

use App\Exceptions\DuplicateEntryException;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Implementação SQLite (PDO) do provider de persistência.
 *
 * Mesmo contrato do MySQLProvider/MongoProvider: todo detalhe do driver
 * (PDO, PDOException, SQLSTATE 23000 de constraint) fica confinado aqui.
 * Para fora, só a DatabaseProviderInterface e a DuplicateEntryException —
 * nenhuma PDOException vaza.
 *
 * Vantagem: zero infraestrutura externa. O banco é um arquivo .sqlite local,
 * criado on-demand (junto da pasta-pai), o que faz dele o provider padrão.
 */
final class SQLiteProvider implements DatabaseProviderInterface
{
    /**
     * SQLSTATE para violação de integridade (inclui UNIQUE). No pdo_sqlite,
     * o errorInfo[1] específico é 19 (SQLITE_CONSTRAINT) ou o estendido 2067
     * (SQLITE_CONSTRAINT_UNIQUE); o SQLSTATE 23000 cobre os dois sem depender
     * de extended codes estarem ligados.
     */
    private const CONSTRAINT_SQLSTATE = '23000';

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
            throw new RuntimeException('Configuração do SQLite incompleta (path).');
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
            throw new RuntimeException('Falha ao inicializar o SQLite: ' . $e->getMessage(), 0, $e);
        }
    }

    public function insertEntry(string $entry): void
    {
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO \"{$this->table}\" (\"entry\") VALUES (:entry)"
            );
            $stmt->execute(['entry' => $entry]);
        } catch (PDOException $e) {
            if ($this->isConstraintViolation($e)) {
                throw new DuplicateEntryException('Este registro já existe.', 0, $e);
            }
            throw new RuntimeException('Falha ao gravar o registro: ' . $e->getMessage(), 0, $e);
        }
    }

    public function entryExists(string $entry): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT 1 FROM \"{$this->table}\" WHERE \"entry\" = :entry LIMIT 1"
            );
            $stmt->execute(['entry' => $entry]);

            return $stmt->fetchColumn() !== false;
        } catch (PDOException $e) {
            throw new RuntimeException('Falha ao consultar o registro: ' . $e->getMessage(), 0, $e);
        }
    }

    public function findAll(): array
    {
        try {
            $stmt = $this->pdo->query(
                "SELECT \"entry\", created_at FROM \"{$this->table}\" ORDER BY created_at ASC"
            );

            // Com ERRMODE_EXCEPTION o query() lança em erro; o false é só para
            // o type-checker.
            if ($stmt === false) {
                return [];
            }

            $rows = [];
            foreach ($stmt as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $entry = $row['entry'] ?? null;
                $rows[] = [
                    'entry'      => is_string($entry) ? $entry : '',
                    'created_at' => $this->formatDate($row['created_at'] ?? null),
                ];
            }

            return $rows;
        } catch (PDOException $e) {
            throw new RuntimeException('Falha ao listar os registros: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Garante que a pasta-pai do arquivo .sqlite exista. SQLite cria o arquivo
     * sozinho, mas falha se o diretório não existir.
     */
    private function ensureDirectory(string $path): void
    {
        $dir = dirname($path);

        if ($dir !== '' && !is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new RuntimeException("Não foi possível criar a pasta do SQLite: {$dir}");
        }
    }

    /**
     * Cria a tabela se não existir. Idempotente — equivalente ao
     * ensureTable do MySQLProvider.
     */
    private function ensureTable(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS \"{$this->table}\" (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                \"entry\" TEXT NOT NULL UNIQUE,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )"
        );
    }

    /**
     * Converte o `created_at` do SQLite ('YYYY-MM-DD HH:MM:SS', UTC) em string
     * ISO 8601 — mesmo formato que os outros providers devolvem. Tolera ausência.
     */
    private function formatDate(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            $value,
            new DateTimeZone('UTC')
        );

        return $date ? $date->format(DATE_ATOM) : $value;
    }

    /**
     * Detecta violação de constraint (UNIQUE) inspecionando o SQLSTATE do PDO.
     * A checagem fica isolada aqui para não vazar detalhe do driver.
     */
    private function isConstraintViolation(PDOException $e): bool
    {
        return ($e->errorInfo[0] ?? null) === self::CONSTRAINT_SQLSTATE;
    }

    /**
     * O nome da tabela vem de env e não pode ser bind param (identificador,
     * não valor). Restringe a [A-Za-z0-9_] para evitar SQL injection ao
     * interpolar na query. Mesma whitelist do MySQLProvider.
     */
    private function sanitizeIdentifier(string $name): string
    {
        if ($name === '' || preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
            throw new RuntimeException("Nome de tabela SQLite inválido: {$name}");
        }

        return $name;
    }
}
