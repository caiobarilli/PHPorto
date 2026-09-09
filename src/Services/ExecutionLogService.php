<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Execution;
use App\Domain\ExecutionKind;
use App\Providers\DatabaseProviderInterface;
use InvalidArgumentException;

/**
 * O registro de execuções, visto de cima.
 *
 * Valida o que entra e delega a persistência ao provider. Não conhece HTTP,
 * não conhece WSL e não conhece o driver de banco — depende só da interface.
 *
 * NÃO altera o que registra. Um log que normaliza o que guarda deixa de ser
 * prova do que aconteceu; a normalização de quebra de linha e BOM acontece
 * antes, em quem monta o script, e é justamente o texto normalizado que foi
 * executado e que precisa ser gravado como está.
 */
final class ExecutionLogService
{
    /** Teto de segurança do recent(), para uma tela não puxar a tabela inteira. */
    public const MAX_LIMIT = 500;

    public function __construct(
        private readonly DatabaseProviderInterface $provider
    ) {
    }

    /**
     * Registra e devolve o registro como ficou no banco — com created_at.
     *
     * @throws InvalidArgumentException comando vazio (-> 400).
     * @throws \App\Exceptions\StorageException falha de persistência (-> 500).
     */
    public function record(Execution $execution): Execution
    {
        if (trim($execution->command) === '') {
            throw new InvalidArgumentException('Comando vazio.');
        }

        if ($execution->durationMs < 0) {
            throw new InvalidArgumentException('Duração negativa.');
        }

        return $this->provider->insert($execution);
    }

    /**
     * $kind nulo é "todos", e é o padrão: quem já chamava recent($limit) não
     * muda de comportamento. O parâmetro só atravessa até o provider — a
     * decisão de filtrar no banco em vez de na memória está registrada na
     * DatabaseProviderInterface.
     *
     * @return list<Execution> mais recentes primeiro
     *
     * @throws InvalidArgumentException limite fora da faixa.
     */
    public function recent(int $limit = 100, ?ExecutionKind $kind = null): array
    {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidArgumentException('Limite fora da faixa (1-' . self::MAX_LIMIT . ').');
        }

        return $this->provider->recent($limit, $kind);
    }

    public function clear(?ExecutionKind $kind = null): int
    {
        return $this->provider->clear($kind);
    }
}
