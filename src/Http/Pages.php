<?php

declare(strict_types=1);

namespace App\Http;

use App\Config\Config;
use App\Config\Flags;
use App\Domain\Execution;
use App\Domain\ExecutionKind;
use App\Domain\OutputCap;
use App\Domain\WinState;
use App\Domain\WinStateScope;
use App\Services\ExecutionLogService;
use App\Win\Elevation;
use App\Win\JobChannel;
use App\Win\WinAction;
use App\Win\WinConfig;
use App\Wsl\Distro;
use App\Wsl\InputLimit;
use App\Wsl\Runner;
use App\Wsl\ScriptBuilder;
use Closure;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * As três telas.
 *
 * O SERVICE É LAZY de propósito, herdado do desenho da fase 1: a home e a
 * /config não tocam no banco, e não devem falhar porque o banco está fora do
 * ar. Só quem grava ou lista abre conexão.
 *
 * Não há estado de resultado em sessão: o painel de saída mostra sempre o
 * registro mais recente do banco. O log é a fonte de verdade, então um F5
 * depois de executar reexibe o que aconteceu em vez de executar de novo —
 * é o POST-redirect-GET que evita a reexecução acidental.
 *
 * @phpstan-import-type AppConfig from \App\Config\Config
 */
final class Pages
{
    /** Quantos registros a tabela mostra. */
    private const ROWS = 100;

    /** Custo medido de acordar a VM do WSL, arredondado para cima. */
    private const COLD_START_S = 5;

    /**
     * @param AppConfig                            $config
     * @param Closure(): ExecutionLogService       $makeService
     */
    public function __construct(
        private readonly array $config,
        private readonly Distro $distroChecker,
        private readonly Closure $makeService,
        private readonly string $filesDir,
        private readonly Elevation $elevation,
    ) {
    }

    public function home(): never
    {
        $status = $this->distroChecker->status();
        $win    = $this->elevation->state();

        $view = new HomeView(
            wslEnabled: $status->isUsable(),
            wslReason: $status->reason($this->config['wsl']['distro']),
            distro: $this->config['wsl']['distro'],
            status: $status,
            winEnabled: $win->on,
            // Três respostas, e a home só tem uma linha para dar: problema de
            // configuração primeiro, porque é o que não se resolve clicando;
            // depois o que houve com uma tentativa; e por último o estado
            // normal de quem acabou de subir o servidor.
            winReason: $win->blocked
                ?? $win->detail
                ?? 'O PowerShell elevado está desligado. Ligue na configuração — ele não sobrevive a reiniciar o servidor.',
        );

        Respond::html('PHPorto', Respond::render('home.php', $view));
    }

    public function config(string $method): never
    {
        if ($method === 'POST') {
            $this->handleConfigPost();
        }

        $provider = $this->config['db_provider'];
        $details  = match ($provider) {
            'sqlite' => [
                ['label' => 'arquivo', 'value' => $this->config['sqlite']['path']],
                ['label' => 'tabela', 'value' => $this->config['sqlite']['table']],
            ],
            'mysql' => [
                ['label' => 'host', 'value' => $this->config['mysql']['host'] . ':' . $this->config['mysql']['port']],
                ['label' => 'banco', 'value' => $this->config['mysql']['database']],
                ['label' => 'tabela', 'value' => $this->config['mysql']['table']],
            ],
            'mongo' => [
                ['label' => 'banco', 'value' => $this->config['mongo']['database']],
                ['label' => 'coleção', 'value' => $this->config['mongo']['collection']],
            ],
            default => [],
        };

        // Só o sqlite mora em arquivo. Nos outros bancos "apagar" seria dropar
        // um schema que a ferramenta não criou e pode não ser só dela — a tela
        // esconde a opção em vez de oferecer um botão que recusa depois.
        $dbPath = $provider === 'sqlite' ? $this->config['sqlite']['path'] : '';

        $win = $this->elevation->state();

        $view = new ConfigView(
            provider: $provider === '' ? 'desconhecido' : $provider,
            details: $details,
            apiEnabled: $this->config['api_enabled'],
            apiFromEnv: Config::apiEnabledFromEnv(),
            apiOverridden: array_key_exists('api_enabled', Flags::all()),
            corsOrigin: $this->config['cors_origin'],
            dbPath: $dbPath,
            dbExists: $dbPath !== '' && is_file($dbPath),
            notice: $this->takeFlash(),
            csrfToken: Csrf::token(),
            csrfField: Csrf::fieldName(),
            win: $win,
            winRetry: $this->takeWinRetry(),
            winProofTimeout: Elevation::PROOF_TIMEOUT_S,
        );

        Respond::html('PHPorto — configuração', Respond::render('config.php', $view));
    }

