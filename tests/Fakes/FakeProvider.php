<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Exceptions\DuplicateEntryException;
use App\Providers\DatabaseProviderInterface;

/**
 * Provider em memória para testes unitários do EntryService.
 *
 * Implementa o mesmo contrato dos providers reais sem nenhuma dependência de
 * rede ou banco. Guarda os valores inseridos para inspeção nos testes e
 * permite forçar respostas/erros para exercitar os caminhos do serviço.
 */
final class FakeProvider implements DatabaseProviderInterface
{
    /** @var list<string> valores efetivamente inseridos via insertEntry() */
    public array $inserted = [];

    /** Quantas vezes entryExists() foi chamado. */
    public int $existsCalls = 0;

    /**
     * @param list<string> $existing valores que já "existem" na base
     */
    public function __construct(private array $existing = [])
    {
    }

    public function insertEntry(string $entry): void
    {
        if (in_array($entry, $this->existing, true)) {
            throw new DuplicateEntryException('Este registro já existe.');
        }

        $this->inserted[] = $entry;
        $this->existing[] = $entry;
    }

    public function entryExists(string $entry): bool
    {
        $this->existsCalls++;

        return in_array($entry, $this->existing, true);
    }

    public function findAll(): array
    {
        return array_map(
            static fn (string $entry): array => ['entry' => $entry, 'created_at' => null],
            $this->existing
        );
    }
}
