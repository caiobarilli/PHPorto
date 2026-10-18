<?php

declare(strict_types=1);

namespace App\Win;

use Throwable;

final class HypervGate
{
    public const F_STATE = 'hyperv.json';

    public const FEATURE_NAME = 'Microsoft-Hyper-V';

    public const CHECK_TIMEOUT_S = 30;

    private const STATE_ENABLED = 1;

    private const STATE_DISABLED = 2;

    public function __construct(
        private readonly string $storageDir,
        private readonly string $filesDir,
    ) {
    }

    public function enabled(): bool
    {
        return $this->read() !== null;
    }

    public function enabledAt(): ?string
    {
        return $this->read()['enabled_at'] ?? null;
    }

    public function enable(): ?string
    {
        [$estado, $edicao] = $this->featureState();

        if ($estado === self::STATE_ENABLED) {
            return $this->write()
                ? null
                : 'Não foi possível gravar ' . $this->statePath()
                    . '. Verifique a permissão de escrita da pasta storage/.';
        }

        return self::refusal($estado, $edicao);
    }

    public static function refusal(int $estado, ?string $edicao): string
    {
        return match ($estado) {
            self::STATE_DISABLED => 'O Hyper-V está instalado, mas desligado no Windows. Ligá-lo é uma '
                . 'mudança no próprio Windows e exige reiniciar o computador — faça pelo "Ativar ou '
                . 'desativar recursos do Windows" e reinicie. O PHPorto não liga o recurso por você.',
            0 => 'Não foi possível conferir o estado do Hyper-V no Windows. Nada foi habilitado — '
                . 'na dúvida, o painel fica desligado.',
            default => self::notInstalled($edicao),
        };
    }

    private static function notInstalled(?string $edicao): string
    {
        if ($edicao !== null && preg_match('/\bHome\b/i', $edicao) === 1) {
            return 'Este computador tem o ' . $edicao . ', e no Windows Home o Hyper-V não existe: '
                . 'ele vem no Pro, Enterprise e Education. Não há o que ligar aqui.';
        }

        $frase = 'O Hyper-V não está instalado neste Windows. Instalar o recurso é uma mudança '
            . 'grande, que exige reiniciar o computador — faça pelo "Ativar ou desativar recursos '
            . 'do Windows" e reinicie. O PHPorto não instala o recurso por você.';

        return $edicao === null
            ? $frase . ' No Windows Home o Hyper-V não existe; ele vem no Pro, Enterprise e Education.'
            : $frase;
    }

    /**
     * @return array{0: int, 1: ?string}
     */
    public static function parseCheck(string $output, bool $timedOut = false): array
    {
        if ($timedOut) {
            return [0, null];
        }

        $estado = preg_match('/HYPERV=(\d+)/', $output, $m) === 1 ? (int) $m[1] : 0;
        $edicao = null;

        if (preg_match('/^EDICAO=([A-Za-z0-9 ._()-]{3,100})\s*$/m', $output, $e) === 1) {
            $edicao = trim($e[1]);
        }

        return [$estado, $edicao];
    }

    public function disable(): bool
    {
        $caminho = $this->statePath();

        if (!is_file($caminho)) {
            return true;
        }

        return @unlink($caminho);
    }

    public function statePath(): string
    {
        return $this->path($this->storageDir, self::F_STATE);
    }

    /**
     * @return array{0: int, 1: ?string}
     */
    private function featureState(): array
    {
        $script = $this->path($this->filesDir, 'hyperv-check.ps1');

        try {
            PsScriptBuilder::write($script, $this->checkBody());
        } catch (Throwable) {
            return [0, null];
        }

        try {
            $r = (new PsRunner($this->filesDir))->run($script, self::CHECK_TIMEOUT_S);
        } catch (Throwable) {
            return [0, null];
        } finally {
            @unlink($script);
        }

        return self::parseCheck($r->output, $r->timedOut);
    }

    private function checkBody(): string
    {
        $filtro = "Name='" . self::FEATURE_NAME . "'";

        return <<<PS
            \$ErrorActionPreference = 'Stop'
            try {
                \$f = Get-CimInstance -ClassName Win32_OptionalFeature -Filter "{$filtro}" -ErrorAction Stop
                if (\$null -eq \$f) { 'HYPERV=3' } else { 'HYPERV=' + [string][int]\$f.InstallState }
            } catch {
                'HYPERV=erro'
            }
            try {
                'EDICAO=' + [string](Get-CimInstance -ClassName Win32_OperatingSystem -ErrorAction Stop).Caption
            } catch {
                'EDICAO=?'
            }
            PS;
    }

    /**
     * @return array{enabled: true, enabled_at: string}|null
     */
    private function read(): ?array
    {
        $caminho = $this->statePath();

        if (!is_file($caminho)) {
            return null;
        }

        $raw = @file_get_contents($caminho);

        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $data = json_decode($raw, true);

        if (!is_array($data) || ($data['enabled'] ?? null) !== true) {
            return null;
        }

        $em = $data['enabled_at'] ?? null;

        return ['enabled' => true, 'enabled_at' => is_string($em) ? $em : ''];
    }

    private function write(): bool
    {
        $json = json_encode([
            'enabled'    => true,
            'enabled_at' => gmdate(DATE_ATOM),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (!is_string($json)) {
            return false;
        }

        if (!is_dir($this->storageDir) && !@mkdir($this->storageDir, 0o775, true) && !is_dir($this->storageDir)) {
            return false;
        }

        return @file_put_contents($this->statePath(), $json . "\n", LOCK_EX) !== false;
    }

    private function path(string $dir, string $nome): string
    {
        return rtrim($dir, '\\/') . DIRECTORY_SEPARATOR . $nome;
    }
}
