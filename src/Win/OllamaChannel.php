<?php

declare(strict_types=1);

namespace App\Win;

use Throwable;

final class OllamaChannel
{
    public const TIMEOUT_S = 60;

    private const API_TIMEOUT_S = 5;

    public function __construct(
        private readonly string $filesDir,
        private readonly bool $windows = PHP_OS_FAMILY === 'Windows',
    ) {
    }

    public function dir(): string
    {
        return $this->filesDir . DIRECTORY_SEPARATOR . Ollama::DIR;
    }

    public function installerPath(): string
    {
        return $this->dir() . DIRECTORY_SEPARATOR . Ollama::INSTALLER;
    }

    public function blocked(): ?string
    {
        return $this->windows ? null : 'O canal do Ollama só funciona com o PHPorto rodando no Windows.';
    }

    public function status(): ?OllamaStatus
    {
        $r = $this->runScript('status', Ollama::statusScript());

        return $r === null || $r->timedOut ? null : OllamaStatus::parse($r->output);
    }

    public function runScript(string $nome, string $body): ?PsResult
    {
        if ($this->blocked() !== null) {
            return null;
        }

        $script = $this->dir() . DIRECTORY_SEPARATOR . 'ollama-' . $nome . '.ps1';

        try {
            PsScriptBuilder::write($script, $body);
            return (new PsRunner($this->filesDir))->run($script, self::TIMEOUT_S);
        } catch (Throwable) {
            return null;
        } finally {
            @unlink($script);
        }
    }

    public function spawnChild(string $nome, string $php): ?PsResult
    {
        $filho = $this->dir() . DIRECTORY_SEPARATOR . 'ollama-' . $nome . '.php';

        if (@file_put_contents($filho, $php) === false) {
            return null;
        }

        return $this->runScript('filho', Ollama::childScript(PHP_BINARY, $filho));
    }

    /**
     * @return array{estado: string, bytes: int, total: int, duration_ms: int}|null
     */
    public function progress(string $arquivo): ?array
    {
        $caminho = $this->dir() . DIRECTORY_SEPARATOR . $arquivo;
        $json = is_file($caminho) ? @file_get_contents($caminho, false, null, 0, 65536) : false;

        return is_string($json) ? Ollama::parseProgress($json) : null;
    }

    public function clearProgress(string $arquivo): void
    {
        @unlink($this->dir() . DIRECTORY_SEPARATOR . $arquivo);
    }

    public function ensureDir(): bool
    {
        return is_dir($this->dir()) || @mkdir($this->dir(), 0o775, true) || is_dir($this->dir());
    }

    /**
     * @return array{code: int, body: string}|null
     */
    public function api(string $metodo, string $path, ?string $corpo = null): ?array
    {
        if ($this->blocked() !== null) {
            return null;
        }

        $http = [
            'method'          => $metodo,
            'follow_location' => 0,
            'timeout'         => self::API_TIMEOUT_S,
            'ignore_errors'   => true,
            'header'          => "User-Agent: PHPorto\r\nContent-Type: application/json\r\n",
        ];

        if ($corpo !== null) {
            $http['content'] = $corpo;
        }

        $resposta = @file_get_contents(Ollama::apiUrl($path), false, stream_context_create(['http' => $http]), 0, 1048576);

        if (!is_string($resposta)) {
            return null;
        }

        $cab = http_get_last_response_headers() ?? [];
        $code = preg_match('#\AHTTP/\S+ (\d{3})#', $cab[0] ?? '', $m) === 1 ? (int) $m[1] : 0;

        return ['code' => $code, 'body' => $resposta];
    }

    public function version(): ?string
    {
        $r = $this->api('GET', '/api/version');
        $d = $r !== null && $r['code'] === 200 ? json_decode($r['body'], true) : null;

        return is_array($d) && is_string($d['version'] ?? null) ? $d['version'] : null;
    }

    /**
     * @return array<string, int>|null
     */
    public function models(): ?array
    {
        $r = $this->api('GET', '/api/tags');

        return $r !== null && $r['code'] === 200 ? Ollama::parseTags($r['body']) : null;
    }

    /**
     * @return list<array{name: string, size: int, size_vram: int, expires_at: ?string}>|null
     */
    public function loaded(): ?array
    {
        $r = $this->api('GET', '/api/ps');

        return $r !== null && $r['code'] === 200 ? Ollama::parsePs($r['body']) : null;
    }

    public function remove(OllamaModel $model): bool
    {
        $r = $this->api('DELETE', '/api/delete', (string) json_encode(['model' => $model->value]));

        return $r !== null && $r['code'] === 200;
    }
}
