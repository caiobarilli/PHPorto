<?php

declare(strict_types=1);

namespace App\Http;

/**
 * A tela /config.
 *
 * Mostra APENAS o banco ativo, e é só leitura. Editar significaria escrever no
 * .env pela web, num projeto que já executa comando arbitrário, para ganhar
 * pouco: quem sobe a ferramenta tem o arquivo aberto no editor.
 */
final readonly class ConfigView
{
    /**
     * @param non-empty-string $provider   valor de DB_PROVIDER em uso
     * @param list<array{label: string, value: string}> $details
     */
    public function __construct(
        public string $provider,
        public array $details,
    ) {
    }
}
