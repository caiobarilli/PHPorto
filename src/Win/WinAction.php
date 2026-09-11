<?php

declare(strict_types=1);

namespace App\Win;

use InvalidArgumentException;

/**
 * As treze ações do Windows, e a allowlist do lado PHP.
 *
 * ESTA NÃO É A TRANCA. A tranca é a allowlist do worker.ps1, que roda em
 * integridade Alta e é a última a validar antes de executar. Esta classe é a
 * primeira barreira: recusa no servidor o que o formulário mandou errado, com
 * mensagem que a pessoa entende, em vez de deixar o worker recusar em silêncio
 * do outro lado de um arquivo.
 *
 * As duas listas são deliberadamente redundantes. Quem escrever no arquivo de
 * trabalho sem passar por aqui — que é exatamente o cenário que a allowlist do
 * worker existe para cobrir — não é barrado por esta classe.
 *
 * REDUNDANTE NÃO É AUTOMÁTICO: nada no PHP obriga o $ALLOWLIST do worker a
 * acompanhar este enum, e uma ação que entre só de um lado fica pela metade —
 * aceita aqui e recusada lá, ou o contrário. Quem acusa isso é o teste de
 * paridade em tests/Unit/WinActionTest.php, que lê o worker.ps1 e compara as
 * duas listas.
 *
 * A ORDEM DOS CASOS É A DO MENU, de [1] a [13], e a tela repete essa ordem nas
 * seções dela.
 *
 * Os nomes dos parâmetros devolvidos por validate() são os nomes EXATOS dos
 * parâmetros das ações (Preset, Provider, PrimaryDNS...), e não nomes próprios
 * traduzidos: o bootstrap.ps1 faz splatting direto neles, então renomear no
 * meio do caminho obrigaria a manter um mapa em algum lugar, e mapa é onde uma
 * ponta fica para trás.
 */
enum WinAction: string
{
    case Audit = 'audit';
    case Tweaks = 'tweaks';
    case Debloat = 'debloat';
    case Dns = 'dns';
    case Performance = 'performance';
    case Install = 'install';
    case Memory = 'memory';
    case Network = 'network';
    case Exporter = 'exporter';
    case Processes = 'processes';
    case Optimize = 'optimize';
    case Gpu = 'gpu';
    case Gdid = 'gdid';

    /**
     * Teto de bytes de qualquer campo de texto livre.
     *
     * Existe porque o arquivo de trabalho é lido por um processo elevado: um
     * campo sem teto é um jeito de fazer o worker gastar memória com algo que
     * nenhum parâmetro do winutil aceitaria. 4 KB é folgado para uma lista de
     * IDs do winget ou de nomes de processo — os usos reais ficam em dezenas
     * de bytes.
     */
    public const MAX_PARAM_BYTES = 4096;

    /**
     * Providers de DNS, lidos do config/dns.json do winutil-cli.
     *
     * NÃO É LISTA INVENTADA: são as nove chaves do arquivo, mais Default e
     * DHCP, que o Invoke-DNS aceita por fora da lista para restaurar o
     * padrão. Uma lista curta aqui esconderia providers que existem lá.
     *
     * A comparação do winutil é -notin, insensível a caixa no PowerShell, e a
     * leitura do JSON também é — então a caixa não quebra nada. Mantida como
     * no arquivo para quem for conferir não precisar traduzir.
     */
    public const DNS_PROVIDERS = [
        'Google',
        'Cloudflare',
        'Cloudflare_Malware',
        'Cloudflare_Malware_Adult',
        'Open_DNS',
        'Quad9',
        'AdGuard_Ads_Trackers',
        'AdGuard_Ads_Trackers_Malware_Adult',
        'Custom',
        'Default',
        'DHCP',
    ];

    public const TWEAK_PRESETS = ['standard', 'minimal', 'advanced'];

    public const OPTIMIZE_PRESETS = ['ssh', 'kill-rdp'];

    public const EXPORTER_SUBACTIONS = ['install', 'status', 'start', 'stop', 'metrics', 'firewall'];

    /** O gpu troca o firewall do exporter por uninstall. Conferido no Invoke-GPU. */
    public const GPU_SUBACTIONS = ['install', 'status', 'start', 'stop', 'metrics', 'uninstall'];