    /**
     * POST da /config: alternar a API e restaurar de fábrica.
     *
     * Mesmo contrato do /wsl — token de uso único e 303 — porque esta rota
     * passou a ter efeito colateral, e um F5 sobre um POST que apaga banco é
     * exatamente o acidente que o PRG existe para impedir.
     */
    private function handleConfigPost(): never
    {
        if (!Csrf::consume()) {
            $this->flash(
                'Requisição recusada: o token desta página já foi usado, ou está ausente. '
                . 'Recarregue a página e tente de novo.'
            );
            Respond::redirect('/config');
        }

        $action = $_POST['acao'] ?? '';
        $action = is_string($action) ? $action : '';

        if ($action === 'api') {
            $ligar = ($_POST['api_enabled'] ?? '') === '1';

            if (!Flags::set('api_enabled', $ligar)) {
                $this->flash(
                    'Não foi possível gravar ' . Flags::path() . '. '
                    . 'Verifique a permissão de escrita da pasta storage/.'
                );
                Respond::redirect('/config');
            }

            $this->flash(
                $ligar
                    ? 'API ligada. /api/executions passa a executar comandos, aceitando apenas a origem '
                        . $this->config['cors_origin'] . '.'
                    : 'API desligada. /api/executions volta a responder 404.'
            );
            Respond::redirect('/config');
        }

        if ($action === 'powershell') {
            $this->handlePowerShellToggle(($_POST['ps_enabled'] ?? '') === '1');
        }

        if ($action === 'fabrica') {
            $apagarBanco = ($_POST['apagar_banco'] ?? '') === '1';
            $partes      = [];

            $partes[] = Flags::reset()
                ? 'configurações voltaram ao .env'
                : 'FALHA ao apagar ' . Flags::path();

            if ($apagarBanco) {
                $partes[] = $this->dropSqliteFile();
            }

            $this->flash('Restauração de fábrica: ' . implode('; ', $partes) . '.');
            Respond::redirect('/config');
        }

        $this->flash('Ação desconhecida.');
        Respond::redirect('/config');
    }

    /**
     * Liga ou desliga o PowerShell elevado.
     *
     * LIGAR NÃO É GRAVAR UM BOOLEANO: abre um processo elevado e espera a
     * prova de que ele funciona. É por isso que este caminho pode demorar e
     * pode falhar — e falhar aqui é resposta legítima, não erro do servidor:
     * quem recusa a elevação é o Windows, a pedido de quem está na frente da
     * máquina.
     *
     * DESLIGAR NÃO PERGUNTA e não pode falhar por permissão: o PHP não mata o
     * processo elevado (medido: taskkill devolve "Acesso negado"), então
     * deixa uma ordem em arquivo e o worker obedece no próprio laço.
     */
    private function handlePowerShellToggle(bool $ligar): never
    {
        if (!$ligar) {
            $this->flash($this->elevation->disable());
            Respond::redirect('/config');
        }

        $erro = $this->elevation->enable();

        if ($erro === null) {
            $estado = $this->elevation->state();

            $this->flash(
                'PowerShell elevado de pé'
                . ($estado->psPid === null ? '' : ' (PID ' . $estado->psPid . ')')
                . '. O botão WIN está liberado. Este estado é de vida curta: reiniciar o servidor desliga.'
            );
            Respond::redirect('/config');
        }

        // O botão "tentar novamente" aparece por causa desta marca, e ela é
        // de uso único como o flash: recarregar a tela depois de ler o aviso
        // não deve continuar oferecendo a retentativa de uma tentativa que
        // já passou.
        Csrf::start();
        $_SESSION['phporto_win_retry'] = true;

        $this->flash($erro);
        Respond::redirect('/config');
    }

