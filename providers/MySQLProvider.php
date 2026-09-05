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
 * Implementação MySQL (PDO) do provider de persistência.
 *
 * Espelha o contrato do MongoProvider: todo detalhe do driver (PDO,
 * PDOException, código 1062 de duplicate entry) fica confinado aqui. Para
 * fora, só a DatabaseProviderInterface e a DuplicateEntryException da
 * aplicação — nenhuma PDOException vaza.
 */
final class MySQLProvider implements DatabaseProviderInterface
{
    /** Código de erro do MySQL para violação de UNIQUE (ER_DUP_ENTRY). */
    private const DUPLICATE_ENTRY_CODE = 1062;

    private PDO $pdo;

    /** Nome da tabela, já validado contra a whitelist de identificadores. */
    private string $table;

    /**
     * @param array{host: string, port: string, database: string, user: string, password: string, table: string} $config
     */
    public function __construct(array $config)
    {
        if ($config['database'] === '' || $config['user'] === '') {
            throw new RuntimeException('Configuração do MySQL incompleta (database/user).');
        }

        $this->table = $this->sanitizeIdentifier($config['table']);

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
            throw new RuntimeException('Falha ao inicializar o MySQL: ' . $e->getMessage(), 0, $e);
        }
    }

    public function insertEntry(string $entry): void
    {
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO `{$this->table}` (`entry`) VALUES (:entry)"
            );
            $stmt->execute(['entry' => $entry]);
        } catch (PDOException $e) {
            if ($this->isDuplicateEntry($e)) {
                throw new DuplicateEntryException('Este registro já existe.', 0, $e);
            }
            throw new RuntimeException('Falha ao gravar o registro: ' . $e->getMessage(), 0, $e);
        }
    }

    public function entryExists(string $entry): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT 1 FROM `{$this->table}` WHERE `entry` = :entry LIMIT 1"
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
                "SELECT `entry`, created_at FROM `{$this->table}` ORDER BY created_at ASC"
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
     * Cria a tabela se não existir. Idempotente — equivalente ao
     * ensureUniqueEntryIndex do MongoProvider.
     */
    private function ensureTable(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS `{$this->table}` (
                id INT AUTO_INCREMENT PRIMARY KEY,
                `entry` VARCHAR(255) NOT NULL UNIQUE,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    /**
     * Converte o `created_at` do MySQL ('YYYY-MM-DD HH:MM:SS', UTC) em string
     * ISO 8601 — mesmo formato que o MongoProvider devolve. Tolera ausência.
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
     * Detecta duplicate entry (1062) inspecionando o errorInfo do PDO — a
     * checagem fica isolada aqui para não vazar o código do driver.
     */
    private function isDuplicateEntry(PDOException $e): bool
    {
        $code = $e->errorInfo[1] ?? null;

        return is_numeric($code) && (int) $code === self::DUPLICATE_ENTRY_CODE;
    }

    /**
     * O nome da tabela vem de env e não pode ser bind param (identificador,
     * não valor). Restringe a [A-Za-z0-9_] para evitar SQL injection ao
     * interpolar na query.
     */
    private function sanitizeIdentifier(string $name): string
    {
        if ($name === '' || preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
            throw new RuntimeException("Nome de tabela MySQL inválido: {$name}");
        }

        return $name;
    }
}
