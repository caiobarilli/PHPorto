<?php

declare(strict_types=1);

namespace App\Network;

use App\Http\AuthDecision;
use App\Http\AuthOutcome;
use Closure;
use RuntimeException;

final class LoginAttempts
{
    public const FILE = 'network-login.json';
    public const MAX_ATTEMPTS = 5;
    public const WINDOW_SECONDS = 900;

    /** @var Closure(): int */
    private Closure $clock;

    /** @param (Closure(): int)|null $clock */
    public function __construct(private string $filesDir, ?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function before(string $ip): ?AuthDecision
    {
        $wait = $this->retryAfter($ip);

        return $wait === 0 ? null : new AuthDecision(AuthOutcome::Locked, $wait);
    }

    public function retryAfter(string $ip): int
    {
        return $this->wait($this->read()[self::key($ip)] ?? null) ?? 0;
    }

    public function after(string $ip, AuthDecision $decision, bool $attempt): AuthDecision
    {
        $wait = $this->retryAfter($ip);

        if ($wait !== 0) {
            return new AuthDecision(AuthOutcome::Locked, $wait);
        }

        if (!$attempt) {
            return $decision;
        }

        if ($decision->outcome !== AuthOutcome::Wrong && $decision->outcome !== AuthOutcome::Allowed) {
            return $decision;
        }

        $this->record($ip, $decision->outcome === AuthOutcome::Allowed);

        return $decision;
    }

    private static function key(string $ip): string
    {
        return hash('sha256', $ip);
    }

    private function record(string $ip, bool $success): void
    {
        $path = $this->path();
        $lock = @fopen($path . '.lock', 'c');

        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }

            throw new RuntimeException('Não foi possível bloquear as tentativas de login pela rede.');
        }

        try {
            $key = self::key($ip);
            $rows = $this->read();
            $timestamps = $this->timestamps($rows[$key] ?? null);

            if ($success) {
                unset($rows[$key]);
            } else {
                $timestamps[] = ($this->clock)();
                $rows[$key] = $timestamps;
            }

            $encoded = json_encode(['version' => 1, 'ips' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

            if (@file_put_contents($path, $encoded . "\n", LOCK_EX) === false) {
                throw new RuntimeException('Não foi possível guardar as tentativas de login pela rede.');
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function path(): string
    {
        return rtrim($this->filesDir, '\\/') . DIRECTORY_SEPARATOR . self::FILE;
    }

    /** @return array<string, list<int>> */
    private function read(): array
    {
        $raw = is_file($this->path()) ? @file_get_contents($this->path()) : false;

        if (!is_string($raw)) {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!is_array($decoded) || ($decoded['version'] ?? null) !== 1 || !is_array($decoded['ips'] ?? null)) {
            return [];
        }

        $rows = [];
        foreach ($decoded['ips'] as $key => $timestamps) {
            if (!is_string($key) || preg_match('/^[0-9a-f]{64}$/D', $key) !== 1) {
                continue;
            }

            $valid = $this->timestamps($timestamps);
            if ($valid !== []) {
                $rows[$key] = $valid;
            }
        }

        return $rows;
    }

    /** @return list<int> */
    private function timestamps(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $now = ($this->clock)();
        $valid = [];
        foreach ($value as $timestamp) {
            if (is_int($timestamp) && $timestamp > $now - self::WINDOW_SECONDS && $timestamp <= $now) {
                $valid[] = $timestamp;
            }
        }

        sort($valid);

        return array_slice($valid, -self::MAX_ATTEMPTS);
    }

    private function wait(mixed $value): ?int
    {
        $timestamps = $this->timestamps($value);

        if (count($timestamps) < self::MAX_ATTEMPTS) {
            return null;
        }

        return max(1, $timestamps[0] + self::WINDOW_SECONDS - ($this->clock)());
    }
}