    /**
     * Apaga o arquivo do sqlite. Devolve a frase que vai para o aviso.
     *
     * Não fecha conexão antes porque não há: o service é lazy e este caminho
     * nunca o instancia. O arquivo é recriado vazio no próximo acesso ao banco.
     */
    private function dropSqliteFile(): string
    {
        if ($this->config['db_provider'] !== 'sqlite') {
            return 'banco não apagado (só o sqlite mora em arquivo)';
        }

        $path = $this->config['sqlite']['path'];

        if (!is_file($path)) {
            return 'não havia arquivo de banco para apagar';
        }

        return @unlink($path)
            ? 'banco apagado (' . basename($path) . ')'
            : 'FALHA ao apagar o banco: o arquivo pode estar aberto por outro processo';
    }

    /**
     * A tela /win.
     *
     * Mesmo desenho do /wsl: POST executa e redireciona, GET mostra o
     * registro mais recente do banco. A diferença é o filtro por tipo — sem
     * ele, esta tela mostraria a última execução do WSL logo depois de
     * alguém rodar uma ação do Windows.
     */
    public function win(string $method): never
    {
        // ANTES DE TUDO, inclusive antes do POST, e a ordem não é detalhe: o
        // send() limpa os arquivos de saída e conclusão antes de mandar o job
        // novo. Recolher depois seria recolher o que o próprio POST acabou de
        // apagar.
        $this->collectOrphanRuns();

        if ($method === 'POST') {
            $this->handleWinPost();
        }

        $rows   = [];
        $failed = null;

        try {
            $rows = ($this->makeService)()->recent(self::ROWS, [ExecutionKind::Windows]);
        } catch (Throwable $e) {
            $failed = 'Não foi possível ler os registros: ' . $e->getMessage();
        }

        $debloat        = [];
        $debloatProblem = null;

        try {
            $debloat = WinConfig::debloat();
        } catch (RuntimeException $e) {
            $debloatProblem = $e->getMessage();
        }

        $tweaks        = [];
        $presets       = [];
        $tweaksProblem = null;

        try {
            $tweaks  = WinConfig::tweaks();
            $presets = WinConfig::presets();
        } catch (RuntimeException $e) {
            $tweaksProblem = $e->getMessage();
        }

        $selecoes = [];

        try {
            $selecoes = ($this->makeService)()->winStates(WinStateScope::Selection);
        } catch (Throwable) {
            // Sem seleção guardada a tela nasce no padrão; não há o que avisar.
        }

        $aplicados = [];

        try {
            $aplicados = ($this->makeService)()->winStates(WinStateScope::Applied);
        } catch (Throwable) {
            // Sem estado a tela oferece "Aplicar", como antes da R1.
        }

        $dnsProviders = [];
        $dnsProblem   = null;

        try {
            foreach ([...WinConfig::dnsProviders(), ...WinAction::DNS_EXTRA] as $chave) {
                [$rotulo, $texto] = WinConfig::DNS_TEXTS[$chave] ?? [str_replace('_', ' ', $chave), ''];
                $dnsProviders[]    = ['key' => $chave, 'label' => $rotulo, 'text' => $texto];
            }
        } catch (RuntimeException $e) {
            $dnsProblem = $e->getMessage();
        }

        $dnsEscolha = [];
        foreach ($selecoes[WinAction::Dns->value]->payload ?? [] as $campo => $valor) {
            if (is_string($valor)) {
                $dnsEscolha[$campo] = $valor;
            }
        }

        $tweaksMarcados  = self::savedSelection($selecoes, WinAction::Tweaks, 'Items') ?? [];
        $debloatMarcados = self::savedSelection($selecoes, WinAction::Debloat, 'Packages') ?? $debloat;

        $view = new WinView(
            rows: $rows,
            result: $rows[0] ?? null,
            blocked: $failed ?? $this->winBlockingReason(),
            notice: $this->takeFlash(),
            csrfToken: Csrf::token(),
            csrfField: Csrf::fieldName(),
            win: $this->elevation->state(),
            timeout: $this->config['winutil']['timeout'],
            tz: $this->config['tz'],
            maxOutputBytes: OutputCap::MAX_OUTPUT_BYTES,
            maxParamBytes: WinAction::MAX_PARAM_BYTES,
            debloatPackages: $debloat,
            debloatProblem: $debloatProblem,
            debloatChecked: $debloatMarcados,
            tweaks: $tweaks,
            tweakPresets: $presets,
            tweaksChecked: $tweaksMarcados,
            tweaksMatch: WinConfig::matchPreset($tweaksMarcados, $presets),
            tweaksProblem: $tweaksProblem,
            tweaksApplied: WinAction::tweakKeysOf($aplicados[WinAction::Tweaks->value]->payload ?? null, $presets),
            appliedActions: array_keys($aplicados),
            dnsProviders: $dnsProviders,
            dnsChosen: $dnsEscolha,
            dnsProblem: $dnsProblem,
        );

        Respond::html('PHPorto — Windows', Respond::render('win.php', $view));
    }

