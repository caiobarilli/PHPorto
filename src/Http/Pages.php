<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Execution;
use App\Domain\ExecutionKind;
use App\Services\ExecutionLogService;
use App\Wsl\Distro;
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
    ) {
    }

    public function home(): never
    {
        $status = $this->distroChecker->status();

        $view = new HomeView(
            wslEnabled: $status->isUsable(),
            wslReason: $status->reason($this->config['wsl']['distro']),
            distro: $this->config['wsl']['distro'],
            status: $status,
        );

        Respond::html('PHPorto', Respond::render('home.php', $view));
    }

    public function config(): never
    {
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

        $view = new ConfigView(
            provider: $provider === '' ? 'desconhecido' : $provider,
            details: $details,
        );

        Respond::html('PHPorto — configuração', Respond::render('config.php', $view));
    }

    public function wsl(string $method): never
    {
        if ($method === 'POST') {
            $this->handlePost();
        }

        $rows   = [];
        $failed = null;

        try {
            $rows = ($this->makeService)()->recent(self::ROWS);
        } catch (Throwable $e) {
            $failed = 'Não foi possível ler os registros: ' . $e->getMessage();
        }

        $view = new WslView(
            rows: $rows,
            result: $rows[0] ?? null,
            blocked: $failed ?? $this->blockingReason(),
            notice: $this->takeFlash(),
            csrfToken: Csrf::token(),
            csrfField: Csrf::fieldName(),
            distro: $this->config['wsl']['distro'],
            root: $this->config['wsl']['root'],
            timeout: $this->config['wsl']['timeout'],
            tz: $this->config['tz'],
            coldStartSeconds: self::COLD_START_S,
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
                $n = ($this->makeService)()->clear();
                $this->flash($n === 0 ? 'Não havia registro para apagar.' : $n . ' registro(s) apagado(s).');
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

    private function takeFlash(): ?string
    {
        Csrf::start();
        $message = $_SESSION['phporto_flash'] ?? null;
        unset($_SESSION['phporto_flash']);

        return is_string($message) && $message !== '' ? $message : null;
    }
}
