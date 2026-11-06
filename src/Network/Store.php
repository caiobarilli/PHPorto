<?php

declare(strict_types=1);

namespace App\Network;

use Closure;
use RuntimeException;

final class Store
{
    public const FILE = 'network.json';

    private string $path;

    /** @var Closure(): string */
    private Closure $clock;

    /**
     * @param (Closure(): string)|null $clock
     */
    public function __construct(string $filesDir, ?Closure $clock = null)
    {
        $this->path = rtrim($filesDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . self::FILE;
        $this->clock = $clock ?? static fn (): string => gmdate(DATE_ATOM);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function load(): State
    {
        $raw = is_file($this->path) ? file_get_contents($this->path) : false;

        if (!is_string($raw)) {
            return State::closed();
        }

        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return State::closed();
        }

        return State::fromArray($decoded) ?? State::closed();
    }

    /**
     * @param list<string> $allow
     */
    public function writeForCli(bool $enabled, string $bindIp, int $port, array $allow, bool $autostart): State
    {
        $state = State::fromArray([
            'enabled' => $enabled,
            'bind_ip' => $bindIp,
            'port' => $port,
            'allow' => $allow,
            'autostart' => $autostart,
            'updated_at' => ($this->clock)(),
        ]);

        if ($state === null) {
            throw new \InvalidArgumentException('Configuração de rede inválida.');
        }

        $this->write($state);

        return $state;
    }

    public function setAutostartFromWeb(bool $autostart): State
    {
        $state = $this->load();

        if ($state->updatedAt === null) {
            throw new RuntimeException('O estado da rede está ausente ou inválido. Rode php network.php on pelo SSH.');
        }

        $next = new State(
            $state->enabled,
            $state->bindIp,
            $state->port,
            $state->allow,
            $autostart,
            ($this->clock)(),
        );
        $this->write($next);

        return $next;
    }

    public function disableFromWeb(): State
    {
        $state = $this->load();

        if ($state->updatedAt === null) {
            return $state;
        }

        $next = new State(false, $state->bindIp, $state->port, $state->allow, false, ($this->clock)());
        $this->write($next);

        return $next;
    }

    private function write(State $state): void
    {
        $directory = dirname($this->path);

        if (!is_dir($directory)) {
            throw new RuntimeException('A pasta de estado não existe: ' . $directory);
        }

        $lock = fopen($this->path . '.lock', 'c');

        if ($lock === false) {
            throw new RuntimeException('Não foi possível abrir o bloqueio do estado da rede.');
        }

        $temporary = null;

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('Não foi possível bloquear o estado da rede.');
            }

            $temporary = tempnam($directory, '.network-');

            if ($temporary === false || file_put_contents($temporary, $state->encoded(), LOCK_EX) === false) {
                throw new RuntimeException('Não foi possível gravar o estado da rede.');
            }

            if (!rename($temporary, $this->path)) {
                throw new RuntimeException('Não foi possível substituir o estado da rede.');
            }
        } finally {
            if (is_string($temporary) && is_file($temporary)) {
                unlink($temporary);
            }

            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