    /**
     * POST da /win: valida, manda para o worker elevado, registra, redireciona.
     *
     * A VALIDAÇÃO É AQUI E TAMBÉM LÁ. Esta é a primeira barreira, a que sabe
     * escrever uma frase que a pessoa entende; a allowlist do worker é a
     * tranca, e é ela que cobre quem escrever no arquivo de trabalho sem
     * passar por esta tela.
     */
    private function handleWinPost(): never
    {
        if (!Csrf::consume()) {
            $this->flash(
                'Requisição recusada: o token desta página já foi usado, ou está ausente. '
                . 'Cada envio vale uma execução — recarregue a página para enviar de novo.'
            );
            Respond::redirect('/win');
        }

        $acaoBruta = $_POST['acao'] ?? '';
        $acaoBruta = is_string($acaoBruta) ? $acaoBruta : '';

        if ($acaoBruta === 'limpar') {
            try {
                $n = ($this->makeService)()->clear([ExecutionKind::Windows]);
                $this->flash($n === 0 ? 'Não havia registro do Windows para apagar.' : $n . ' registro(s) do Windows apagado(s).');
            } catch (Throwable $e) {
                $this->flash('Falha ao limpar: ' . $e->getMessage());
            }
            Respond::redirect('/win');
        }

        $acao = WinAction::tryFrom($acaoBruta);

        if ($acao === null) {
            $this->flash('Ação desconhecida.');
            Respond::redirect('/win');
        }

        $blocked = $this->winBlockingReason();

        if ($blocked !== null) {
            $this->flash($blocked);
            Respond::redirect('/win');
        }

        // Só os campos que chegaram como texto, ou como lista de textos — a
        // lista de checkboxes (Items[], Packages[]), que vira texto separado
        // por vírgula. O resto não é entrada válida de formulário, e deixar
        // passar viraria um TypeError lá dentro.
        $entrada = [];
        foreach ($_POST as $chave => $valor) {
            if (!is_string($chave)) {
                continue;
            }

            if (is_string($valor)) {
                $entrada[$chave] = $valor;
            } elseif (is_array($valor) && array_is_list($valor) && array_filter($valor, 'is_string') === $valor) {
                $entrada[$chave] = implode(',', $valor);
            }
        }

        try {
            $params = $acao->validate($entrada);
        } catch (InvalidArgumentException $e) {
            $this->flash($e->getMessage());
            Respond::redirect('/win');
        }

        $this->saveWinSelection($acao, $params);

        $nonce = $this->elevation->nonce();

        if ($nonce === null) {
            // Corrida real: o interruptor foi desligado entre o GET que
            // desenhou o formulário e este POST.
            $this->flash('O PowerShell elevado não está mais de pé. Ligue de novo na configuração.');
            Respond::redirect('/win');
        }

        $canal = new JobChannel($this->filesDir);

        try {
            $run = $canal->dispatch($acao, $params, $nonce, $this->config['winutil']['timeout']);
        } catch (RuntimeException $e) {
            $this->flash($e->getMessage());
            Respond::redirect('/win');
        }

        $execution = new Execution(
            // O que se registra é a linha de comando EQUIVALENTE, e não o
            // JSON do arquivo de trabalho: o log tem de dizer o que foi feito
            // numa forma que a pessoa possa repetir no terminal.
            command: self::describe($acao, $params),
            output: $run->output,
            exitCode: $run->exitCode,
            durationMs: $run->durationMs,
            kind: ExecutionKind::Windows,
            timedOut: $run->timedOut,
        );

        try {
            ($this->makeService)()->record($execution);
        } catch (InvalidArgumentException $e) {
            $this->flash('Registro recusado: ' . $e->getMessage());
        } catch (Throwable $e) {
            // A ação ACONTECEU; só o registro falhou. Numa tela que executa
            // com privilégio de Administrador, dizer isso é o mínimo.
            $this->flash('A ação executou, mas o registro falhou: ' . $e->getMessage());
        }

        // DEPOIS do registro, e independente dele: a linha do histórico diz o
        // que aconteceu, e esta diz como a máquina ficou. Se a gravação do
        // histórico falhou, a ação ainda aconteceu e o estado ainda mudou.
        $this->applyWinState($acao, $params, $run->exitCode, $run->timedOut);

        Respond::redirect('/win');
    }

