<?php

declare(strict_types=1);

namespace App\Http;

use App\Win\Ollama;
use App\Win\OllamaModel;
use App\Win\OllamaStatus;
use Closure;

final readonly class OllamaView
{
    public const PARTE_COMPLETA = 'completa';

    public const PARTE_ESTADO = 'estado';

    /**
     * @param array<string, int>|null $models
     * @param array{estado: string, bytes: int, total: int}|null $download
     * @param array{estado: string, bytes: int, total: int}|null $pull
     * @param list<array{name: string, size: int, size_vram: int, expires_at: ?string}>|null $loaded
     */
    public function __construct(
        public ?string $blocked,
        public ?OllamaStatus $status,
        public ?string $version,
        public ?array $models,
        public bool $installerReady,
        public ?array $download,
        public ?array $pull,
        public ?string $notice = null,
        public string $csrfToken = '',
        public string $csrfField = '',
        public ?array $loaded = null,
        public string $parte = self::PARTE_COMPLETA,
    ) {
    }

    public function pulling(): bool
    {
        return $this->pull !== null && $this->pull['estado'] !== 'success' && !str_starts_with($this->pull['estado'], 'erro');
    }

    public function downloading(): bool
    {
        return ($this->download['estado'] ?? '') === 'baixando';
    }

    /**
     * @return array{install: bool, uninstall: bool, start: bool, stop: bool}
     */
    public function buttons(): array
    {
        $livre = $this->blocked === null;
        $instalado = $this->status?->installed === true;
        $api = $this->version !== null;
        $rodando = $api || ($this->status !== null && $this->status->processes > 0);

        return [
            'install'   => $livre && $this->status !== null && !$instalado && !$this->downloading(),
            'uninstall' => $livre && $instalado,
            'start'     => $livre && $instalado && !$api,
            'stop'      => $livre && $rodando,
        ];
    }

    /**
     * @return array{pull: bool, remove: bool}
     */
    public function modelButtons(string $model): array
    {
        $livre = $this->blocked === null;
        $tem = isset($this->models[$model]);

        return [
            'pull'   => $livre && $this->version !== null && $this->models !== null && !$tem && !$this->pulling(),
            'remove' => $livre && $tem,
        ];
    }

    public function isLoaded(string $model): bool
    {
        if ($this->loaded === null) {
            return false;
        }

        foreach ($this->loaded as $m) {
            if ($m['name'] === $model) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, int>
     */
    public function extraModels(): array
    {
        if ($this->models === null) {
            return [];
        }

        $extras = [];

        foreach ($this->models as $name => $size) {
            if (OllamaModel::tryFrom($name) === null) {
                $extras[$name] = $size;
            }
        }

        ksort($extras);

        return $extras;
    }

    public function fitsInGpu(string $model): ?bool
    {
        if ($this->isLoaded($model)) {
            return true;
        }

        $modelo = OllamaModel::tryFrom($model);
        $st = $this->status;

        if ($modelo === null || $st === null || $st->vramMb === null || $st->vramUsedMb === null) {
            return null;
        }

        return $modelo->minVramMb() <= $st->vramMb - $st->vramUsedMb;
    }

    public function versionMatch(): ?bool
    {
        return Ollama::versionMatches($this->version);
    }

    public function vramFreeMb(): ?int
    {
        $st = $this->status;

        if ($st === null || $st->vramMb === null || $st->vramUsedMb === null) {
            return null;
        }

        return $st->vramMb - $st->vramUsedMb;
    }

    /**
     * @param array{estado: string, bytes: int, total: int} $p
     * @param Closure(int): string $mb
     */
    public static function progressText(array $p, Closure $mb): string
    {
        $txt = self::estado($p['estado']);

        if ($p['total'] > 0) {
            return $txt . ' — ' . $mb($p['bytes']) . ' de ' . $mb($p['total']) . ' (' . intdiv($p['bytes'] * 100, $p['total']) . '%)';
        }

        if ($p['bytes'] > 0) {
            return $txt . ' — ' . $mb($p['bytes']) . ' de ' . $mb(Ollama::INSTALLER_BYTES);
        }

        return $txt;
    }

    private static function estado(string $estado): string
    {
        $fixos = [
            'success' => 'pronto',
            'pulling manifest' => 'baixando o manifesto',
            'verifying sha256 digest' => 'conferindo o SHA-256',
            'writing manifest' => 'gravando',
        ];

        if (isset($fixos[$estado])) {
            return $fixos[$estado];
        }

        if (preg_match('/^pulling (?:sha256[:-])?([0-9a-f]{12,})$/', $estado, $m) === 1) {
            return 'baixando ' . substr($m[1], 0, 12);
        }

        return $estado;
    }
}