    /**
     * O gdid não instala nem para nada: ele liga e desliga um pipeline inteiro.
     *
     * Daí o conjunto não se parecer com o do exporter nem com o do gpu. E
     * `disable` bloqueia domínios de notificação no hosts, o que é efeito
     * amplo e recuperável apenas rodando `enable` — por isso o gdid não entra
     * em preset nenhum: é ação escolhida a dedo, nunca efeito colateral.
     */
    public const GDID_SUBACTIONS = ['status', 'disable', 'enable'];

    public const NETWORK_DURATION_MIN = 1;

    public const NETWORK_DURATION_MAX = 3600;

    /**
     * Valida o que veio do formulário e devolve os parâmetros do winutil.
     *
     * Devolve array vazio para as ações que não têm parâmetro — e isso é
     * resposta válida, não ausência de resposta.
     *
     * @param array<string, string> $input campos crus do POST
     *
     * @return array<string, string|int|bool>
     *
     * @throws InvalidArgumentException com a frase que vai para a tela
     */
    public function validate(array $input): array
    {
        return match ($this) {
            // O performance NÃO recebe state, e AGORA ESTA LISTA É A ÚNICA
            // COISA QUE O IMPEDE.
            //
            // Havia duas travas: esta e o param() do winutil-cli.ps1, que não
            // declarava State — passar -State devolvia NamedParameterNotFound
            // e nada executava. Aquele ponto de entrada não existe mais: o
            // bootstrap.ps1 faz splatting direto em Invoke-Performance, que
            // DECLARA -State [ValidateSet('on','off')].
            //
            // Ou seja, "off" deixou de ser inalcançável por acidente e passou
            // a ser omissão deliberada. Pôr State aqui e no worker faria o
            // desligar do plano de energia funcionar — é decisão em aberto,
            // não impedimento técnico.
            self::Audit,
            self::Debloat,
            self::Performance,
            self::Memory,
            self::Processes => [],

            self::Tweaks    => $this->tweaks($input),
            self::Dns       => $this->dns($input),
            self::Install   => $this->install($input),
            self::Network   => $this->network($input),
            // Conjuntos diferentes: o exporter tem firewall, o gpu tem
            // uninstall. Conferido nos dois Invoke-*.
            self::Exporter  => ['SubAction' => $this->pick($input, 'SubAction', self::EXPORTER_SUBACTIONS, obrigatorio: true)],
            self::Gpu       => ['SubAction' => $this->pick($input, 'SubAction', self::GPU_SUBACTIONS, obrigatorio: true)],
            self::Gdid      => ['SubAction' => $this->pick($input, 'SubAction', self::GDID_SUBACTIONS, obrigatorio: true)],
            self::Optimize  => $this->optimize($input),
        };
    }

    /**
     * O que uma execução BEM-SUCEDIDA desta ação significa para o estado.
     *
     * Devolve null para as ações que não afirmam nada sobre estado — as nove
     * que não são reversíveis, e o `gdid -SubAction status`, que só relata.
     * Null NÃO é "não aplicado": quem recebe null não mexe no que está
     * guardado. Ver WinStateChange.
     *
     * QUATRO AÇÕES SÃO REVERSÍVEIS, e cada uma diz a reversão de um jeito:
     *
     *   tweaks    -Undo, e ele EXIGE -Preset. Medido no Invoke-Tweaks: o -Undo
     *             lê a lista daquele preset no preset.json e reverte item por
     *             item, então reverter sem saber o preset é impossível. É a
     *             razão mais forte para este estado existir: essa informação
     *             não está em nenhum outro lugar do sistema.
     *   optimize  -Undo, e ele NÃO precisa de parâmetro: o Invoke-Optimize lê
     *             o próprio C:\WinUtil\optimize-state.json e recusa sem ele. O
     *             payload guarda o preset só para a tela poder dizer o que
     *             será revertido.
     *   gdid      'disable' aplica e 'enable' reverte — não há -Undo, são duas
     *             subações. O estado real também vive em
     *             C:\WinUtil\gdid-state.json, escrito pela própria ação.
     *   performance  -State on/off. Hoje só 'on' chega, porque State não está
     *             nas allowlists; a regra já trata os dois para o dia em que
     *             entrar, e o caminho de reverter não fica escrito pela metade.
     *
     * POR QUE O ESTADO NÃO É LIDO DA MÁQUINA, apesar de optimize e gdid
     * guardarem arquivo próprio e o plano de energia ser consultável por
     * powercfg: ler custaria um processo por carregamento de página, e é
     * exatamente o custo que o heartbeat da elevação existe para não pagar
     * (~200 ms de powershell.exe contra um filemtime()). Além disso, dois dos
     * quatro arquivos ficam em C:\WinUtil, que é escrito por processo elevado,
     * e o tweaks não tem arquivo nenhum. O que esta ferramenta guarda é o que
     * ELA fez — e a nota no WinStateScope diz que mexer por fora deixa a linha
     * desatualizada, que é o custo aceito.
     *
     * @param array<string, string|int|bool> $params os já validados por validate()
     */
    public function stateChange(array $params): ?WinStateChange
    {
        return match ($this) {
            self::Tweaks => new WinStateChange(
                applied: !isset($params['Undo']),
                // Só o Preset: é o que o -Undo exige de volta. Guardar o resto
                // seria guardar o que ninguém vai ler.
                payload: isset($params['Preset']) ? ['Preset' => $params['Preset']] : [],
            ),

            self::Optimize => new WinStateChange(
                applied: !isset($params['Undo']),
                payload: isset($params['Preset']) ? ['Preset' => $params['Preset']] : [],
            ),

            // 'status' não muda nem afirma: devolve null para o chamador deixar
            // a linha como está.
            self::Gdid => match ($params['SubAction'] ?? '') {
                'disable' => new WinStateChange(applied: true),
                'enable'  => new WinStateChange(applied: false),
                default   => null,
            },

            self::Performance => new WinStateChange(
                // Ausente é 'on': é o default declarado no Invoke-Performance.
                applied: ($params['State'] ?? 'on') !== 'off',
            ),

            default => null,
        };
    }