    /**
     * Anota o que esta execução significa para o estado aplicado da /win.
     *
     * SÓ EXIT 0 MOVE O ESTADO, e o resto fica como está. Timeout, código
     * diferente de zero e código NULO — que é o que o worker deixa quando
     * matou o filho por ordem ou por desaparecimento do pai — todos significam
     * "não se sabe o que ficou feito", e nos três o certo é não mexer:
     *
     *   numa APLICAÇÃO que falhou, gravar "aplicado" seria afirmar sem base, e
     *   é exatamente a afirmação que esta fatia não pode fazer;
     *
     *   numa REVERSÃO que falhou, esquecer a linha faria a tela oferecer
     *   "Aplicar" no que provavelmente continua aplicado — e manter o
     *   "Reverter" pelo menos deixa o botão do reparo à mão.
     *
     * E EXIT 0 AINDA É SINAL FRACO, o que está registrado aqui para ninguém
     * ler esta linha como garantia. Medido no Invoke-Tweaks: ele captura o erro
     * de CADA item, escreve ERROR e segue, termina com OK e sai 0; preset
     * inexistente também sai 0. Ou seja, "aplicado" quer dizer "esta
     * ferramenta mandou aplicar e o script terminou sem estourar", não "os 22
     * tweaks estão no registro". A saída, que fica no histórico, é o que diz
     * item por item.
     *
     * FALHA DE BANCO AQUI NÃO DERRUBA A PÁGINA. A ação já aconteceu e o
     * histórico já tem a linha; o que se perde é o rótulo certo no botão. Avisa
     * e segue, porque o contrário — estourar depois de uma ação de
     * Administrador — deixaria a pessoa sem ver o resultado do que rodou.
     *
     * @param array<string, string|int|bool> $params
     */
    private function applyWinState(
        ?WinAction $acao,
        array $params,
        ?int $exitCode,
        bool $timedOut
    ): void {
        if ($acao === null || $timedOut || $exitCode !== 0) {
            return;
        }

        $mudanca = $acao->stateChange($params);

        // Null é "esta execução não afirma nada sobre estado" — as nove ações
        // não reversíveis e o gdid status. Não é "não aplicado".
        if ($mudanca === null) {
            return;
        }

        try {
            $servico = ($this->makeService)();
            $antes   = $servico->winStates(WinStateScope::Applied)[$acao->value] ?? null;
            $presets = $acao === WinAction::Tweaks ? WinConfig::presets() : [];
            $depois  = $acao->mergeState($antes?->payload, $mudanca, $presets);

            if ($depois !== null) {
                $servico->putWinState(new WinState(
                    scope: WinStateScope::Applied,
                    action: $acao->value,
                    payload: $depois,
                ));

                return;
            }

            // Nada ficou aplicado: APAGA em vez de gravar "não aplicado".
            // Ausência e "não aplicado" têm de significar a mesma coisa — ver
            // a nota na interface do provider.
            $servico->forgetWinState(WinStateScope::Applied, $acao->value);
        } catch (Throwable $e) {
            $this->flash(
                'A ação executou e está no histórico, mas não foi possível anotar o estado dela: '
                . $e->getMessage() . ' O botão pode aparecer como "aplicar" no que já está aplicado.'
            );
        }
    }

    /**
     * Guarda o que a pessoa escolheu numa ação que a tela lembra.
     *
     * Recebe a ação e os parâmetros já validados. Grava no escopo de seleção a
     * lista do tweaks (Items) ou do debloat (Packages), ou o provedor do DNS
     * com os endereços do DNS próprio; nas outras ações, não faz nada. Falha
     * de banco vira aviso, sem impedir a execução.
     *
     * @param array<string, string|int|bool> $params
     */
    private function saveWinSelection(WinAction $acao, array $params): void
    {
        $payload = match ($acao) {
            WinAction::Tweaks  => isset($params['Items']) ? ['Items' => $params['Items']] : null,
            WinAction::Debloat => isset($params['Packages']) ? ['Packages' => $params['Packages']] : null,
            WinAction::Dns     => $params,
            default            => null,
        };

        if ($payload === null) {
            return;
        }

        try {
            ($this->makeService)()->putWinState(new WinState(
                scope: WinStateScope::Selection,
                action: $acao->value,
                payload: $payload,
            ));
        } catch (Throwable $e) {
            $this->flash('Não foi possível guardar a escolha desta seção: ' . $e->getMessage());
        }
    }

