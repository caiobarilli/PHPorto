<?php

declare(strict_types=1);

namespace App\Http;

use Closure;

final class Auth
{
    public const MAX_FAILURES = 5;

    public const LOCK_SECONDS = 900;

    public const COOKIE = 'phporto_auth';

    public const COOKIE_SECONDS = 604800;

    private const COOKIE_LABEL = 'phporto-auth-cookie-v1';

    public const COUNTER_FILE = 'auth-falhas.json';

    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param (Closure(): int)|null $clock
     */
    public function __construct(
        private readonly string $token,
        private readonly string $counterFile,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

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

    private function save(int $falhas, int $ate): void
    {
        @file_put_contents(
            $this->counterFile,
            (string) json_encode(['token' => $this->fingerprint(), 'falhas' => $falhas, 'ate' => $ate]),
            LOCK_EX
        );
    }

    private function fingerprint(): string
    {
        return hash('sha256', $this->token);
    }
}
