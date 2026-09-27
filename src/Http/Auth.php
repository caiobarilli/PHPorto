<?php

declare(strict_types=1);

namespace App\Http;

use Closure;

/**
 * O portão de token: decide se uma requisição passa, antes de qualquer rota.
 *
 * O token vai no campo de senha do HTTP Basic, e o usuário é ignorado. Sem
 * PHPORTO_AUTH_TOKEN configurado nada é servido. Cinco erros seguidos
 * bloqueiam por quinze minutos, contados num arquivo que guarda também a
 * impressão do token: trocar o token descarta o contador.
 */
final class Auth
{
    /** Erros seguidos que disparam o bloqueio. */
    public const MAX_FAILURES = 5;

    /** Duração do bloqueio, em segundos. */
    public const LOCK_SECONDS = 900;

    /** O texto do diálogo do navegador. */
    public const REALM = 'PHPorto - cole o token no campo de senha';

    /** Nome do arquivo do contador, em storage/. */
    public const COUNTER_FILE = 'auth-falhas.json';

    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * Recebe o token configurado, o caminho do arquivo do contador e,
     * opcionalmente, o relógio em segundos (para teste).
     *
     * @param (Closure(): int)|null $clock
     */
    public function __construct(
        private readonly string $token,
        private readonly string $counterFile,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * Decide sobre a senha que veio no Basic.
     *
     * Recebe a senha, ou null se a requisição não trouxe credencial. Devolve
     * a decisão, com os segundos restantes quando bloqueado.
     */
    public function check(?string $password): AuthDecision
    {
        if ($this->token === '') {
            return new AuthDecision(AuthOutcome::NotConfigured);
        }

        $agora  = ($this->clock)();
        $estado = $this->load();

        if ($estado['ate'] > $agora) {
            return new AuthDecision(AuthOutcome::Locked, $estado['ate'] - $agora);
        }

        if ($password === null) {
            return new AuthDecision(AuthOutcome::Missing);
        }

        if (hash_equals($this->token, $password)) {
            if (($estado['falhas'] > 0 || $estado['ate'] > 0) && is_file($this->counterFile)) {
                unlink($this->counterFile);
            }

            return new AuthDecision(AuthOutcome::Allowed);
        }

        $falhas = $estado['falhas'] + 1;

        if ($falhas >= self::MAX_FAILURES) {
            $this->save(0, $agora + self::LOCK_SECONDS);

            return new AuthDecision(AuthOutcome::Locked, self::LOCK_SECONDS);
        }

        $this->save($falhas, 0);

        return new AuthDecision(AuthOutcome::Wrong);
    }

    /**
     * O contador guardado para o token atual.
     *
     * Devolve zero falhas e nenhum bloqueio quando o arquivo falta, é
     * ilegível, ou é de outro token.
     *
     * @return array{falhas: int, ate: int}
     */
    private function load(): array
    {
        $vazio = ['falhas' => 0, 'ate' => 0];
        $bruto = is_file($this->counterFile) ? file_get_contents($this->counterFile) : false;

        if (!is_string($bruto)) {
            return $vazio;
        }

        $dados = json_decode($bruto, true);

        if (!is_array($dados) || ($dados['token'] ?? null) !== $this->fingerprint()) {
            return $vazio;
        }

        return [
            'falhas' => is_int($dados['falhas'] ?? null) ? $dados['falhas'] : 0,
            'ate'    => is_int($dados['ate'] ?? null) ? $dados['ate'] : 0,
        ];
    }

    /** Grava o contador do token atual. Falha de disco não impede a resposta. */
    private function save(int $falhas, int $ate): void
    {
        @file_put_contents(
            $this->counterFile,
            (string) json_encode(['token' => $this->fingerprint(), 'falhas' => $falhas, 'ate' => $ate]),
            LOCK_EX
        );
    }

    /** A impressão do token que o contador guarda, em vez do token. */
    private function fingerprint(): string
    {
        return hash('sha256', $this->token);
    }
}