    /**
     * Devolve as caixas marcadas guardadas de uma ação.
     *
     * Recebe as linhas do escopo de seleção, a ação e o campo da lista.
     * Devolve as chaves guardadas, ou null se não há seleção guardada.
     *
     * @param array<string, WinState> $selecoes
     *
     * @return list<string>|null
     */
    private static function savedSelection(array $selecoes, WinAction $acao, string $campo): ?array
    {
        $valor = $selecoes[$acao->value]->payload[$campo] ?? null;

        if (!is_string($valor)) {
            return null;
        }

        return array_values(array_filter(array_map('trim', explode(',', $valor)), static fn (string $k): bool => $k !== ''));
    }

    /**
     * Grava as execuções que terminaram e nunca viraram linha.
     *
     * POR QUE ISTO EXISTE: a espera é síncrona, e quem grava a linha é a
     * requisição que espera. Se ela morrer — servidor parado, aba fechada,
     * processo do `php -S` encerrado no meio —, o trabalho terminou do outro
     * lado e o registro não aconteceu. Medido no `files/win-worker.log`: duas
     * execuções de `tweaks` mexeram no registro e nos serviços do Windows e
     * não existem no histórico. A promessa do projeto é que o banco diz se
     * algo executou, e sem isto ela não se sustenta.
     *
     * SÓ APAGA O PAR DEPOIS DE GRAVAR. Apagar antes transformaria uma falha de
     * banco na perda definitiva da execução, que é justamente o problema que
     * este método resolve. Falhou, o par fica para a próxima abertura da tela.
     *
     * A PRIMEIRA FALHA INTERROMPE o recolhimento. Se o banco recusou uma, vai
     * recusar as outras, e insistir só encheria a sessão de mensagens sobre o
     * mesmo problema.
     *
     * O AVISO PODE SER SOBRESCRITO num POST, e é aceito: quem acabou de mandar
     * uma ação está olhando o resultado dela, e a linha recolhida está no
     * histórico com a nota que se explica. Num GET — que é o caso comum, abrir
     * a tela depois de reiniciar o servidor — o aviso aparece.
     */
    private function collectOrphanRuns(): void
    {
        $canal  = new JobChannel($this->filesDir);
        $orfas  = $canal->collectOrphans();

        if ($orfas === []) {
            return;
        }

        $gravadas = 0;

        foreach ($orfas as $orfa) {
            $acao = WinAction::tryFrom($orfa->acao);

            $execution = new Execution(
                // Sem ação conhecida o rótulo diz isso, em vez de inventar um
                // nome: o worker só deixa a ação de fora quando recusou o job
                // antes de validá-lo, e a saída registrada explica o motivo.
                command: $acao === null
                    ? 'ação não identificada (recuperada)'
                    : self::describe($acao, $orfa->params),
                output: $orfa->result->output,
                exitCode: $orfa->result->exitCode,
                durationMs: $orfa->result->durationMs,
                kind: ExecutionKind::Windows,
                timedOut: $orfa->result->timedOut,
                createdAt: $orfa->finishedAt,
            );

            try {
                ($this->makeService)()->record($execution);
            } catch (Throwable $e) {
                $this->flash(
                    'Havia execução do Windows sem registro, e gravá-la falhou: ' . $e->getMessage()
                    . ' Ela continua no disco e será tentada de novo.'
                );

                return;
            }

            // O recolhimento também move o estado, porque a execução que ele
            // recolhe ACONTECEU de verdade — só o registro dela é que ficou
            // para trás. Um tweaks que aplicou e cujo servidor morreu na
            // espera está aplicado na máquina, e a tela tem de saber.
            //
            // Na prática o órfão interrompido traz exit NULO, e o
            // applyWinState não mexe em nada: é o caso em que não se sabe o
            // que ficou feito. Quem passa por aqui é o órfão que CONCLUIU com
            // 0 e perdeu só a gravação.
            $this->applyWinState($acao, $orfa->params, $orfa->result->exitCode, $orfa->result->timedOut);

            $canal->discardOrphan($orfa->id);
            $gravadas++;
        }

        $this->flash(
            $gravadas === 1
                ? 'Uma execução do Windows terminou sem ser registrada e acabou de entrar no histórico, '
                    . 'com a hora real de término.'
                : $gravadas . ' execuções do Windows terminaram sem registro e acabaram de entrar no '
                    . 'histórico, com a hora real de término.'
        );
    }

