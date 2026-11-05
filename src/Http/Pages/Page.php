<?php

declare(strict_types=1);

namespace App\Http\Pages;

use App\Http\Csrf;
use App\Php\C\CCompiler;
use App\Services\ExecutionLogService;
use App\Win\Elevation;
use App\Win\HypervGate;
use App\Win\OneShot;
use App\Win\PsTerminal;
use App\Win\UacPolicy;
use App\Win\WinAction;
use App\Wsl\Distro;
use Closure;

/**
 * @phpstan-import-type AppConfig from \App\Config\Config
 */
abstract class Page
{
    protected const ROWS = 100;

    /**
     * @param AppConfig $config
     * @param Closure(): ExecutionLogService $makeService
     */
    public function __construct(
        protected readonly array $config,
        protected readonly Distro $distroChecker,
        protected readonly Closure $makeService,
        protected readonly string $filesDir,
        protected readonly Elevation $elevation,
        protected readonly HypervGate $hyperv,
        protected readonly OneShot $oneShot,
        protected readonly UacPolicy $uac,
        protected readonly PsTerminal $terminal,
        protected readonly CCompiler $c,
        protected readonly string $storageDir,
    ) {
    }

    /**
     * @param array<string, string|int|bool> $params
     */
    protected static function describe(WinAction $acao, array $params): string
    {
        $partes = [$acao->value];

        foreach ($params as $nome => $valor) {
            if (is_bool($valor)) {
                if ($valor) {
                    $partes[] = '-' . $nome;
                }

                continue;
            }

            if (in_array($nome, WinAction::SECRET_PARAMS, true)) {
                $partes[] = '-' . $nome . ' ***';

                continue;
            }

            $texto    = (string) $valor;
            $partes[] = '-' . $nome . ' ' . (preg_match('/\s/', $texto) === 1 ? '"' . $texto . '"' : $texto);
        }

        return implode(' ', $partes);
    }

    protected function winBlockingReason(): ?string
    {
        $estado = $this->elevation->state();

        if ($estado->blocked !== null) {
            return $estado->blocked;
        }

        if (!$estado->on) {
            return ($estado->detail ?? 'O PowerShell elevado está desligado.')
                . ' Nada é executado sem ele: as ações do Windows exigem Administrador.';
        }

        return null;
    }

    protected function flash(string $message): void
    {
        Csrf::start();
        $_SESSION['phporto_flash'] = $message;
    }

    protected function takeFlash(): ?string
    {
        Csrf::start();
        $message = $_SESSION['phporto_flash'] ?? null;
        unset($_SESSION['phporto_flash']);

        return is_string($message) && $message !== '' ? $message : null;
    }
}
