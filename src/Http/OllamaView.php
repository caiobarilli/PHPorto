<?php

declare(strict_types=1);

namespace App\Http;

use App\Win\Ollama;
use App\Win\OllamaStatus;
use Closure;

final readonly class OllamaView
{
    /**
     * @param array<string, int>|null $models
     * @param array{estado: string, bytes: int, total: int}|null $download
     * @param array{estado: string, bytes: int, total: int}|null $pull
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

    /**
     * @param array{estado: string, bytes: int, total: int} $p
     * @param Closure(int): string $mb
     */
    public static function progressText(array $p, Closure $mb): string
    {
        $txt = $p['estado'];

        if ($p['total'] > 0) {
            return $txt . ' — ' . $mb($p['bytes']) . ' de ' . $mb($p['total']) . ' (' . intdiv($p['bytes'] * 100, $p['total']) . '%)';
        }

        if ($p['bytes'] > 0) {
            return $txt . ' — ' . $mb($p['bytes']) . ' de ' . $mb(Ollama::INSTALLER_BYTES);
        }

        return $txt;
    }
}
