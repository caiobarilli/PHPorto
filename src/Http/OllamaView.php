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