    /**
     * O RÓTULO da execução, para o registro. Não é comando colável.
     *
     * Até a migração ele começava com `winutil`, e naquele tempo era colável de
     * verdade: existia um winutil-cli.ps1 na máquina que aceitava exatamente
     * aqueles parâmetros. Não existe mais — as ações moram em src/Win/actions e
     * quem as chama é o bootstrap, com splatting.
     *
     * Então o prefixo saiu em vez de virar outro nome inventado. Um rótulo que
     * PARECE comando e não roda é pior que um rótulo que não finge: quem
     * copiasse `winutil -Action audit` de uma linha do histórico receberia
     * "termo não reconhecido" e iria procurar defeito onde não há.
     *
     * O que sobrou é a ação e os parâmetros, que é o que a pessoa escolheu na
     * tela. A coluna `kind` do banco já diz que é do Windows.
     *
     * CONSEQUÊNCIA REGISTRADA: o histórico fica com dois formatos. As linhas
     * gravadas antes desta mudança continuam com o prefixo, e reescrevê-las
     * seria falsear o registro — cada linha diz o que a ferramenta chamava
     * naquele dia.
     *
     * @param array<string, string|int|bool> $params
     */
    private static function describe(WinAction $acao, array $params): string
    {
        $partes = [$acao->value];

        foreach ($params as $nome => $valor) {
            if (is_bool($valor)) {
                if ($valor) {
                    $partes[] = '-' . $nome;
                }

                continue;
            }

            $texto    = (string) $valor;
            $partes[] = '-' . $nome . ' ' . (preg_match('/\s/', $texto) === 1 ? '"' . $texto . '"' : $texto);
        }

        return implode(' ', $partes);
    }

    /** O que impede executar uma ação do Windows agora, ou null. */
    private function winBlockingReason(): ?string
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

    public function wsl(string $method): never
    {
        if ($method === 'POST') {
            $this->handlePost();
        }

        $rows   = [];
        $failed = null;

        try {
            $rows = ($this->makeService)()->recent(self::ROWS, ExecutionKind::WSL);
        } catch (Throwable $e) {
            $failed = 'Não foi possível ler os registros: ' . $e->getMessage();
        }

        $blocked = $failed ?? $this->blockingReason();

        $view = new WslView(
            rows: $rows,
            result: $rows[0] ?? null,
            blocked: $blocked,
            notice: $this->takeFlash(),
            csrfToken: Csrf::token(),
            csrfField: Csrf::fieldName(),
            distro: $this->config['wsl']['distro'],
            root: $this->config['wsl']['root'],
            timeout: $this->config['wsl']['timeout'],
            tz: $this->config['tz'],
            coldStartSeconds: self::COLD_START_S,
            maxOutputBytes: OutputCap::MAX_OUTPUT_BYTES,
            maxCommandBytes: InputLimit::MAX_COMMAND_BYTES,
            maxPathBytes: InputLimit::MAX_PATH_BYTES,
            // Só com a distro usável: distro ausente não está "dormindo".
            awake: $blocked === null ? $this->distroChecker->isRunning() : null,
        );

        Respond::html('PHPorto — WSL', Respond::render('wsl.php', $view));
    }