    /**
     * @param array<string, string> $input
     *
     * @return array<string, string|bool>
     */
    private function tweaks(array $input): array
    {
        $params = ['Preset' => $this->pick($input, 'Preset', self::TWEAK_PRESETS, obrigatorio: true)];

        if ($this->flag($input, 'Undo')) {
            $params['Undo'] = true;
        }

        return $params;
    }

    /**
     * @param array<string, string> $input
     *
     * @return array<string, string>
     */
    private function dns(array $input): array
    {
        $provider = $this->pick($input, 'Provider', self::DNS_PROVIDERS, obrigatorio: true);
        $params   = ['Provider' => $provider];

        // O Invoke-DNS recusa custom sem primário; recusar aqui evita gastar
        // uma elevação e uma linha no log para uma mensagem de erro.
        if (strcasecmp($provider, 'Custom') === 0) {
            $params['PrimaryDNS'] = $this->ip($input, 'PrimaryDNS', obrigatorio: true);

            $secundario = $this->ip($input, 'SecondaryDNS', obrigatorio: false);
            if ($secundario !== '') {
                $params['SecondaryDNS'] = $secundario;
            }
        }

        return $params;
    }

    /**
     * O campo de apps é TEXTO LIVRE, com teto.
     *
     * Lista curada envelheceria em semanas: o catálogo do winget é aberto, e
     * qualquer recorte nosso viraria uma lista desatualizada que recusa o que
     * o winget aceita. A consequência ampla do install é decisão registrada —
     * a allowlist limita a SINTAXE, não o alcance.
     *
     * Não há validação de formato de ID de propósito: quem decide o que é ID
     * válido é o winget, e um regex nosso recusaria nome legítimo que ninguém
     * previu. O valor nunca entra em linha de comando (ver PsScriptBuilder),
     * então texto livre aqui não é execução de código em nenhum lugar.
     *
     * @param array<string, string> $input
     *
     * @return array<string, string>
     */
    private function install(array $input): array
    {
        $apps = $this->texto($input, 'Apps', obrigatorio: true);

        // O Invoke-Install parte por vírgula e descarta vazios; uma entrada
        // que só tem vírgulas passaria por aqui e morreria lá com mensagem
        // pior que esta.
        $itens = array_filter(array_map('trim', explode(',', $apps)), static fn (string $i): bool => $i !== '');

        if ($itens === []) {
            throw new InvalidArgumentException('Informe ao menos um app, separados por vírgula.');
        }

        return ['Apps' => $apps];
    }

