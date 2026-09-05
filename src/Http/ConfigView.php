<?php

declare(strict_types=1);

namespace App\Http;

/**
 * A tela /config.
 *
 * ANTES esta tela era só leitura, e a razão estava certa: editar significaria
 * escrever no .env pela web, num projeto que já executa comando arbitrário,
 * para ganhar pouco — quem sobe a ferramenta tem o arquivo aberto no editor.
 *
 * O QUE MUDOU não foi essa conclusão, foi o alcance dela. O .env continua
 * intocado pelo processo web. O que a tela alterna é uma única chave, gravada
 * em storage/flags.json (ver App\Config\Flags), e o motivo de existir é que
 * PHPORTO_API_ENABLED não é preferência: é uma superfície que executa comando,
 * e ligar às cegas editando arquivo é pior do que ligar com um aviso na frente.
 *
 * Por isso a tela mostra as DUAS respostas — a do .env e a que está valendo.
 * Uma chave ligada sem que o .env diga isso é informação, não detalhe.
 */
final readonly class ConfigView
{
    /**
     * @param non-empty-string                         $provider     valor de DB_PROVIDER em uso
     * @param list<array{label: string, value: string}> $details
     * @param bool   $apiEnabled    o que vale agora (flags.json ou .env)
     * @param bool   $apiFromEnv    o que o .env sozinho diria
     * @param bool   $apiOverridden true quando a tela sobrescreve o .env
     * @param string $corsOrigin    a única origem que a API aceita
     * @param string $dbPath        caminho do sqlite, vazio nos outros bancos
     * @param bool   $dbExists      se há arquivo de banco para apagar
     */
    public function __construct(
        public string $provider,
        public array $details,
        public bool $apiEnabled,
        public bool $apiFromEnv,
        public bool $apiOverridden,
        public string $corsOrigin,
        public string $dbPath,
        public bool $dbExists,
        public ?string $notice,
        public string $csrfToken,
        public string $csrfField,
    ) {
    }
}
