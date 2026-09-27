<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Execution;
use App\Domain\ExecutionKind;
use App\Domain\WinState;
use App\Domain\WinStateScope;
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
     * $kinds nulo é "todos", e é o padrão: quem já chamava recent($limit) não
     * muda de comportamento. Lista vazia é nenhum. O parâmetro só atravessa
     * até o provider — a decisão de filtrar no banco em vez de na memória está
     * registrada na DatabaseProviderInterface.
     *
     * @param list<ExecutionKind>|null $kinds
     *
     * @return list<Execution> mais recentes primeiro
     *
     * @throws InvalidArgumentException limite fora da faixa.
     */
    public function recent(int $limit = 100, ?array $kinds = null): array
    {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidArgumentException('Limite fora da faixa (1-' . self::MAX_LIMIT . ').');
        }

        return $this->provider->recent($limit, $kinds);
    }

    /**
     * Apaga os registros dos tipos dados e devolve quantos. Nulo apaga todos;
     * lista vazia, nenhum.
     *
     * @param list<ExecutionKind>|null $kinds
     */
    public function clear(?array $kinds = null): int
    {
        return $this->provider->clear($kinds);
    }

    /**
     * Guarda o estado da /win — o que está aplicado, ou o que ficou marcado.
     *
     * POR QUE ISTO MORA NESTA CLASSE, apesar de o nome dela dizer "log de
     * execuções": ela é o único caminho das telas até os providers, e o repasse
     * entra junto com os métodos no banco pelo mesmo motivo registrado no
     * filtro por tipo — sem ele o código existiria nos três drivers e nenhuma
     * tela alcançaria, nascendo morto à espera de outro commit.
     *
     * Um WinStateService separado seria mais honesto no nome e custaria uma
     * SEGUNDA conexão por requisição: o bootstrap monta o provider dentro do
     * factory, então dois serviços são dois providers, e no sqlite isso é
     * abrir o arquivo duas vezes e rodar o CREATE TABLE IF NOT EXISTS duas
     * vezes por carregamento de página. Se um dia houver mais estado que
     * execução aqui, o nome da classe é que está errado.
     *
     * @throws InvalidArgumentException ação vazia.
     * @throws \App\Exceptions\StorageException falha de persistência.
     */
    public function putWinState(WinState $state): WinState
    {
        if (trim($state->action) === '') {
            throw new InvalidArgumentException('Ação vazia no estado da /win.');
        }

        return $this->provider->putWinState($state);
    }

    /**
     * @return array<string, WinState> indexado pelo nome da ação
     *
     * @throws \App\Exceptions\StorageException falha de consulta.
     */
    public function winStates(WinStateScope $scope): array
    {
        return $this->provider->winStates($scope);
    }

    /** @throws \App\Exceptions\StorageException falha de escrita. */
    public function forgetWinState(WinStateScope $scope, string $action): int
    {
        return $this->provider->forgetWinState($scope, $action);
    }
}
