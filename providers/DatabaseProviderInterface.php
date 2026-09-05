<?php

declare(strict_types=1);

namespace App\Providers;

use App\Exceptions\DuplicateEntryException;

/**
 * Contrato agnóstico de persistência.
 *
 * Um "entry" é um registro com um valor único (string) e uma data de criação.
 * Propositalmente NÃO expõe nada específico de Mongo (sem BSON, sem
 * Collection, sem ObjectId). As implementações via PDO (MySQL, SQLite)
 * implementam exatamente os mesmos métodos sem mudar a camada de serviço.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * NOTA DE FASE — de onde veio a unicidade (não apagar sem decidir)
 *
 * `entryExists()` e a DuplicateEntryException são herança direta do domínio
 * anterior (e-mail em lista de espera), onde o valor precisava ser único. Elas
 * sobreviveram à generalização porque mantêm três caminhos distintos e
 * testáveis — válido, inválido e duplicado — que é o que dá substância à
 * suíte desta fase.
 *
 * Isso NÃO é uma afirmação de que o domínio futuro precisa de unicidade. Um
 * log de execução, por exemplo, registra o mesmo comando muitas vezes e não
 * quer nada disso. Quando o domínio entrar, esta é a primeira decisão a tomar:
 * manter a unicidade porque ela serve, ou removê-la junto do índice UNIQUE nos
 * três providers. O que não vale é herdá-la por inércia.
 * ────────────────────────────────────────────────────────────────────────────
 */
interface DatabaseProviderInterface
{
    /**
     * Persiste um registro novo.
     *
     * @throws DuplicateEntryException se o valor já existir (unique constraint).
     * @throws \RuntimeException       em qualquer outra falha de persistência.
     */
    public function insertEntry(string $entry): void;

    /**
     * Indica se o valor já está registrado.
     *
     * @throws \RuntimeException em falha de consulta.
     */
    public function entryExists(string $entry): bool;

    /**
     * Retorna todos os registros, ordenados por data de criação (mais antigo
     * primeiro).
     *
     * Formato agnóstico ao driver: `created_at` vem como string ISO 8601
     * (UTC) ou null — nada de tipos do Mongo (UTCDateTime/BSON). Os providers
     * via PDO devolvem a mesma estrutura.
     *
     * @return list<array{entry: string, created_at: ?string}>
     *
     * @throws \RuntimeException em falha de consulta.
     */
    public function findAll(): array;
}
