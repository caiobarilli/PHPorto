<?php

declare(strict_types=1);

namespace App\Win;

use Closure;

/**
 * A política do UAC desta máquina, lida do registro, e o que ela permite.
 *
 * POR QUE EXISTE: a ação sensível só vale a pena porque cada execução pede um
 * prompt. Com o UAC silencioso (ConsentPromptBehaviorAdmin = 0) ou desligado
 * (EnableLUA = 0) não há prompt nenhum, e rodar mesmo assim daria a impressão
 * de uma proteção que não existe. Nesses casos a ação sensível é RECUSADA, com
 * a correção na frase. Política que não deu para ler também recusa: o lado
 * fechado é o padrão.
 *
 * NÃO É TRANCA, e isto fica escrito para ninguém ler como se fosse: a leitura
 * é feita em integridade Média, então um processo Médio consegue forjá-la. Não
 * importa: com CPBA = 0 ou EnableLUA = 0 esse processo eleva sozinho de
 * qualquer jeito. A checagem existe para a tela não mentir e para empurrar a
 * máquina de volta ao padrão. A tranca é o próprio UAC, mais o worker longo
 * recusando as sensíveis.
 *
 * QUEM MUDA O CPBA É A PESSOA, À MÃO (secpol.msc ou reg add num prompt
 * elevado). Uma ação do PHPorto que escrevesse política de UAC seria ruim de
 * raiz. Ver docs/seguranca.md.
 *
 * reg.exe, e não powershell.exe: um processo de dezenas de ms contra ~200 ms.
 * Roda só quando alguém precisa saber (a /config e o despacho sensível), uma
 * vez por requisição.
 */
final class UacPolicy
{
    public const REG_KEY = 'HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Policies\System';

    /**
     * O valor que o Windows usa quando a chave não existe no registro.
     *
     * @var array<string, int>
     */
    public const DEFAULTS = [
        'EnableLUA'                  => 1,
        'ConsentPromptBehaviorAdmin' => 5,
        'PromptOnSecureDesktop'      => 1,
        'ConsentPromptBehaviorUser'  => 3,
    ];

    /** A correção do UAC silencioso, colável num prompt elevado. */
    public const FIX_CPBA = 'reg add "HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Policies\System"'
        . ' /v ConsentPromptBehaviorAdmin /t REG_DWORD /d 5 /f';

    /** O cabeçalho que o reg.exe imprime antes dos valores, em qualquer idioma. */
    private const REG_HEADER = 'HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows\CurrentVersion\Policies\System';

    /** @var array<string, int>|null|false false = ainda não lido */
    private array|false|null $values = false;

    /**
     * @param (Closure(): ?string)|null $query devolve a saída do reg.exe, ou
     *                                         null quando falhou; injetável
     *                                         para teste
     */
    public function __construct(
        private readonly ?Closure $query = null,
    ) {
    }

    /**
     * Os valores lidos, com os padrões no lugar dos ausentes, ou null.
     *
     * Lidos uma vez por instância: a política não muda no meio de uma
     * requisição, e cada leitura é um processo.
     *
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

    /**
     * Por que uma ação sensível não pode rodar agora, ou null quando pode.
     *
     * Cada frase diz o que fazer. Nada foi executado em nenhum dos casos.
     */
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

    /** Uma linha para a /config: em que pé o UAC está, e o que isso faz com as ações sensíveis. */
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
     * Os valores DWORD da saída do reg.exe, com os padrões no lugar dos
     * ausentes, ou null quando a saída não é a da chave.
     *
     * O cabeçalho com o nome da chave é a âncora: o reg.exe o imprime em
     * qualquer idioma do Windows, e saída sem ele é erro ou lixo.
     *
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

    /**
     * A saída do reg.exe, ou null quando falhou ou não é Windows.
     *
     * A linha de comando é fixa: nenhum texto de fora entra nela.
     */
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
