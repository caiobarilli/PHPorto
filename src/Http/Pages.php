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
use App\Php\C\CCode;
use App\Php\C\CCompiler;
use App\Php\C\CPanel;
use App\Php\C\CRun;
use App\Php\C\Tcc;
use App\Php\IniKey;
use App\Php\PhpBuild;
use App\Php\PhpIni;
use App\Php\PhpProbe;
use App\Php\PhpReleases;
use App\Php\PhpUpdate;
use App\Services\ExecutionLogService;
use App\Win\Elevation;
use App\Win\HypervGate;
use App\Win\HypervListing;
use App\Win\JobChannel;
use App\Win\OneShot;
use App\Win\PsResult;
use App\Win\PsTerminal;
use App\Win\PsTerminalCommand;
use App\Win\UacPolicy;
use App\Win\SshKeyFolder;
use App\Win\WinAction;
use App\Win\WinConfig;
use App\Wsl\Distro;
use App\Wsl\InputLimit;
use App\Wsl\Runner;
use App\Wsl\RunResult;
use App\Wsl\ScriptBuilder;
use App\Wsl\Upload;
use Closure;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * @phpstan-import-type AppConfig from \App\Config\Config
 */
final class Pages
{
    private const ROWS = 100;

    private const COLD_START_S = 5;

    private const HYPERV_READ_TIMEOUT_S = Config::MIN_WINUTIL_TIMEOUT;

    /**
     * @param AppConfig $config
     * @param Closure(): ExecutionLogService $makeService
     */
    public function __construct(
        private readonly array $config,
        private readonly Distro $distroChecker,
        private readonly Closure $makeService,
        private readonly string $filesDir,
        private readonly Elevation $elevation,
        private readonly HypervGate $hyperv,
        private readonly OneShot $oneShot,
        private readonly UacPolicy $uac,
        private readonly PsTerminal $terminal,
        private readonly CCompiler $c,
        private readonly string $storageDir,
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
            winReason: $win->blocked
                ?? $win->detail
                ?? 'O PowerShell elevado está desligado. Ligue na configuração — ele não sobrevive a reiniciar o servidor.',
            hypervEnabled: $this->hyperv->enabled(),
            hypervReason: 'O painel do Hyper-V está desligado. Ligue na configuração — uma vez ligado, ele sobrevive a reiniciar.',
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

        $dbPath = $provider === 'sqlite' ? $this->config['sqlite']['path'] : '';

        $win        = $this->elevation->state();
        $wslEnabled = $this->distroChecker->enabled();
        $wslDistros = $wslEnabled ? $this->distroChecker->registered() : null;

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
            hypervEnabled: $this->hyperv->enabled(),
            hypervEnabledAt: $this->hyperv->enabledAt(),
            uacOk: $this->uac->blockingReason() === null,
            uacSummary: $this->uac->summary(),
            tz: $this->config['tz'],
            wslDistros: $wslDistros,
            wslRunning: $wslDistros === null ? null : $this->distroChecker->running(),
            wslEnabled: $wslEnabled,
        );

