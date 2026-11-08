<?php

declare(strict_types=1);

namespace App\Http\Pages;

use App\Autostart\History as AutostartHistory;
use App\Autostart\Manager as AutostartManager;
use App\Autostart\Store as AutostartStore;
use App\Autostart\Task as AutostartTask;
use App\Config\Config;
use App\Config\Flags;
use App\Domain\Execution;
use App\Domain\ExecutionKind;
use App\Http\ConfigView;
use App\Http\Csrf;
use App\Http\Respond;
use App\Network\Firewall;
use App\Network\History;
use App\Network\Machine;
use App\Network\Manager;
use App\Network\Probe;
use App\Network\Store as NetworkStore;
use App\Win\Elevation;
use App\Win\HypervGate;
use App\Win\JobChannel;
use App\Win\PsResult;
use App\Win\WinAction;
use App\Wsl\Distro;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class ConfigPage extends Page
{
    public function handle(string $method): never
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

        $autostart = new AutostartStore($this->filesDir);

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
            wslDistro: $this->distroChecker->name(),
            networkEnabled: $this->network->enabled,
            networkAddress: $this->network->enabled ? $this->network->address() : null,
            networkAllow: $this->network->allow,
            networkUpdatedAt: $this->network->updatedAt,
            autostartEnabled: $autostart->enabled(),
            autostartUpdatedAt: $autostart->updatedAt(),
        );

        Respond::html('PHPorto — configuração', Respond::render('config.php', $view), $this->network);
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

        if (in_array($action, ['autostart-ligar', 'autostart-desligar', 'autostart-parar'], true)) {
            $this->handleAutostart($action);
        }

        if ($action === 'rede-desligar') {
            $this->handleNetworkOff();
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

        if ($action === WinAction::WslInstallDistro->value) {
            $this->handleWslInstallDistroPost();
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

    private function handleWslInstallDistroPost(): never
    {
        $distro = $this->distroChecker->name();

        if (!$this->distroChecker->enabled()) {
            $this->flash('O WSL ainda não está ativado (ou falta reiniciar o Windows). Nada foi executado.');
            Respond::redirect('/config');
        }

        $instaladas = $this->distroChecker->registered();

        if ($instaladas === null) {
            $this->flash('Não foi possível ler as distros instaladas. Nada foi executado.');
            Respond::redirect('/config');
        }

        if (Distro::contains($instaladas, $distro)) {
            $this->flash(sprintf('A distro %s já está instalada. Nada foi executado.', $distro));
            Respond::redirect('/config');
        }

        try {
            $params = WinAction::WslInstallDistro->validate(['Distro' => $distro]);
        } catch (InvalidArgumentException) {
            $this->flash('PHPORTO_DISTRO no .env não é um nome de distro aceito. Nada foi executado.');
            Respond::redirect('/config');
        }

        $blocked = $this->oneShot->blockingReason();

        if ($blocked !== null) {
            $this->flash($blocked);
            Respond::redirect('/config');
        }

        try {
            $run = $this->oneShot->dispatch(WinAction::WslInstallDistro, $params, $this->config['winutil']['timeout']);
        } catch (RuntimeException $e) {
            $this->flash($e->getMessage());
            Respond::redirect('/config');
        }

        $aviso = self::wslInstallDistroNotice($run, $distro);

        try {
            ($this->makeService)()->record(new Execution(
                command: self::describe(WinAction::WslInstallDistro, $params),
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

    public static function wslInstallDistroNotice(PsResult $run, string $distro): string
    {
        if ($run->timedOut) {
            return sprintf('Instalar %s demorou demais e foi cancelado. A saída está no histórico da /win.', $distro);
        }

        if ($run->exitCode !== 0) {
            return sprintf('Falha ao instalar %s (código ', $distro)
                . ($run->exitCode === null ? 'nenhum' : $run->exitCode)
                . '). A saída está no histórico da /win.';
        }

        return sprintf(
            '%1$s instalada, sem abrir. Para criar o usuário UNIX, abra o %1$s uma vez fora do PHPorto '
            . '(menu Iniciar ou wsl -d %1$s num terminal). A saída está no histórico da /win.',
            $distro,
        );
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

    private function handleAutostart(string $action): never
    {
        $domain = getenv('USERDOMAIN');
        $user = getenv('USERNAME');
        $oneShot = $this->oneShot;
        $gestor = new AutostartManager(
            new AutostartStore($this->filesDir),
            new AutostartTask($this->filesDir),
            static fn (WinAction $a, array $params, int $timeout): PsResult => $oneShot->dispatch($a, $params, $timeout),
            new AutostartHistory($this->makeService),
            dirname($this->filesDir),
            PHP_BINARY,
            (is_string($domain) ? $domain : '') . '\\' . (is_string($user) ? $user : ''),
        );

        $result = match ($action) {
            'autostart-ligar' => $gestor->enable(),
            'autostart-desligar' => $gestor->disable(),
            default => $gestor->stopNow(),
        };

        $this->flash(implode(' ', $result->lines));
        Respond::redirect('/config');
    }

    private function handleNetworkOff(): never
    {
        $manager = new Manager(
            new NetworkStore($this->filesDir),
            new Machine(),
            new Probe($this->filesDir),
            Firewall::fromOneShot($this->oneShot),
            new History($this->makeService),
        );
        $result = $manager->disable();

        $this->flash(implode(' ', $result->lines));
        Respond::redirect('/config');
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

    private function takeWinRetry(): bool
    {
        Csrf::start();
        $marca = $_SESSION['phporto_win_retry'] ?? false;
        unset($_SESSION['phporto_win_retry']);

        return $marca === true;
    }
}
