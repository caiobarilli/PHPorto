<?php

declare(strict_types=1);

namespace App\Http;

use Closure;

/**
 * O portão de token: decide se uma requisição passa, antes de qualquer rota.
 *
 * Nas telas o token entra por um formulário e vira um cookie assinado; na
 * API ele vai no campo de senha do HTTP Basic, e o usuário é ignorado. Sem
 * PHPORTO_AUTH_TOKEN configurado nada é servido. Cinco erros seguidos, token
 * ou cookie, bloqueiam por quinze minutos, contados num arquivo que guarda
 * também a impressão do token: trocar o token descarta o contador.
 *
 * O cookie é "prazo.hmac": o HMAC-SHA256 de um rótulo fixo com o prazo, tendo
 * o próprio token como chave. O token nunca sai no cookie, o prazo não se
 * estende sem a chave, e trocar o token invalida todo cookie emitido.
 */
final class Auth
{
    /** Erros seguidos que disparam o bloqueio. */
    public const MAX_FAILURES = 5;

    /** Duração do bloqueio, em segundos. */
    public const LOCK_SECONDS = 900;

    /** Nome do cookie de sessão das telas. */
    public const COOKIE = 'phporto_auth';

    /** Validade do cookie, em segundos: sete dias, contados do login. */
    public const COOKIE_SECONDS = 604800;

    /** O que o HMAC assina junto com o prazo; a versão permite trocar o formato. */
    private const COOKIE_LABEL = 'phporto-auth-cookie-v1';

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

        return $this->fail($estado['falhas'], $agora);
    }

    public function isConfigured(): bool
    {
        return $this->token !== '';
    }

    /** @return array{value: string, expires: int} */
    public function issueCookie(): array
    {
        $ate = ($this->clock)() + self::COOKIE_SECONDS;

        return ['value' => $ate . '.' . $this->sign($ate), 'expires' => $ate];
    }

    public function checkCookie(?string $cookie): AuthDecision
    {
        if ($this->token === '') {
            return new AuthDecision(AuthOutcome::NotConfigured);
        }

        $agora  = ($this->clock)();
        $estado = $this->load();

        // O bloqueio vale também para quem já tem cookie: afrouxar aqui seria
        // abrir uma porta que o token certo não abre.
        if ($estado['ate'] > $agora) {
            return new AuthDecision(AuthOutcome::Locked, $estado['ate'] - $agora);
        }

        if ($cookie === null || $cookie === '') {
            return new AuthDecision(AuthOutcome::Missing);
        }

        if (preg_match('/^(\d{1,12})\.([0-9a-f]{64})$/D', $cookie, $m) !== 1
            || !hash_equals($this->sign((int) $m[1]), $m[2])) {
            return $this->fail($estado['falhas'], $agora);
        }

        // Assinatura certa e prazo vencido não é ataque: é sessão velha, e não
        // conta como erro. Prazo além da validade só aparece com o relógio
        // voltando, e cai no mesmo caso.
        $ate = (int) $m[1];

        if ($ate <= $agora || $ate > $agora + self::COOKIE_SECONDS) {
            return new AuthDecision(AuthOutcome::Expired);
        }

        return new AuthDecision(AuthOutcome::Allowed);
    }

    private function fail(int $falhasAntes, int $agora): AuthDecision
    {
        $falhas = $falhasAntes + 1;

        if ($falhas >= self::MAX_FAILURES) {
            $this->save(0, $agora + self::LOCK_SECONDS);

            return new AuthDecision(AuthOutcome::Locked, self::LOCK_SECONDS);
        }

        $this->save($falhas, 0);

        return new AuthDecision(AuthOutcome::Wrong);
    }

    private function sign(int $ate): string
    {
        return hash_hmac('sha256', self::COOKIE_LABEL . '|' . $ate, $this->token);
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
