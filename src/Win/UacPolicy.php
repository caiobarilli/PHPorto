<?php

declare(strict_types=1);

namespace App\Win;

use Closure;

final class UacPolicy
{
    public const REG_KEY = 'HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Policies\System';

    /**
     * @var array<string, int>
     */
    public const DEFAULTS = [
        'EnableLUA'                  => 1,
        'ConsentPromptBehaviorAdmin' => 5,
        'PromptOnSecureDesktop'      => 1,
        'ConsentPromptBehaviorUser'  => 3,
    ];

    public const FIX_CPBA = 'reg add "HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Policies\System"'
        . ' /v ConsentPromptBehaviorAdmin /t REG_DWORD /d 5 /f';

    private const REG_HEADER = 'HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows\CurrentVersion\Policies\System';

    /** @var array<string, int>|null|false */
    private array|false|null $values = false;

    /**
     * @param (Closure(): ?string)|null $query
     */
    public function __construct(
        private readonly ?Closure $query = null,
    ) {
    }

    /**
     * @return array<string, int>|null
     */
    public function values(): ?array
    {
        if ($this->values === false) {
            $saida        = $this->query !== null ? ($this->query)() : self::queryRegistry();
            $this->values = $saida === null ? null : self::parse($saida);
        }

        return $this->values;
    }

    public function verdict(): UacVerdict
    {
        return self::judge($this->values());
    }

    public function blockingReason(): ?string
    {
        return match ($this->verdict()) {
            UacVerdict::Ok => null,

            UacVerdict::Silencioso => 'O UAC está silencioso (ConsentPromptBehaviorAdmin = 0): o Windows elevaria '
                . 'sem perguntar, e esta ação só roda com um prompt de verdade. Nada foi executado. '
                . "Defina 'Comportamento do prompt de elevação para administradores no Modo de Aprovação de "
                . "Administrador' como 'Solicitar consentimento para binários não Windows' "
                . '(ConsentPromptBehaviorAdmin = 5) em secpol.msc, ou num prompt elevado: ' . self::FIX_CPBA . '.',

            UacVerdict::Desligado => 'UAC desligado (EnableLUA = 0): todo processo seu já é Administrador, e o '
                . 'PHPorto não consegue pedir confirmação por ação. Nada foi executado. Ligue o UAC '
                . "('Executar todos os administradores no Modo de Aprovação de Administrador' em secpol.msc, "
                . 'EnableLUA = 1) e reinicie o Windows.',

            UacVerdict::Desconhecido => 'Não foi possível ler a política do UAC (reg.exe query ' . self::REG_KEY
                . '), e sem ela não dá para saber se haverá prompt. Nada foi executado. Rode esse mesmo '
                . 'comando num prompt para ver o erro.',
        };
    }

    public function summary(): string
    {
        $cpba = $this->values()['ConsentPromptBehaviorAdmin'] ?? null;

        return match ($this->verdict()) {
            UacVerdict::Ok => match ($cpba) {
                5       => 'UAC no padrão do Windows (ConsentPromptBehaviorAdmin = 5): cada ação sensível abre o próprio prompt.',
                2       => 'UAC em "sempre notificar" (ConsentPromptBehaviorAdmin = 2), o endurecimento opcional: cada ação sensível abre o próprio prompt.',
                default => sprintf('UAC pedindo confirmação (ConsentPromptBehaviorAdmin = %d): cada ação sensível abre o próprio prompt.', $cpba),
            },
            UacVerdict::Silencioso   => 'UAC silencioso (ConsentPromptBehaviorAdmin = 0): ações sensíveis bloqueadas. Para liberar, num prompt elevado: ' . self::FIX_CPBA,
            UacVerdict::Desligado    => 'UAC desligado (EnableLUA = 0): ações sensíveis bloqueadas. Ligar o UAC exige reiniciar o Windows.',
            UacVerdict::Desconhecido => 'Não foi possível ler a política do UAC: ações sensíveis bloqueadas.',
        };
    }

    /**
     * @return array<string, int>|null
     */
    public static function parse(string $saida): ?array
    {
        if (stripos($saida, self::REG_HEADER) === false) {
            return null;
        }

        $valores = self::DEFAULTS;

        preg_match_all('/^\s+(\w+)\s+REG_DWORD\s+0x([0-9a-f]+)/mi', $saida, $linhas, PREG_SET_ORDER);

        foreach ($linhas as [, $nome, $hex]) {
            if (array_key_exists($nome, self::DEFAULTS)) {
                $valores[$nome] = (int) hexdec($hex);
            }
        }

        return $valores;
    }

    /** @param array<string, int>|null $valores */
    public static function judge(?array $valores): UacVerdict
    {
        if ($valores === null) {
            return UacVerdict::Desconhecido;
        }

        $lua  = $valores['EnableLUA'] ?? self::DEFAULTS['EnableLUA'];
        $cpba = $valores['ConsentPromptBehaviorAdmin'] ?? self::DEFAULTS['ConsentPromptBehaviorAdmin'];

        return match (true) {
            $lua === 0               => UacVerdict::Desligado,
            $cpba === 0              => UacVerdict::Silencioso,
            $cpba >= 1 && $cpba <= 5 => UacVerdict::Ok,
            default                  => UacVerdict::Desconhecido,
        };
    }

    private static function queryRegistry(): ?string
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return null;
        }

        $linhas = [];
        $rc     = 1;

        @exec('reg.exe query "' . self::REG_KEY . '" 2>NUL', $linhas, $rc);

        return $rc === 0 ? implode("\n", $linhas) : null;
    }
}
