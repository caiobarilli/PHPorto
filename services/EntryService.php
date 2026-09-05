<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\DuplicateEntryException;
use App\Providers\DatabaseProviderInterface;
use InvalidArgumentException;

/**
 * Regras de negócio dos registros.
 *
 * Normaliza o valor, checa duplicidade e delega a persistência ao provider.
 * Não conhece HTTP nem o driver de banco — depende só da interface.
 *
 * Esqueleto genérico: a validação aqui é deliberadamente mínima (valor não
 * vazio). É este o ponto onde a regra do domínio entra quando houver domínio;
 * sobre a unicidade herdada, ver a nota de fase na DatabaseProviderInterface.
 */
final class EntryService
{
    public function __construct(
        private readonly DatabaseProviderInterface $provider
    ) {
    }

    /**
     * Registra um valor.
     *
     * @throws InvalidArgumentException  valor inválido (-> 400).
     * @throws DuplicateEntryException   valor já registrado (-> 409).
     * @throws \RuntimeException         falha interna (-> 500).
     */
    public function register(string $entry): void
    {
        $entry = $this->normalize($entry);

        if (!$this->isValid($entry)) {
            throw new InvalidArgumentException('Valor inválido.');
        }

        // Checagem em nível de aplicação para uma resposta 409 limpa.
        // A barreira definitiva continua sendo o índice único do banco,
        // que cobre a corrida entre dois registros simultâneos.
        if ($this->provider->entryExists($entry)) {
            throw new DuplicateEntryException('Este registro já existe.');
        }

        $this->provider->insertEntry($entry);
    }

    /**
     * Só apara as bordas. Note que NÃO há lowercase: caixa era significativa
     * apenas para e-mail e saiu junto com ele — um valor genérico (um caminho,
     * um comando) não pode ser rebaixado de caixa sem mudar de significado.
     */
    private function normalize(string $entry): string
    {
        return trim($entry);
    }

    private function isValid(string $entry): bool
    {
        return $entry !== '';
    }
}