    /** Executa, registra e redireciona. Nunca renderiza direto. */
    private function handlePost(): never
    {
        // consume(), não check(): o token vale por UMA execução. Ver a nota na
        // Csrf — é o que impede uma intenção virar três comandos executados.
        if (!Csrf::consume()) {
            // Sem revelar detalhe: um POST sem token é, por definição, de fora.
            $this->flash(
                'Requisição recusada: o token desta página já foi usado, ou está ausente. '
                . 'Cada envio vale uma execução — recarregue a página para enviar de novo.'
            );
            Respond::redirect('/wsl');
        }

        $action = $_POST['acao'] ?? 'comando';
        $action = is_string($action) ? $action : 'comando';

        if ($action === 'limpar') {
            try {
                $n = ($this->makeService)()->clear(ExecutionKind::WSL);
                $this->flash($n === 0 ? 'Não havia registro do WSL para apagar.' : $n . ' registro(s) do WSL apagado(s).');
            } catch (Throwable $e) {
                $this->flash('Falha ao limpar: ' . $e->getMessage());
            }
            Respond::redirect('/wsl');
        }

        $blocked = $this->blockingReason();
        if ($blocked !== null) {
            $this->flash($blocked);
            Respond::redirect('/wsl');
        }

        if ($action === 'anexo') {
            $src = trim(is_string($_POST['origem'] ?? null) ? $_POST['origem'] : '');
            $dst = trim(is_string($_POST['destino'] ?? null) ? $_POST['destino'] : '');

            if ($src === '' || $dst === '') {
                $this->flash('Preencha origem e destino do anexo.');
                Respond::redirect('/wsl');
            }

            try {
                InputLimit::path('Origem', $src);
                InputLimit::path('Destino', $dst);
            } catch (InvalidArgumentException $e) {
                $this->flash($e->getMessage());
                Respond::redirect('/wsl');
            }

            $this->execute(
                ScriptBuilder::attachment(),
                ScriptBuilder::attachmentRecord($src, $dst),
                ExecutionKind::Anexo,
                ['PHPORTO_SRC' => $src, 'PHPORTO_DST' => $dst],
            );
        }

        $command = is_string($_POST['cmd'] ?? null) ? $_POST['cmd'] : '';

        if (trim($command) === '') {
            $this->flash('Digite um comando.');
            Respond::redirect('/wsl');
        }

        try {
            InputLimit::command($command);
        } catch (InvalidArgumentException $e) {
            $this->flash($e->getMessage());
            Respond::redirect('/wsl');
        }

        $this->execute(
            ScriptBuilder::command($command),
            ScriptBuilder::normalize($command),
            ExecutionKind::Comando,
            [],
        );
    }

    /**
     * @param array<string, string> $extraEnv
     */
    private function execute(string $script, string $record, ExecutionKind $kind, array $extraEnv): never
    {
        $runner = new Runner($this->config['wsl'], $this->filesDir);

        try {
            $run = $runner->run($script, $extraEnv);
        } catch (RuntimeException $e) {
            $this->flash($e->getMessage());
            Respond::redirect('/wsl');
        }

        $execution = new Execution(
            command: $record,
            output: $run->output,
            exitCode: $run->exitCode,
            durationMs: $run->durationMs,
            kind: $kind,
            timedOut: $run->timedOut,
        );

        try {
            $execution = ($this->makeService)()->record($execution);
        } catch (InvalidArgumentException $e) {
            $this->flash('Registro recusado: ' . $e->getMessage());
        } catch (Throwable $e) {
            // A execução ACONTECEU; só o registro falhou. Dizer isso é o mínimo,
            // porque a pessoa precisa saber que o efeito ocorreu sem log.
            $this->flash('O comando executou, mas o registro falhou: ' . $e->getMessage());
        }

        Respond::redirect('/wsl');
    }

    /** O que impede executar agora, ou null. */
    private function blockingReason(): ?string
    {
        if ($this->config['wsl']['root'] === '') {
            return 'PHPORTO_WSL_ROOT está vazio ou ausente no .env. Nada é executado: sem ele '
                . 'o comando rodaria no home do usuário do WSL, em silêncio.';
        }

        $status = $this->distroChecker->status();

        return $status->isUsable() ? null : $status->reason($this->config['wsl']['distro']);
    }

    private function flash(string $message): void
    {
        Csrf::start();
        $_SESSION['phporto_flash'] = $message;
    }

    /** De uso único, como o flash: a retentativa é da tentativa que falhou. */
    private function takeWinRetry(): bool
    {
        Csrf::start();
        $marca = $_SESSION['phporto_win_retry'] ?? false;
        unset($_SESSION['phporto_win_retry']);

        return $marca === true;
    }

    private function takeFlash(): ?string
    {
        Csrf::start();
        $message = $_SESSION['phporto_flash'] ?? null;
        unset($_SESSION['phporto_flash']);

        return is_string($message) && $message !== '' ? $message : null;
    }
}