        Respond::html('PHPorto — configuração', Respond::render('config.php', $view));
    }

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

        if ($action === 'hyperv') {
            $this->handleHypervToggle(($_POST['hyperv_enabled'] ?? '') === '1');
        }

        if ($action === WinAction::Wsl->value) {
            $this->handleWslPost();
        }

        if ($action === WinAction::WslEnable->value) {
            $this->handleWslEnablePost();
        }

        if ($action === 'fabrica') {
            $apagarBanco = ($_POST['apagar_banco'] ?? '') === '1';
            $partes      = self::factoryResetParts(Flags::reset(), $this->hyperv);

            if ($apagarBanco) {
                $partes[] = $this->dropSqliteFile();
            }

            $this->flash('Restauração de fábrica: ' . implode('; ', $partes) . '.');
            Respond::redirect('/config');
        }

        $this->flash('Ação desconhecida.');
        Respond::redirect('/config');
    }

    private function handleWslPost(): never
    {
        try {
            $params = self::wslPostParams($_POST);
        } catch (InvalidArgumentException $e) {
            $this->flash($e->getMessage());
            Respond::redirect('/config');
        }

        $blocked = $this->winBlockingReason();

        if ($blocked !== null) {
            $this->flash($blocked);
            Respond::redirect('/config');
        }

        $nonce = $this->elevation->nonce();

        if ($nonce === null) {
            $this->flash('O PowerShell elevado não está mais de pé. Ligue de novo nesta tela.');
            Respond::redirect('/config');
        }

        try {
            $run = (new JobChannel($this->filesDir))
                ->dispatch(WinAction::Wsl, $params, $nonce, $this->config['winutil']['timeout']);
        } catch (RuntimeException $e) {
            $this->flash($e->getMessage());
            Respond::redirect('/config');
        }

        $aviso = self::wslNotice((string) $params['SubAction'], $run);

        try {
            ($this->makeService)()->record(new Execution(
                command: self::describe(WinAction::Wsl, $params),
                output: $run->output,
                exitCode: $run->exitCode,
                durationMs: $run->durationMs,
                kind: ExecutionKind::Windows,
                timedOut: $run->timedOut,
            ));
        } catch (Throwable $e) {
            $aviso .= ' A ação executou, mas o registro falhou: ' . $e->getMessage();
        }

        $this->flash($aviso);
        Respond::redirect('/config');
    }

    private function handleWslEnablePost(): never
    {
        if ($this->distroChecker->enabled()) {
            $this->flash('O WSL já está ativado no Windows. Nada foi executado.');
            Respond::redirect('/config');
        }

        $blocked = $this->oneShot->blockingReason();

        if ($blocked !== null) {
            $this->flash($blocked);
            Respond::redirect('/config');
        }

        try {
            $run = $this->oneShot->dispatch(WinAction::WslEnable, [], $this->config['winutil']['timeout']);
        } catch (RuntimeException $e) {
            $this->flash($e->getMessage());
            Respond::redirect('/config');
        }

        $aviso = self::wslEnableNotice($run);

        try {
            ($this->makeService)()->record(new Execution(
                command: self::describe(WinAction::WslEnable, []),
                output: $run->output,
                exitCode: $run->exitCode,
                durationMs: $run->durationMs,
                kind: ExecutionKind::Windows,
                timedOut: $run->timedOut,
            ));
        } catch (Throwable $e) {
            $aviso .= ' A ação executou, mas o registro falhou: ' . $e->getMessage();
        }

        $this->flash($aviso);
        Respond::redirect('/config');
    }

    public static function wslEnableNotice(PsResult $run): string
    {
        if ($run->timedOut) {
            return 'Ativar o WSL demorou demais e foi cancelado. A saída está no histórico da /win.';
        }

        if ($run->exitCode !== 0) {
            return 'Falha ao ativar o WSL (código '
                . ($run->exitCode === null ? 'nenhum' : $run->exitCode)
                . '). A saída está no histórico da /win.';
        }

        return 'WSL ativado, sem instalar distro. Reinicie o Windows para concluir; '
            . 'até lá o card do WSL continua mostrando o botão Ativar. A saída está no histórico da /win.';
    }

    /**
     * @param array<mixed> $post
     * @return array<string, string|int|bool>
     * @throws InvalidArgumentException
     */
    public static function wslPostParams(array $post): array
    {
        $sub = $post['SubAction'] ?? null;

        return WinAction::Wsl->validate(is_string($sub) ? ['SubAction' => $sub] : []);
    }

    public static function wslNotice(string $sub, PsResult $run): string
    {
        $desligar = $sub === 'shutdown';

        if ($run->timedOut) {
            return ($desligar ? 'Desligar' : 'Acordar')
                . ' o WSL demorou demais e foi cancelado. A saída está no histórico da /win.';
        }

        if ($run->exitCode !== 0) {
            return 'Falha ao ' . ($desligar ? 'desligar' : 'acordar') . ' o WSL (código '
                . ($run->exitCode === null ? 'nenhum' : $run->exitCode)
                . '). A saída está no histórico da /win.';
        }

        return $desligar
            ? 'WSL desligado: todas as distros e a VM do WSL 2 pararam. A saída está no histórico da /win.'
            : 'WSL acordado: a distro padrão subiu. Sem nada rodando dentro, o WSL pode parar de novo '
                . 'depois de um tempo ocioso. A saída está no histórico da /win.';
    }

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

        Csrf::start();
        $_SESSION['phporto_win_retry'] = true;

        $this->flash($erro);
        Respond::redirect('/config');
    }

    /**
     * @return list<string>
     */
    public static function factoryResetParts(bool $flagsApagado, HypervGate $hyperv): array
    {
        return [
            $flagsApagado
                ? 'configurações voltaram ao .env'
                : 'FALHA ao apagar ' . Flags::path(),
            $hyperv->disable()
                ? 'painel do Hyper-V desligado'
                : 'FALHA ao apagar ' . $hyperv->statePath(),
        ];
    }

    private function handleHypervToggle(bool $ligar): never
    {
        if (!$ligar) {
            $this->flash(
                $this->hyperv->disable()
                    ? 'Painel do Hyper-V desligado.'
                    : 'Não foi possível apagar ' . $this->hyperv->statePath()
                        . '. Verifique a permissão de escrita da pasta storage/.'
            );
            Respond::redirect('/config');
        }

        $erro = $this->hyperv->enable();

        $this->flash(
            $erro
            ?? 'Painel do Hyper-V ligado. Ele fica ligado mesmo depois de reiniciar o servidor '
                . 'e o Windows — é decisão sua, não prova de privilégio.'
        );
        Respond::redirect('/config');
    }

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

    public function win(string $method): never
    {
        $this->collectOrphanRuns();

        $aba = WinTab::fromQuery($_GET['aba'] ?? null);

        if ($method === 'POST') {
            $this->handleWinPost($aba);
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
        }

        $aplicados = [];

        try {
            $aplicados = ($this->makeService)()->winStates(WinStateScope::Applied);
        } catch (Throwable) {
        }

        $pendentes = [];

        try {
            $pendentes = ($this->makeService)()->winStates(WinStateScope::PendingReboot);
        } catch (Throwable) {
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
            terminalMaxCommandBytes: PsTerminalCommand::MAX_COMMAND_BYTES,
            terminalMaxOutputBytes: PsTerminalCommand::MAX_OUTPUT_BYTES,
            terminalTimeout: PsTerminalCommand::TIMEOUT_SECONDS,
            debloatPackages: $debloat,
            debloatProblem: $debloatProblem,
            debloatChecked: $debloatMarcados,
            tweaks: WinConfig::translateTweaks($tweaks),
            tweakPresets: $presets,
            tweaksChecked: $tweaksMarcados,
            tweaksMatch: WinConfig::matchPreset($tweaksMarcados, $presets),
            tweaksProblem: $tweaksProblem,
            tweaksApplied: WinAction::tweakKeysOf($aplicados[WinAction::Tweaks->value]->payload ?? null, $presets),
            appliedActions: array_keys($aplicados),
            pendingRebootActions: array_keys($pendentes),
            dnsProviders: $dnsProviders,
            dnsChosen: $dnsEscolha,
            dnsProblem: $dnsProblem,
            tab: $aba,
            sensitiveBlocked: $failed ?? $this->elevation->configProblem(),
            wslDistros: $aba === WinTab::ChavesSsh ? ($this->distroChecker->registered() ?? []) : [],
        );

        Respond::html('PHPorto — Windows', Respond::render('win.php', $view));
    }

    private function handleWinPost(WinTab $aba): never
    {
        if (!Csrf::consume()) {
            $this->flash(
                'Requisição recusada: o token desta página já foi usado, ou está ausente. '
                . 'Cada envio vale uma execução — recarregue a página para enviar de novo.'
            );
            Respond::redirect($aba->url());
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
            Respond::redirect($aba->url());
        }

        if ($acaoBruta === PsTerminalCommand::ACTION) {
            $this->runTerminal($_POST['cmd'] ?? null);
            Respond::redirect($aba->url());
        }

        $acao = WinAction::tryFrom($acaoBruta);

        if ($acao === null) {
            $this->flash('Ação desconhecida.');
            Respond::redirect($aba->url());
        }

        $recusa = self::winPostRefusal($acao);

        if ($recusa !== null) {
            $this->flash($recusa);
            Respond::redirect($aba->url());
        }

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
            Respond::redirect($aba->url());
        }

        if (
            $acao === WinAction::SshKeys
            && ($params['SubAction'] ?? '') === 'wsl-user'
            && !Distro::contains($this->distroChecker->registered() ?? [], (string) ($params['Distro'] ?? ''))
        ) {
            $this->flash('A distro escolhida não aparece em "wsl -l -q". Escolha uma da lista.');
            Respond::redirect($aba->url());
        }

        $recusaChave = self::sshKeyRefusal($acao, $params, getenv('USERPROFILE'));

        if ($recusaChave !== null) {
            $this->flash($recusaChave);
            Respond::redirect($aba->url());
        }

        $sensivel = $acao->isSensitive($params);
        $blocked  = $sensivel ? $this->oneShot->blockingReason() : $this->winBlockingReason();

        if ($blocked !== null) {
            $this->flash($blocked);
            Respond::redirect($aba->url());
        }

        $this->saveWinSelection($acao, $params);

        try {
            if ($sensivel) {
                $run = $this->oneShot->dispatch($acao, $params, $this->config['winutil']['timeout']);
            } else {
                $nonce = $this->elevation->nonce();

                if ($nonce === null) {
                    $this->flash('O PowerShell elevado não está mais de pé. Ligue de novo na configuração.');
                    Respond::redirect($aba->url());
                }

                $run = (new JobChannel($this->filesDir))
                    ->dispatch($acao, $params, $nonce, $this->config['winutil']['timeout']);
            }
        } catch (RuntimeException $e) {
            $this->flash($e->getMessage());
            Respond::redirect($aba->url());
        }

        $execution = new Execution(
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
            $this->flash('A ação executou, mas o registro falhou: ' . $e->getMessage());
        }

        $this->applyWinState($acao, $params, $run->exitCode, $run->timedOut);

        Respond::redirect($aba->url());
    }

    /**
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

        if ($mudanca === null) {
            return;
        }

        try {
            $servico = ($this->makeService)();
            $escopo  = $mudanca->scope;
            $antes   = $servico->winStates($escopo)[$acao->value] ?? null;
            $presets = $acao === WinAction::Tweaks ? WinConfig::presets() : [];
            $depois  = $acao->mergeState($antes?->payload, $mudanca, $presets);

            if ($depois !== null) {
                $servico->putWinState(new WinState(
                    scope: $escopo,
                    action: $acao->value,
                    payload: $depois,
                ));

                return;
            }

            $servico->forgetWinState($escopo, $acao->value);
        } catch (Throwable $e) {
            $this->flash(
                'A ação executou e está no histórico, mas não foi possível anotar o estado dela: '
                . $e->getMessage() . ' O botão pode aparecer como "aplicar" no que já está aplicado.'
            );
        }
    }

    /**
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
     * @param array<string, WinState> $selecoes
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

    private function collectOrphanRuns(): void
    {
        $canal  = new JobChannel($this->filesDir);
        $orfas  = $canal->collectOrphans();

        if ($orfas === []) {
            return;
        }

        $gravadas = 0;
        $presas   = 0;

        foreach ($orfas as $orfa) {
            $acao = WinAction::tryFrom($orfa->acao);

            $execution = new Execution(
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

            $this->applyWinState($acao, $orfa->params, $orfa->result->exitCode, $orfa->result->timedOut);

            if (!$canal->discardOrphan($orfa->id)) {
                $presas++;
            }

            $gravadas++;
        }

        $this->flash(
            ($gravadas === 1
                ? 'Uma execução do Windows terminou sem ser registrada e acabou de entrar no histórico, '
                    . 'com a hora real de término.'
                : $gravadas . ' execuções do Windows terminaram sem registro e acabaram de entrar no '
                    . 'histórico, com a hora real de término.')
            . match (true) {
                $presas === 0 => '',
                $presas === 1 => ' Uma não pôde ser apagada nem marcada como já importada e pode voltar '
                    . 'ao histórico na próxima visita.',
                default => ' ' . $presas . ' não puderam ser apagadas nem marcadas como já importadas e '
                    . 'podem voltar ao histórico na próxima visita.',
            }
        );
    }

    /**
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

            if (in_array($nome, WinAction::SECRET_PARAMS, true)) {
                $partes[] = '-' . $nome . ' ***';

                continue;
            }

            $texto    = (string) $valor;
            $partes[] = '-' . $nome . ' ' . (preg_match('/\s/', $texto) === 1 ? '"' . $texto . '"' : $texto);
        }

        return implode(' ', $partes);
    }

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

    public function hyperv(string $method = 'GET'): never
    {
        if (!$this->hyperv->enabled()) {
            Respond::notFound();
        }

        if ($method === 'POST') {
            $this->handleHypervPost();
        }

        $parte = self::hypervParte($_GET['parte'] ?? null);

        if ($parte === HypervView::PARTE_CASCA) {
            Respond::html('PHPorto — Hyper-V', Respond::render('hyperv.php', new HypervView(
                tz: $this->config['tz'],
                blocked: null,
                listing: null,
                readAtUtc: gmdate(DATE_ATOM),
                notice: $this->takeFlash(),
                csrfToken: Csrf::token(),
                csrfField: Csrf::fieldName(),
                parte: HypervView::PARTE_CASCA,
            )));
        }

        $blocked  = $this->winBlockingReason();
        $listing  = null;
        $timedOut = false;

        if ($blocked === null) {
            $nonce = $this->elevation->nonce();

            if ($nonce === null) {
                $blocked = 'O PowerShell elevado não está mais de pé. Ligue de novo na configuração.';
            } else {
                try {
                    $run = (new JobChannel($this->filesDir))
                        ->dispatch(WinAction::Hyperv, [], $nonce, self::HYPERV_READ_TIMEOUT_S);

                    if ($run->timedOut) {
                        $timedOut = true;
                    } else {
                        $listing = HypervListing::fromOutput($run->output);
                    }
                } catch (RuntimeException $e) {
                    $blocked = $e->getMessage();
                }
            }
        }

        $view = new HypervView(
            tz: $this->config['tz'],
            blocked: $blocked,
            listing: $listing,
            readAtUtc: gmdate(DATE_ATOM),
            timedOut: $timedOut,
            notice: $parte === HypervView::PARTE_TABELA ? null : $this->takeFlash(),
            csrfToken: Csrf::token(),
            csrfField: Csrf::fieldName(),
            actionsBlocked: $this->elevation->configProblem(),
            parte: $parte,
        );

        if ($parte === HypervView::PARTE_TABELA) {
            Respond::partial(Respond::render('hyperv.php', $view));
        }

        Respond::html('PHPorto — Hyper-V', Respond::render('hyperv.php', $view));
    }

    public function php(string $method = 'GET'): never
    {
        if ($method === 'POST') {
            $this->handlePhpPost();
        }

        $caminho = getenv('PATH');
        $ativo   = PhpProbe::current();

        $view = PhpView::make(
            active: $ativo,
            pathBinary: PhpProbe::onPath(is_string($caminho) ? $caminho : '', PATH_SEPARATOR, PhpProbe::executable()),
            ignorarCaixa: PhpProbe::windows(),
            medir: static fn (string $binario): ?PhpBuild => PhpProbe::measure($binario),
        )->with(
            ini: $ativo === null ? null : PhpIni::load($ativo->ini, self::scannedInis(), static fn (string $chave): string|false => ini_get($chave)),
            notice: $this->takeFlash(),
            csrfToken: Csrf::token(),
            csrfField: Csrf::fieldName(),
            update: PhpReleases::plan(
                $ativo,
                PhpProbe::windows(),
                static fn (): ?string => PhpReleases::cached(
                    dirname(__DIR__, 2) . '/storage/php-releases.json',
                    time(),
                    static fn (): ?string => PhpReleases::fetch(PhpReleases::LISTING_URL),
                ),
                static fn (string $binario): ?string => PhpProbe::measure($binario)?->version,
            ),
        )->withC($this->cPanel());

        Respond::html('PHPorto — PHP', Respond::render('php.php', $view));
    }

    private function handlePhpPost(): never
    {
        if (!Csrf::consume()) {
            $this->flash('Sessão expirada ou formulário antigo. Releia a página e tente de novo. Nada foi gravado.');
            Respond::redirect('/php');
        }

        $acao = $_POST['acao'] ?? null;

        if ($acao === Tcc::ACTION) {
            $this->flash(self::tccInstall($this->cPanel(), static fn (string $url): ?string => Tcc::fetch($url)));
            Respond::redirect('/php');
        }

        if ($acao === CPanel::FFI_ACTION) {
            $this->flash(self::ffiExtensionEnable($this->cPanel(), php_ini_loaded_file(), $_POST[PhpIni::HASH_FIELD] ?? null));
            Respond::redirect('/php');
        }

        if ($acao === CCode::ACTION) {
            $this->runC($_POST[CCode::FIELD] ?? null);
            Respond::redirect('/php');
        }

        if (array_key_exists(PhpReleases::POST_FIELD, $_POST)) {
            $this->handlePhpUpdate($_POST[PhpReleases::POST_FIELD]);
        }

        $this->flash(self::phpIniSave(
            php_ini_loaded_file(),
            self::scannedInis(),
            $_POST[PhpIni::HASH_FIELD] ?? null,
            $_POST[PhpIni::FIELD] ?? null,
        ));
        Respond::redirect('/php');
    }

    private function cPanel(): CPanel
    {
        Csrf::start();

        return new CPanel(
            tcc: Tcc::status(Tcc::dir($this->storageDir)),
            windows: PhpProbe::windows(),
            ffiLoaded: extension_loaded('ffi'),
            ffiEnable: IniKey::FfiEnable->normalize((string) ini_get(IniKey::FfiEnable->value)),
            last: CRun::fromArray($_SESSION[CCode::SESSION_KEY] ?? null),
        );
    }

    /**
     * @param Closure(string): ?string $baixar
     */
    public static function tccInstall(CPanel $painel, Closure $baixar): string
    {
        if (!$painel->windows) {
            return 'O pacote oficial do Tiny C Compiler é para Windows x64. Nada foi instalado.';
        }

        return Tcc::install($painel->tcc->dir, $baixar);
    }

    public static function ffiExtensionEnable(CPanel $painel, string|false $ini, mixed $hash): string
    {
        if (!$painel->canEnableFfi()) {
            return $painel->ffiLoaded
                ? 'A extensão FFI já está carregada. O php.ini não foi tocado.'
                : 'Instale o Tiny C Compiler antes de ativar a extensão FFI. Nada foi gravado.';
        }

        if ($ini === false) {
            return 'Nenhum php.ini carregado. Esta tela só grava num php.ini que o PHP já leu. Nada foi gravado.';
        }

        if (!is_string($hash) || preg_match('/\A[0-9a-f]{64}\z/', $hash) !== 1) {
            return 'Formulário sem a marca do php.ini lido. Releia a página. Nada foi gravado.';
        }

        try {
            $mudou = PhpIni::enableExtension($ini, $hash, 'ffi');
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }

        if (!$mudou) {
            return 'extension=ffi já está ativa no php.ini. O php.ini não foi tocado. Feche e suba o php -S de novo para carregar.';
        }

        return 'php.ini gravado: extension=ffi. A versão anterior ficou em ' . basename($ini) . PhpIni::BACKUP_SUFFIX
            . '. Feche e suba o php -S de novo para carregar a extensão.';
    }

    private function runC(mixed $raw): void
    {
        $painel = $this->cPanel();
        $recusa = $painel->blocked();

        if ($recusa !== null) {
            $this->flash($recusa);

            return;
        }

        try {
            $run = CCode::execute($this->c, $painel->tcc, $raw);
        } catch (InvalidArgumentException $e) {
            $this->flash($e->getMessage());

            return;
        }

        $_SESSION[CCode::SESSION_KEY] = $run->toArray();
    }

    private function handlePhpUpdate(mixed $versao): never
    {
        $plano = PhpReleases::plan(
            PhpProbe::current(),
            PhpProbe::windows(),
            static fn (): ?string => PhpReleases::fetch(PhpReleases::LISTING_URL),
            static fn (string $binario): ?string => PhpProbe::measure($binario)?->version,
        );

        try {
            $params = self::phpUpdateParams($plano, $versao);
        } catch (InvalidArgumentException $e) {
            $this->flash($e->getMessage());
            Respond::redirect('/php');
        }

        $bloqueio = $this->oneShot->blockingReason();

        if ($bloqueio !== null) {
            $this->flash($bloqueio);
            Respond::redirect('/php');
        }

        try {
            $run = $this->oneShot->dispatch(WinAction::PhpUpdate, $params, $this->config['winutil']['timeout']);
        } catch (RuntimeException $e) {
            $this->flash($e->getMessage());
            Respond::redirect('/php');
        }

        $aviso = self::phpUpdateNotice($params, $run);

        try {
            ($this->makeService)()->record(new Execution(
                command: self::describe(WinAction::PhpUpdate, $params),
                output: $run->output,
                exitCode: $run->exitCode,
                durationMs: $run->durationMs,
                kind: ExecutionKind::Windows,
                timedOut: $run->timedOut,
            ));
        } catch (Throwable $e) {
            $aviso .= ' A ação executou, mas o registro falhou: ' . $e->getMessage();
        }

        $this->flash($aviso);
        Respond::redirect('/php');
    }

    /**
     * @return array<string, string|int|bool>
     * @throws InvalidArgumentException
     */
    public static function phpUpdateParams(PhpUpdate $plano, mixed $versao): array
    {
        if (!$plano->available() || $plano->release === null || $plano->dir === null) {
            throw new InvalidArgumentException($plano->status . ' Nada foi feito.');
        }

        if ($versao !== $plano->release->version) {
            throw new InvalidArgumentException('A lista oficial mudou desde que a tela abriu: agora o patch é o '
                . $plano->release->version . '. Releia a página. Nada foi feito.');
        }

        return WinAction::PhpUpdate->validate([
            'Version' => $plano->release->version,
            'Sha256'  => $plano->release->sha256,
            'PhpDir'  => $plano->dir,
        ]);
    }

    /**
     * @param array<string, string|int|bool> $params
     */
    public static function phpUpdateNotice(array $params, PsResult $run): string
    {
        $versao = (string) ($params['Version'] ?? '');

        if ($run->timedOut) {
            return 'A atualização do PHP passou do prazo. Confira a pasta do PHP e o histórico do Windows antes de subir o php -S.';
        }

        if ($run->exitCode !== 0) {
            $motivo = preg_match_all('/^\[ ERROR \] (.+)$/m', $run->output, $erros) > 0
                ? ' ' . trim((string) end($erros[1]))
                : '';

            return 'A atualização do PHP para ' . $versao . ' não foi feita.' . $motivo . ' Detalhes no histórico do Windows.';
        }

        if (preg_match('/^\[ OK \] Backup: (.+)$/m', $run->output, $backup) === 1) {
            return 'PHP atualizado para ' . $versao . ' na mesma pasta. A versão anterior ficou em '
                . trim($backup[1]) . '. Feche o php -S e suba de novo para usar a versão nova.';
        }

        return 'O php.exe da pasta já é o ' . $versao . '. Nada mudou. Feche o php -S e suba de novo para usar a versão nova.';
    }

    /**
     * @param list<string> $escaneados
     */
    public static function phpIniSave(string|false $ini, array $escaneados, mixed $hash, mixed $enviado): string
    {
        if ($ini === false) {
            return 'Nenhum php.ini carregado. Esta tela só grava num php.ini que o PHP já leu. Nada foi gravado.';
        }

        $form = PhpIni::load($ini, $escaneados, static fn (string $chave): false => false);

        if ($form === null || $form->error !== null) {
            return ($form->error ?? 'Não deu para ler o php.ini.') . ' Nada foi gravado.';
        }

        if (!is_string($hash) || preg_match('/\A[0-9a-f]{64}\z/', $hash) !== 1) {
            return 'Formulário sem a marca do php.ini lido. Releia a página. Nada foi gravado.';
        }

        try {
            $novos = PhpIni::changes($form->rows, $enviado);

            if ($novos === []) {
                return 'Nenhum valor mudou. O php.ini não foi tocado.';
            }

            PhpIni::write($ini, $hash, $novos);
        } catch (InvalidArgumentException|RuntimeException $e) {
            return $e->getMessage();
        }

        $partes = [];

        foreach ($novos as $nome => $valor) {
            $partes[] = $nome . ' = ' . $valor;
        }

        return 'php.ini gravado: ' . implode(', ', $partes) . '. A versão anterior ficou em '
            . basename($ini) . PhpIni::BACKUP_SUFFIX . '. Feche e suba o php -S de novo para valer.';
    }

    /**
     * @return list<string>
     */
    private static function scannedInis(): array
    {
        return PhpProbe::iniFiles(null, php_ini_scanned_files());
    }

    public static function hypervParte(mixed $valor): string
    {
        return match ($valor) {
            null, ''                     => HypervView::PARTE_CASCA,
            HypervView::PARTE_TABELA     => HypervView::PARTE_TABELA,
            default                      => HypervView::PARTE_COMPLETA,
        };
    }

    private function handleHypervPost(): never
    {
        if (!Csrf::consume()) {
            $this->flash(
                'Requisição recusada: o token desta página já foi usado, ou está ausente. '
                . 'Cada envio vale uma execução — recarregue a página para enviar de novo.'
            );
            Respond::redirect('/hyperv');
        }

        try {
            $params = self::hypervPostParams($_POST);
        } catch (InvalidArgumentException $e) {
            $this->flash($e->getMessage());
            Respond::redirect('/hyperv');
        }

        $blocked = $this->oneShot->blockingReason();

        if ($blocked !== null) {
            $this->flash($blocked);
            Respond::redirect('/hyperv');
        }

        try {
            $run = $this->oneShot->dispatch(WinAction::HypervVm, $params, $this->config['winutil']['timeout']);
        } catch (RuntimeException $e) {
            $this->flash($e->getMessage());
            Respond::redirect('/hyperv');
        }

        $aviso = self::hypervVmNotice($run);

        try {
            ($this->makeService)()->record(new Execution(
                command: self::describe(WinAction::HypervVm, $params),
                output: $run->output,
                exitCode: $run->exitCode,
                durationMs: $run->durationMs,
                kind: ExecutionKind::Windows,
                timedOut: $run->timedOut,
            ));
        } catch (Throwable $e) {
            $aviso .= ' A ação executou, mas o registro falhou: ' . $e->getMessage();
        }

        $this->flash($aviso);
        Respond::redirect('/hyperv');
    }

    /**
     * @param array<mixed> $post
     * @return array<string, string|int|bool>
     * @throws InvalidArgumentException
     */
    public static function hypervPostParams(array $post): array
    {
        if (($post['acao'] ?? null) !== WinAction::HypervVm->value) {
            throw new InvalidArgumentException('Ação desconhecida.');
        }

        $entrada = [];
        foreach (['SubAction', 'VmId'] as $campo) {
            if (isset($post[$campo]) && is_string($post[$campo])) {
                $entrada[$campo] = $post[$campo];
            }
        }

        return WinAction::HypervVm->validate($entrada);
    }

    public static function hypervVmNotice(PsResult $run): string
    {
        if ($run->timedOut) {
            return 'A ação na VM demorou demais e foi cancelada. Atualize a lista para ver como ela ficou.';
        }

        $inicio = strpos($run->output, '{');
        $fim    = strrpos($run->output, '}');
        $dados  = $inicio !== false && $fim !== false && $fim > $inicio
            ? json_decode(substr($run->output, $inicio, $fim - $inicio + 1), true)
            : null;

        if (!is_array($dados) || !is_string($dados['mensagem'] ?? null) || $dados['mensagem'] === '') {
            return 'A ação na VM terminou sem uma resposta legível (código '
                . ($run->exitCode === null ? 'nenhum' : $run->exitCode)
                . '). A saída está no histórico da /win.';
        }

        $nome = is_string($dados['name'] ?? null) && $dados['name'] !== '' ? $dados['name'] . ': ' : '';

        return $nome . $dados['mensagem'];
    }

    /**
     * @param array<string, string|int|bool> $params
     */
    public static function sshKeyRefusal(WinAction $acao, array $params, string|false $perfil): ?string
    {
        if ($acao !== WinAction::SshKeys || ($params['SubAction'] ?? '') !== 'generate') {
            return null;
        }

        $pasta = SshKeyFolder::folder($perfil);

        return $pasta === null ? null : SshKeyFolder::refusal($pasta, (string) ($params['KeyName'] ?? ''));
    }

    public static function winPostRefusal(WinAction $acao): ?string
    {
        return match ($acao) {
            WinAction::HypervVm  => 'Ligar, desligar e reiniciar VM é pela tela do Hyper-V, não pela /win.',
            WinAction::Wsl       => 'Desligar e acordar o WSL é pela configuração, não pela /win.',
            WinAction::WslEnable => 'Ativar o WSL é pela configuração, não pela /win.',
            WinAction::PhpUpdate => 'Atualizar o PHP é pela tela do PHP, não pela /win.',
            default              => null,
        };
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
            awake: $blocked === null ? $this->distroChecker->isRunning() : null,
            maxUploadBytes: Upload::limit(),
        );

        Respond::html('PHPorto — WSL', Respond::render('wsl.php', $view));
    }

    private function handlePost(): never
    {
        if (Upload::postOverflow($_SERVER, $_POST, $_FILES)) {
            $this->flash(sprintf(
                'O envio passou do limite do servidor (post_max_size = %s). Nada foi copiado. O limite por arquivo é %s.',
                (string) ini_get('post_max_size'),
                Upload::megabytes(Upload::limit()),
            ));
            Respond::redirect('/wsl');
        }

        if (!Csrf::consume()) {
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
            $this->handleUpload();
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

    private function handleUpload(): never
    {
        $upload = Upload::forRequest($this->filesDir);

        try {
            $dst     = Upload::destination($_POST['destino'] ?? null);
            $arquivo = $upload->accept($_FILES[Upload::FIELD] ?? null, Upload::limit());
        } catch (InvalidArgumentException $e) {
            $this->flash($e->getMessage());
            Respond::redirect('/wsl');
        }

        $this->runAndRecord(
            function (Runner $runner) use ($upload, $arquivo, $dst): RunResult {
                $run = $upload->copy($arquivo, $dst, $runner->run(...));
                if ($run->exitCode === Upload::EXIT_DESTINATION_EXISTS) {
                    $this->flash('O destino já existe no WSL. Nada foi copiado; escolha outro nome.');
                }

                return $run;
            },
            Upload::record($arquivo['name'], $arquivo['size'], $dst),
            ExecutionKind::Anexo,
        );
    }

    /**
     * @param array<string, string> $extraEnv
     */
    private function execute(string $script, string $record, ExecutionKind $kind, array $extraEnv): never
    {
        $this->runAndRecord(
            static fn (Runner $runner): RunResult => $runner->run($script, $extraEnv),
            $record,
            $kind,
        );
    }

    private function runTerminal(mixed $raw): void
    {
        try {
            $execution = PsTerminalCommand::execute($this->terminal, $raw);
        } catch (InvalidArgumentException $e) {
            $this->flash($e->getMessage());

            return;
        }

        try {
            ($this->makeService)()->record($execution);
        } catch (Throwable $e) {
            $this->flash('O comando executou, mas o registro falhou: ' . $e->getMessage());
        }
    }

    /**
     * @param Closure(Runner): RunResult $runWith
     */
    private function runAndRecord(Closure $runWith, string $record, ExecutionKind $kind): never
    {
        $runner = new Runner($this->config['wsl'], $this->filesDir);

        try {
            $run = $runWith($runner);
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
            $this->flash('O comando executou, mas o registro falhou: ' . $e->getMessage());
        }

        Respond::redirect('/wsl');
    }

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
