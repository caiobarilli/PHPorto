<?php

declare(strict_types=1);

namespace App\Http;

/** O que o portão de token decidiu sobre uma requisição. */
enum AuthOutcome
{
    /** Token certo: a requisição segue. */
    case Allowed;

    /** PHPORTO_AUTH_TOKEN ausente ou vazio: a aplicação não serve nada. */
    case NotConfigured;

    /** Nenhuma credencial na requisição. Não conta como erro. */
    case Missing;

    /** Credencial com o token errado, ou cookie com assinatura errada. Conta como erro. */
    case Wrong;

    /** Cookie com assinatura certa e prazo vencido. Não conta como erro. */
    case Expired;

    /** Erros demais em seguida: bloqueado até o prazo acabar. */
    case Locked;

    /** O código HTTP da resposta; 0 quando a requisição segue. */
    public function status(): int
    {
        return match ($this) {
            self::Allowed       => 0,
            self::NotConfigured => 503,
            self::Missing,
            self::Wrong,
            self::Expired       => 401,
            self::Locked        => 429,
        };
    }
}
