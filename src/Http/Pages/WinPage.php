<?php

declare(strict_types=1);

namespace App\Http\Pages;

use App\Domain\Execution;
use App\Domain\ExecutionKind;
use App\Domain\OutputCap;
use App\Domain\WinState;
use App\Domain\WinStateScope;
use App\Http\Csrf;
use App\Http\Respond;
use App\Http\WinTab;
use App\Http\WinView;
use App\Php\PhpUpdate;
use App\Win\JobChannel;
use App\Win\PsTerminalCommand;
use App\Win\SshKeyFolder;
use App\Win\WinAction;
use App\Win\WinConfig;
use App\Wsl\Distro;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class WinPage extends Page
{
    public function handle(string $method): never
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
            WinAction::WslInstallDistro => 'Instalar a distro do WSL é pela configuração, não pela /win.',
            WinAction::PhpUpdate => 'Atualizar o PHP é pela tela do PHP, não pela /win.',
            default              => null,
        };
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
}