    /**
     * @param array<string, string> $input
     *
     * @return array<string, string|int>
     */
    private function network(array $input): array
    {
        // A interface é OBRIGATÓRIA aqui. Sem ela o Invoke-Network cai num
        // Read-Host (linha 44), e num worker -NonInteractive isso falha com
        // erro que não explica nada para quem clicou.
        $params = ['Interface' => $this->texto($input, 'Interface', obrigatorio: true)];

        $bruto = trim($input['Duration'] ?? '');

        if ($bruto === '') {
            return $params;
        }

        if (preg_match('/^\d+$/', $bruto) !== 1) {
            throw new InvalidArgumentException('Duração deve ser um número inteiro de segundos.');
        }

        $duracao = (int) $bruto;

        if ($duracao < self::NETWORK_DURATION_MIN || $duracao > self::NETWORK_DURATION_MAX) {
            throw new InvalidArgumentException(sprintf(
                'Duração fora da faixa (%d-%d segundos).',
                self::NETWORK_DURATION_MIN,
                self::NETWORK_DURATION_MAX
            ));
        }

        $params['Duration'] = $duracao;

        return $params;
    }

    /**
     * @param array<string, string> $input
     *
     * @return array<string, string|bool>
     */
    private function optimize(array $input): array
    {
        $params = [];

        $preset = $this->pick($input, 'Preset', self::OPTIMIZE_PRESETS, obrigatorio: false);
        if ($preset !== '') {
            $params['Preset'] = $preset;
        }

        $kill = $this->texto($input, 'Kill', obrigatorio: false);
        if ($kill !== '') {
            $params['Kill'] = $kill;
        }

        $keepUser = $this->texto($input, 'KeepUser', obrigatorio: false);
        if ($keepUser !== '') {
            $params['KeepUser'] = $keepUser;
        }

        if ($this->flag($input, 'Undo')) {
            $params['Undo'] = true;
        }

        // Sem preset, sem lista e sem undo, o Invoke-Optimize não tem o que
        // fazer — e uma execução que não faz nada vira uma linha no log que
        // parece falha.
        if (!isset($params['Preset']) && !isset($params['Kill']) && !isset($params['Undo'])) {
            throw new InvalidArgumentException(
                'Escolha um preset, uma lista de processos, ou marque restaurar (-Undo).'
            );
        }

        return $params;
    }

    /**
     * @param array<string, string> $input
     * @param list<string>          $permitidos
     */
    private function pick(array $input, string $campo, array $permitidos, bool $obrigatorio): string
    {
        $valor = trim($input[$campo] ?? '');

        if ($valor === '') {
            if ($obrigatorio) {
                throw new InvalidArgumentException(sprintf(
                    'Escolha um valor para %s (%s).',
                    $campo,
                    implode(', ', $permitidos)
                ));
            }

            return '';
        }

        foreach ($permitidos as $permitido) {
            if (strcasecmp($valor, $permitido) === 0) {
                // Devolve a forma da LISTA, não a que veio no POST: assim o
                // que chega ao worker e ao log é sempre a mesma grafia.
                return $permitido;
            }
        }

        throw new InvalidArgumentException(sprintf('Valor inválido para %s.', $campo));
    }

    /**
     * @param array<string, string> $input
     */
    private function texto(array $input, string $campo, bool $obrigatorio): string
    {
        $valor = trim($input[$campo] ?? '');

        if ($valor === '' && $obrigatorio) {
            throw new InvalidArgumentException(sprintf('Preencha %s.', $campo));
        }

        if (strlen($valor) > self::MAX_PARAM_BYTES) {
            throw new InvalidArgumentException(sprintf(
                '%s passou do teto de %d bytes.',
                $campo,
                self::MAX_PARAM_BYTES
            ));
        }

        return $valor;
    }

    /**
     * @param array<string, string> $input
     */
    private function ip(array $input, string $campo, bool $obrigatorio): string
    {
        $valor = trim($input[$campo] ?? '');

        if ($valor === '') {
            if ($obrigatorio) {
                throw new InvalidArgumentException(sprintf('Preencha %s.', $campo));
            }

            return '';
        }

        if (filter_var($valor, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException(sprintf('%s não é um endereço IP válido.', $campo));
        }

        return $valor;
    }

    /**
     * Checkbox: presente e igual a "1" liga; qualquer outra coisa não liga.
     *
     * @param array<string, string> $input
     */
    private function flag(array $input, string $campo): bool
    {
        return ($input[$campo] ?? '') === '1';
    }
}
