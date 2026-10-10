<?php

declare(strict_types=1);

namespace App\Win;

use App\Domain\WinStateScope;
use InvalidArgumentException;
use RuntimeException;

/**
 * As dezesseis ações do Windows, e a allowlist do lado PHP.
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
 * A ORDEM DOS CASOS É A DO MENU, de [1] a [15], e a tela repete essa ordem nas
 * seções dela. O décimo sexto, hyperv, é fora do menu: atende a rota /hyperv, e
 * por isso vem depois, sem seção na /win.
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
    case Rdp = 'rdp';
    case Sunshine = 'sunshine';

    /**
     * A décima sexta é FORA DO MENU da /win: ela atende a rota própria /hyperv,
     * que lista as máquinas virtuais. Está no enum porque é ação do Windows como
     * as outras — passa pela allowlist do worker e pelo despachante —, mas não
     * tem seção na tela /win, e por isso vem depois da ordem de [1] a [15].
     */
    case Hyperv = 'hyperv';

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

    /** Provider que o Invoke-DNS aceita além das chaves do dns.json: devolve o DNS ao automático. */
    public const DNS_EXTRA = ['DHCP'];

    public const TWEAK_PRESETS = ['standard', 'minimal', 'advanced'];

    /**
     * Os principais que a tela oferece como caixa no install, por ID do winget.
     *
     * Cada ID foi conferido com winget show --id --exact na fonte winget. As
     * caixas só somam ao campo livre, que continua aceitando qualquer ID.
     *
     * @var array<string, string>
     */
    public const INSTALL_SUGGESTIONS = [
        'Git.Git'                     => 'Git',
        'Microsoft.VisualStudioCode'  => 'Visual Studio Code',
        'Docker.DockerDesktop'        => 'Docker Desktop',
        'Microsoft.WSL'               => 'WSL',
        'Debian.Debian'               => 'Debian',
        '7zip.7zip'                   => '7-Zip',
        'VB-Audio.Voicemeeter.Potato' => 'VoiceMeeter Potato',
    ];

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

    /**
     * O rdp lê e liga/desliga o acesso remoto, e liga/reverte o vídeo H.264/UDP.
     *
     * status só lê; on/off valem na hora (Applied); h264-on/h264-off só valem
     * depois de reiniciar (PendingReboot). Ver stateChange().
     */
    public const RDP_SUBACTIONS = ['status', 'on', 'off', 'h264-on', 'h264-off'];

    /**
     * O sunshine lê, instala pelo winget, sobe e para o serviço, abre/fecha a
     * porta no firewall, grava as credenciais da Web UI e pareia um Moonlight.
     *
     * set-creds e pair são as duas que levam texto da pessoa (usuário, senha,
     * PIN, nome do dispositivo); as outras seis recusam esses campos. O PIN é
     * PEDIDO na tela, mas nunca GUARDADO: ver SECRET_PARAMS. Só start e stop
     * mexem no estado do serviço; ver stateChange().
     */
    public const SUNSHINE_SUBACTIONS = ['status', 'install', 'start', 'stop', 'firewall-open', 'firewall-close', 'set-creds', 'pair'];

    /** As subações do sunshine que levam credencial. As outras recusam os campos dela. */
    public const SUNSHINE_CRED_SUBACTIONS = ['set-creds', 'pair'];

    /** Os campos de texto que só set-creds e pair aceitam, mais a caixa SetCreds. */
    public const SUNSHINE_CRED_FIELDS = ['User', 'Password', 'Pin', 'DeviceName', 'SetCreds'];

    /**
     * Os tetos das credenciais do sunshine, em BYTES (strlen), como o
     * MAX_PARAM_BYTES. O worker.ps1 repete os mesmos números na allowlist
     * dele, e o teste de paridade confere.
     *
     * A senha mínima de 8 é nossa: o Sunshine não exige mínimo, e é a senha da
     * tela que controla quem transmite esta máquina. O nome do dispositivo para
     * em 128 porque é o limite do próprio Sunshine.
     */
    public const SUNSHINE_USER_MAX = 64;

    public const SUNSHINE_PASSWORD_MIN = 8;

    public const SUNSHINE_PASSWORD_MAX = 256;

    public const SUNSHINE_DEVICE_MAX = 128;

    /** O nome com que o Moonlight aparece no Sunshine, quando o campo vem vazio. */
    public const SUNSHINE_DEVICE_DEFAULT = 'notebook';

    /**
     * Parâmetros que NUNCA aparecem em claro fora do caminho da execução.
     *
     * O rótulo do histórico (Pages::describe) troca o valor deles por ***, na
     * gravação normal e no recolhimento de órfãs. O worker.ps1 faz o mesmo no
     * win-done-<id>.json com o $SEGREDOS dele. O que sobra em claro é o pedido
     * em files/ enquanto o UAC está na tela (até ~75 s), apagado em todo
     * caminho — ver docs/seguranca.md.
     */
    public const SECRET_PARAMS = ['Password', 'Pin'];

    /** run gera a auditoria; open abre a pasta do log no Explorer. Sem subação, run. */
    public const AUDIT_SUBACTIONS = ['run', 'open'];

    /** on ativa o plano de desempenho máximo; off volta ao Balanceado. Sem State, on. */
    public const PERFORMANCE_STATES = ['on', 'off'];

    /**
     * As execuções que só rodam com UAC próprio, pelo worker de uso único.
     *
     * São as que abrem a máquina para a rede (rdp on, as portas do firewall),
     * instalam programa ou serviço (install, sunshine install, exporter
     * install), registram tarefa SYSTEM (gpu install), trocam a senha de
     * administração do Sunshine (sunshine set-creds) ou autorizam um
     * dispositivo novo a ver e controlar a tela (sunshine pair, da mesma classe
     * do rdp on). Com o PowerShell elevado longo de pé,
     * quem lesse o nonce do marcador rodaria qualquer uma delas sem prompt;
     * por isso o worker longo as RECUSA, e cada uma abre o próprio prompt do
     * Windows (ver OneShot).
     *
     * '*' = toda execução da ação; lista = só essas subações. Esta tabela
     * ROTEIA. A tranca é o $SENSIVEIS do worker.ps1, e o teste de paridade em
     * tests/Unit/WinActionTest.php confere as duas, como faz com as allowlists.
     *
     * @var array<string, '*'|list<string>>
     */
    public const SENSITIVE = [
        'install'  => '*',
        'rdp'      => ['on'],
        'sunshine' => ['install', 'firewall-open', 'set-creds', 'pair'],
        'exporter' => ['install', 'firewall'],
        'gpu'      => ['install'],
    ];

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
            self::Memory,
            self::Processes,
            // O hyperv só lista, e a lista não tem parâmetro: como memory e
            // processes, devolve vazio. A tranca contra parâmetro inventado é
            // o allowlist do worker, que declara 'hyperv' sem nenhum campo.
            self::Hyperv => [],

            self::Audit       => $this->opcional($input, 'SubAction', self::AUDIT_SUBACTIONS),
            self::Performance => $this->opcional($input, 'State', self::PERFORMANCE_STATES),

            self::Tweaks    => $this->tweaks($input),
            self::Debloat   => $this->debloat($input),
            self::Dns       => $this->dns($input),
            self::Install   => $this->install($input),
            self::Network   => $this->network($input),
            // Conjuntos diferentes: o exporter tem firewall, o gpu tem
            // uninstall. Conferido nos dois Invoke-*.
            self::Exporter  => ['SubAction' => $this->pick($input, 'SubAction', self::EXPORTER_SUBACTIONS, obrigatorio: true)],
            self::Gpu       => ['SubAction' => $this->pick($input, 'SubAction', self::GPU_SUBACTIONS, obrigatorio: true)],
            self::Gdid      => ['SubAction' => $this->pick($input, 'SubAction', self::GDID_SUBACTIONS, obrigatorio: true)],
            self::Rdp       => ['SubAction' => $this->pick($input, 'SubAction', self::RDP_SUBACTIONS, obrigatorio: true)],
            self::Sunshine  => $this->sunshine($input),
            self::Optimize  => $this->optimize($input),
        };
    }

    /**
     * Diz se esta execução só roda com UAC próprio (ver SENSITIVE).
     *
     * Recebe os parâmetros JÁ validados: a subação chega na grafia da lista, e
     * a comparação pode ser exata.
     *
     * @param array<string, string|int|bool> $params
     */
    public function isSensitive(array $params): bool
    {
        $regra = self::SENSITIVE[$this->value] ?? null;

        if ($regra === null) {
            return false;
        }

        return $regra === '*' || in_array($params['SubAction'] ?? '', $regra, true);
    }

    /**
     * O que uma execução BEM-SUCEDIDA desta ação significa para o estado.
     *
     * Devolve null para as ações que não afirmam nada sobre estado — as nove
     * que não são reversíveis, e os `status` do gdid e do rdp, que só relatam.
     * Null NÃO é "não aplicado": quem recebe null não mexe no que está
     * guardado. Ver WinStateChange.
     *
     * SEIS AÇÕES SÃO REVERSÍVEIS, e cada uma diz a reversão de um jeito:
     *
     *   tweaks    -Undo com a lista dos tweaks a reverter (-Items) ou um
     *             -Preset. O payload guarda as chaves aplicadas; ver
     *             mergeState().
     *   optimize  -Undo, e ele NÃO precisa de parâmetro: o Invoke-Optimize lê
     *             o próprio runtime/optimize-state.json e recusa sem ele. O
     *             payload guarda o preset só para a tela poder dizer o que
     *             será revertido.
     *   gdid      'disable' aplica e 'enable' reverte — não há -Undo, são duas
     *             subações. O estado real também vive em
     *             runtime/gdid-state.json, escrito pela própria ação.
     *   performance  -State on aplica, -State off reverte ao Balanceado.
     *   rdp       'on'/'off' ligam e desligam o acesso remoto no Applied,
     *             valendo na hora; 'h264-on'/'h264-off' ligam e revertem o vídeo
     *             H.264/UDP no PendingReboot, valendo só depois de reiniciar.
     *             Duas dimensões, dois escopos, cada subação movendo o seu.
     *   sunshine  'start' sobe o serviço e 'stop' o para, no Applied. install,
     *             firewall, status, set-creds e pair não afirmam estado: o
     *             set-creds devolve o serviço a Running, mas quem afirma isso é
     *             o start.
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
                payload: isset($params['Items'])
                    ? ['Items' => $params['Items']]
                    : (isset($params['Preset']) ? ['Preset' => $params['Preset']] : []),
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

            // 'status' só relata: devolve null. As outras quatro movem UM escopo
            // cada — ligar/desligar o acesso remoto vale na hora (Applied); o
            // vídeo H.264/UDP só vale depois de reiniciar (PendingReboot).
            self::Rdp => match ($params['SubAction'] ?? '') {
                'on'       => new WinStateChange(applied: true),
                'off'      => new WinStateChange(applied: false),
                'h264-on'  => new WinStateChange(applied: true, scope: WinStateScope::PendingReboot),
                'h264-off' => new WinStateChange(applied: false, scope: WinStateScope::PendingReboot),
                default    => null,
            },

            // Só o estado do serviço vira 'aplicado': start liga, stop esquece.
            // install, firewall, status, set-creds e pair não afirmam nada.
            self::Sunshine => match ($params['SubAction'] ?? '') {
                'start' => new WinStateChange(applied: true),
                'stop'  => new WinStateChange(applied: false),
                default => null,
            },

            default => null,
        };
    }

    /**
     * O payload aplicado que fica guardado depois de uma mudança de estado.
     *
     * Recebe o payload guardado antes (null se não havia linha), a mudança e
     * os presets reduzidos por WinConfig::presets(). Devolve o payload a
     * gravar, ou null para esquecer a linha.
     *
     * Nos tweaks o aplicado é um CONJUNTO de chaves: aplicar soma ao que
     * estava guardado, reverter subtrai, e o conjunto vazio esquece a linha.
     * Um Preset, guardado ou vindo da mudança, conta como as chaves dele. Nas
     * outras ações a mudança substitui o guardado.
     *
     * @param array<string, string|int|bool>|null $antes
     * @param array<string, list<string>>         $presets
     *
     * @return array<string, string|int|bool>|null
     */
    public function mergeState(?array $antes, WinStateChange $mudanca, array $presets): ?array
    {
        if ($this !== self::Tweaks) {
            return $mudanca->applied ? $mudanca->payload : null;
        }

        $guardadas = self::tweakKeysOf($antes, $presets);
        $destaVez  = self::tweakKeysOf($mudanca->payload, $presets);

        $resultado = $mudanca->applied
            ? array_values(array_unique(array_merge($guardadas, $destaVez)))
            : array_values(array_diff($guardadas, $destaVez));

        return $resultado === [] ? null : ['Items' => implode(',', $resultado)];
    }

    /**
     * As chaves de tweak que um payload dos tweaks guarda.
     *
     * Recebe o payload (null se não há linha) e os presets reduzidos por
     * WinConfig::presets(). Devolve as chaves de Items, ou as do Preset, ou
     * lista vazia.
     *
     * @param array<string, string|int|bool>|null $payload
     * @param array<string, list<string>>         $presets
     *
     * @return list<string>
     */
    public static function tweakKeysOf(?array $payload, array $presets): array
    {
        if ($payload === null) {
            return [];
        }

        if (isset($payload['Items']) && is_string($payload['Items'])) {
            return array_values(array_filter(array_map('trim', explode(',', $payload['Items'])), static fn (string $k): bool => $k !== ''));
        }

        if (isset($payload['Preset']) && is_string($payload['Preset'])) {
            return $presets[strtolower($payload['Preset'])] ?? [];
        }

        return [];
    }

    /**
     * Diz se o botão dos tweaks reverte.
     *
     * Recebe as caixas marcadas e as chaves aplicadas. Devolve true quando há
     * caixa marcada e todas estão entre as aplicadas.
     *
     * @param list<string> $marcadas
     * @param list<string> $aplicadas
     */
    public static function tweaksRevert(array $marcadas, array $aplicadas): bool
    {
        return $marcadas !== [] && array_diff($marcadas, $aplicadas) === [];
    }

    /**
     * @param array<string, string> $input
     *
     * @return array<string, string|bool>
     */
    private function tweaks(array $input): array
    {
        $preset = $this->pick($input, 'Preset', self::TWEAK_PRESETS, obrigatorio: false);
        $itens  = $this->lista($input, 'Items', 'Tweak', static fn (): array => WinConfig::tweakKeys());

        if ($preset !== '' && $itens !== '') {
            throw new InvalidArgumentException('Escolha um preset ou marque tweaks, não os dois.');
        }

        if ($preset === '' && $itens === '') {
            throw new InvalidArgumentException('Marque ao menos um tweak.');
        }

        $params = $itens !== '' ? ['Items' => $itens] : ['Preset' => $preset];

        if ($this->flag($input, 'Undo')) {
            $params['Undo'] = true;
        }

        return $params;
    }

    /**
     * Valida os pacotes do debloat.
     *
     * Recebe os campos do POST. Sem Packages, devolve vazio, e a ação remove
     * os pacotes do debloat.json inteiro. Com o campo PackagesForm, que só o
     * formulário da tela envia, uma lista vazia é recusada em vez de virar
     * "todos".
     *
     * @param array<string, string> $input
     *
     * @return array<string, string>
     */
    private function debloat(array $input): array
    {
        $pacotes = $this->lista($input, 'Packages', 'Pacote', static fn (): array => WinConfig::debloat());

        if ($pacotes === '') {
            if (($input['PackagesForm'] ?? '') === '1') {
                throw new InvalidArgumentException('Marque ao menos um pacote.');
            }

            return [];
        }

        return ['Packages' => $pacotes];
    }

    /**
     * Valida uma lista separada por vírgula contra os itens permitidos.
     *
     * Recebe os campos do POST, o nome do campo, o rótulo do item para a
     * mensagem e quem fornece os itens permitidos. Devolve os itens na grafia
     * da lista permitida, unidos por vírgula, ou string vazia se o campo veio
     * vazio.
     *
     * @param array<string, string>  $input
     * @param callable(): list<string> $permitidos
     *
     * @throws InvalidArgumentException com a frase que vai para a tela
     */
    private function lista(array $input, string $campo, string $rotulo, callable $permitidos): string
    {
        $bruto = $this->texto($input, $campo, obrigatorio: false);

        if ($bruto === '') {
            return '';
        }

        try {
            $validos = $permitidos();
        } catch (RuntimeException $e) {
            throw new InvalidArgumentException('Não foi possível ler a lista de ' . $campo . ': ' . $e->getMessage());
        }

        $porMinuscula = [];

        foreach ($validos as $valido) {
            $porMinuscula[strtolower($valido)] = $valido;
        }

        $aceitos = [];

        foreach (explode(',', $bruto) as $item) {
            $item = trim($item);

            if ($item === '') {
                continue;
            }

            $canonico = $porMinuscula[strtolower($item)] ?? null;

            if ($canonico === null) {
                throw new InvalidArgumentException(sprintf('%s desconhecido: %s.', $rotulo, $item));
            }

            if (in_array($canonico, $aceitos, true)) {
                throw new InvalidArgumentException(sprintf('%s repetido: %s.', $rotulo, $canonico));
            }

            $aceitos[] = $canonico;
        }

        return implode(',', $aceitos);
    }

    /**
     * @param array<string, string> $input
     *
     * @return array<string, string>
     */
    private function dns(array $input): array
    {
        try {
            $providers = [...WinConfig::dnsProviders(), ...self::DNS_EXTRA];
        } catch (RuntimeException $e) {
            throw new InvalidArgumentException('Não foi possível ler a lista de DNS: ' . $e->getMessage());
        }

        $provider = $this->pick($input, 'Provider', $providers, obrigatorio: true);
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
     * As caixas dos principais (AppsMarcados, IDs de INSTALL_SUGGESTIONS) só
     * SOMAM ao campo: o ID marcado que o campo ainda não tem entra no fim.
     *
     * @param array<string, string> $input
     *
     * @return array<string, string>
     */
    private function install(array $input): array
    {
        $apps     = $this->texto($input, 'Apps', obrigatorio: false);
        $marcados = $this->lista($input, 'AppsMarcados', 'App', static fn (): array => array_keys(self::INSTALL_SUGGESTIONS));

        // O Invoke-Install parte por vírgula e descarta vazios; uma entrada
        // que só tem vírgulas passaria por aqui e morreria lá com mensagem
        // pior que esta.
        $itens = array_values(array_filter(array_map('trim', explode(',', $apps)), static fn (string $i): bool => $i !== ''));

        if ($marcados === '') {
            if ($itens === []) {
                throw new InvalidArgumentException('Informe ao menos um app, separados por vírgula, ou marque um dos principais.');
            }

            return ['Apps' => $apps];
        }

        $jaTem = array_map('strtolower', $itens);

        foreach (explode(',', $marcados) as $id) {
            if (!in_array(strtolower($id), $jaTem, true)) {
                $itens[] = $id;
                $jaTem[]  = strtolower($id);
            }
        }

        $juntos = implode(',', $itens);

        if (strlen($juntos) > self::MAX_PARAM_BYTES) {
            throw new InvalidArgumentException(sprintf('Apps passou do teto de %d bytes.', self::MAX_PARAM_BYTES));
        }

        return ['Apps' => $juntos];
    }

    /**
     * Valida o sunshine: a subação e, em set-creds e pair, as credenciais.
     *
     * As seis subações antigas RECUSAM os campos de credencial, em vez de
     * ignorá-los: parâmetro sobrando nunca chega ao worker, e um PIN digitado
     * num pedido que não o usa vira erro na tela em vez de ir para o disco.
     * Pelo mesmo motivo set-creds recusa Pin e DeviceName.
     *
     * A SENHA NÃO SOFRE TRIM: espaço nas pontas é senha. O usuário, o PIN e o
     * nome sofrem. As mensagens citam o NOME do campo, nunca o valor.
     *
     * A COMBINAÇÃO é conferida aqui e de novo no Invoke-Sunshine, como o
     * Invoke-DNS faz com Custom: o worker só confere cada campo sozinho.
     *
     * @param array<string, string> $input
     *
     * @return array<string, string|bool>
     */
    private function sunshine(array $input): array
    {
        $sub    = $this->pick($input, 'SubAction', self::SUNSHINE_SUBACTIONS, obrigatorio: true);
        $params = ['SubAction' => $sub];

        if (!in_array($sub, self::SUNSHINE_CRED_SUBACTIONS, true)) {
            foreach (self::SUNSHINE_CRED_FIELDS as $campo) {
                if (trim($input[$campo] ?? '') !== '') {
                    throw new InvalidArgumentException(sprintf('%s não vale para a subação %s.', $campo, $sub));
                }
            }

            return $params;
        }

        $params['User']     = $this->sunshineUser($input);
        $params['Password'] = $this->sunshinePassword($input);

        if ($sub === 'set-creds') {
            foreach (['Pin', 'DeviceName'] as $campo) {
                if (trim($input[$campo] ?? '') !== '') {
                    throw new InvalidArgumentException(sprintf(
                        'Só gravar credenciais não usa %s: apague o campo, ou use Parear com o PIN.',
                        $campo
                    ));
                }
            }

            return $params;
        }

        $pin = trim($input['Pin'] ?? '');

        if ($pin === '') {
            throw new InvalidArgumentException('Preencha Pin.');
        }

        // Sem /u de propósito: \d com /u casaria dígito de outra escrita, e o
        // Sunshine só aceita 0-9.
        if (preg_match('/\A[0-9]{4}\z/', $pin) !== 1) {
            throw new InvalidArgumentException('Pin deve ter 4 dígitos, de 0 a 9.');
        }

        $params['Pin']        = $pin;
        $params['DeviceName'] = $this->sunshineDevice($input);

        if ($this->flag($input, 'SetCreds')) {
            $params['SetCreds'] = true;
        }

        return $params;
    }

    /**
     * @param array<string, string> $input
     */
    private function sunshineUser(array $input): string
    {
        $user = trim($input['User'] ?? '');

        if ($user === '') {
            throw new InvalidArgumentException('Preencha User.');
        }

        self::semControle('User', $user, self::SUNSHINE_USER_MAX);

        // O cabeçalho Basic separa usuário e senha no PRIMEIRO dois-pontos.
        if (str_contains($user, ':')) {
            throw new InvalidArgumentException("User não pode ter ':'.");
        }

        return $user;
    }

    /**
     * @param array<string, string> $input
     */
    private function sunshinePassword(array $input): string
    {
        $senha = $input['Password'] ?? '';

        if ($senha === '') {
            throw new InvalidArgumentException('Preencha Password.');
        }

        self::semControle('Password', $senha, self::SUNSHINE_PASSWORD_MAX);

        if (strlen($senha) < self::SUNSHINE_PASSWORD_MIN) {
            throw new InvalidArgumentException(sprintf(
                'Password precisa de pelo menos %d bytes.',
                self::SUNSHINE_PASSWORD_MIN
            ));
        }

        return $senha;
    }

    /**
     * @param array<string, string> $input
     */
    private function sunshineDevice(array $input): string
    {
        $nome = trim($input['DeviceName'] ?? '');

        if ($nome === '') {
            return self::SUNSHINE_DEVICE_DEFAULT;
        }

        self::semControle('DeviceName', $nome, self::SUNSHINE_DEVICE_MAX);

        return $nome;
    }

    /**
     * Confere UTF-8 válido, teto em bytes e ausência de caractere de controle.
     *
     * UTF-8 inválido é recusado aqui porque o pedido é JSON: o json_encode do
     * OneShot falharia mais adiante com uma frase que não diz qual campo.
     *
     * @throws InvalidArgumentException citando o campo, nunca o valor
     */
    private static function semControle(string $campo, string $valor, int $teto): void
    {
        if (!mb_check_encoding($valor, 'UTF-8')) {
            throw new InvalidArgumentException(sprintf('%s tem bytes que não são texto UTF-8.', $campo));
        }

        if (strlen($valor) > $teto) {
            throw new InvalidArgumentException(sprintf('%s passou do teto de %d bytes.', $campo, $teto));
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $valor) === 1) {
            throw new InvalidArgumentException(sprintf('%s não pode ter caractere de controle.', $campo));
        }
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
     * Valida um campo opcional de conjunto fechado.
     *
     * Recebe os campos do POST, o nome do campo e os valores permitidos.
     * Devolve [campo => valor] na grafia da lista, ou vazio se o campo não veio.
     *
     * @param array<string, string> $input
     * @param list<string>          $permitidos
     *
     * @return array<string, string>
     */
    private function opcional(array $input, string $campo, array $permitidos): array
    {
        $valor = $this->pick($input, $campo, $permitidos, obrigatorio: false);

        return $valor === '' ? [] : [$campo => $valor];
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
