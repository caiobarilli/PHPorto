<?php

declare(strict_types=1);

namespace App\Autostart;

use App\Network\Store as NetworkStore;
use Closure;
use RuntimeException;

final class Store
{
    public const FILE = 'autostart.json';

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

    public function enabled(): bool
    {
        return $this->read() ?? false;
    }

    public function updatedAt(): ?string
    {
        $raw = is_file($this->path) ? file_get_contents($this->path) : false;

        if (!is_string($raw)) {
            return null;
        }

        try {
            $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($data) && is_string($data['updated_at'] ?? null) ? $data['updated_at'] : null;
    }

    public function set(bool $enabled): void
    {
        $directory = dirname($this->path);

        if (!is_dir($directory)) {
            throw new RuntimeException('A pasta de estado não existe: ' . $directory);
        }

        $payload = json_encode(
            ['enabled' => $enabled, 'updated_at' => ($this->clock)()],
            JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
        ) . "\n";
        $temporary = tempnam($directory, '.autostart-');

        if ($temporary === false || file_put_contents($temporary, $payload, LOCK_EX) === false) {
            throw new RuntimeException('Não foi possível gravar o estado do início com o Windows.');
        }

        if (!rename($temporary, $this->path)) {
            @unlink($temporary);

            throw new RuntimeException('Não foi possível substituir o estado do início com o Windows.');
        }
    }

    public function migrate(NetworkStore $network): bool
    {
        if ($this->read() !== null) {
            $network->dropLegacyAutostart();

            return false;
        }

        $legacy = $network->legacyAutostart();

        if ($legacy === null) {
            return false;
        }

        $this->set($legacy);
        $network->dropLegacyAutostart();

        return true;
    }

    private function read(): ?bool
    {
        $raw = is_file($this->path) ? file_get_contents($this->path) : false;

        if (!is_string($raw)) {
            return null;
        }

        try {
            $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($data) && is_bool($data['enabled'] ?? null) ? $data['enabled'] : null;
    }
}
