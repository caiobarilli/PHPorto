<?php

declare(strict_types=1);

namespace App\Win;

use Throwable;

/**
 * O interruptor do painel do Hyper-V, e a conferência do recurso no Windows.
 *
 * A DIFERENÇA EM RELAÇÃO À Elevation É O PONTO. O PowerShell elevado é prova
 * viva de privilégio, amarrada ao PID do php -S: some a cada reinício, e é isso
 * que se quer lá. Aqui é o contrário — habilitar o painel do Hyper-V é uma
 * decisão do dono da máquina, não uma prova, então mora em arquivo próprio em
 * storage/ e SOBREVIVE a reiniciar o servidor e o Windows. Por isso não passa
 * pelo win-elevation.json nem pelo flags.json: o primeiro expira de propósito,
 * o segundo é a superfície da API, e misturar as três decisões numa só gaveta
 * seria dar a uma o prazo de validade da outra.
 *
 * FAIL-SAFE PARA O LADO FECHADO, como o Flags e a Elevation: arquivo ausente,
 * ilegível, ou sem `enabled` verdadeiro lê como desabilitado. A falha cai para
 * o lado seguro por construção, não por tratamento de erro.
 *
 * A CONFERÊNCIA DO RECURSO NÃO PRECISA DE ELEVAÇÃO, e isto foi medido: o
 * Get-WindowsOptionalFeature -Online exige Administrador (COMException "requer
 * elevação") e não roda do processo Média do PHPorto, mas o Win32_OptionalFeature
 * pelo CIM lê o mesmo estado sem elevar — InstallState 1 é ligado, 2 é presente
 * e desligado, 3 é ausente. É esse o caminho usado aqui. Habilitar o recurso no
 * Windows, esse sim, é mudança grande que pede reinício, e o PHPorto não a faz.
 */
final class HypervGate
{
    public const F_STATE = 'hyperv.json';

    /** O recurso opcional do Windows que precisa estar ligado. */
    public const FEATURE_NAME = 'Microsoft-Hyper-V';

    /**
     * Teto da conferência do recurso.
     *
     * Uma consulta CIM ao Win32_OptionalFeature responde em menos de um
     * segundo; o teto folgado cobre a primeira chamada fria e o custo de abrir
     * o powershell.exe (~200 ms medidos), sem pendurar a requisição.
     */
    public const CHECK_TIMEOUT_S = 30;

    /** InstallState do Win32_OptionalFeature: ligado. */
    private const STATE_ENABLED = 1;

    /** InstallState: presente, porém desligado. */
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

    /** Quando o painel foi habilitado, em UTC, ou null. */
    public function enabledAt(): ?string
    {
        return $this->read()['enabled_at'] ?? null;
    }

    /**
     * Confere o recurso e, só se ele estiver ligado, grava o estado.
     *
     * Devolve null quando habilitou, ou a frase para a tela quando não —
     * recurso desligado, ausente, ou conferência que não voltou. Nunca liga o
     * recurso do Windows: isso é mudança grande, com reinício, e fica de fora.
     */
    public function enable(): ?string
    {
        $estado = $this->featureState();

        if ($estado === self::STATE_ENABLED) {
            return $this->write()
                ? null
                : 'Não foi possível gravar ' . $this->statePath()
                    . '. Verifique a permissão de escrita da pasta storage/.';
        }

        return match ($estado) {
            self::STATE_DISABLED => 'O Hyper-V está instalado, mas desligado no Windows. Ligá-lo é uma '
                . 'mudança no próprio Windows e exige reiniciar o computador — faça pelo "Ativar ou '
                . 'desativar recursos do Windows" e reinicie. O PHPorto não liga o recurso por você.',
            0 => 'Não foi possível conferir o estado do Hyper-V no Windows. Nada foi habilitado — '
                . 'na dúvida, o painel fica desligado.',
            default => 'O Hyper-V não está instalado neste Windows. Instalar o recurso é uma mudança '
                . 'grande, que exige reiniciar o computador — faça pelo "Ativar ou desativar recursos '
                . 'do Windows" e reinicie. O PHPorto não instala o recurso por você.',
        };
    }

    /**
     * Desliga o painel apagando o arquivo.
     *
     * Ausência já é o estado desabilitado, então sumir com o arquivo é a
     * operação correta — não existe "gravar desligado". Sem arquivo para
     * apagar, já está no estado pedido.
     */
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
     * O InstallState do recurso, ou 0 quando a conferência não voltou legível.
     *
     * Roda um powershell.exe COMUM, não elevado: o Win32_OptionalFeature pelo
     * CIM lê o estado sem Administrador. A saída é uma linha `HYPERV=<n>`, e
     * qualquer coisa fora disso — timeout, erro, saída inesperada — vira 0, que
     * o enable() trata como "não deu para conferir" e recusa.
     */
    private function featureState(): int
    {
        $script = $this->path($this->filesDir, 'hyperv-check.ps1');

        try {
            PsScriptBuilder::write($script, $this->checkBody());
        } catch (Throwable) {
            return 0;
        }

        try {
            $r = (new PsRunner($this->filesDir))->run($script, self::CHECK_TIMEOUT_S);
        } catch (Throwable) {
            return 0;
        } finally {
            @unlink($script);
        }

        if ($r->timedOut || preg_match('/HYPERV=(\d+)/', $r->output, $m) !== 1) {
            return 0;
        }

        return (int) $m[1];
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
            PS;
    }

    /**
     * O estado gravado, ou null quando ausente, ilegível ou desabilitado.
     *
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
