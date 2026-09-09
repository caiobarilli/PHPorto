<?php

declare(strict_types=1);

namespace App\Http;

use App\Config\Config;
use App\Config\Flags;
use App\Domain\Execution;
use App\Domain\ExecutionKind;
use App\Services\ExecutionLogService;
use App\Win\Elevation;
use App\Win\JobChannel;
use App\Win\PsRunner;
use App\Win\WinAction;
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
        private readonly Elevation $elevation,
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
            winPath: $this->config['winutil']['path'],
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
        if ($method === 'POST') {
            $this->handleWinPost();
        }

        $rows   = [];
        $failed = null;

        try {
            $rows = ($this->makeService)()->recent(self::ROWS, ExecutionKind::Windows);
        } catch (Throwable $e) {
            $failed = 'Não foi possível ler os registros: ' . $e->getMessage();
        }

        $view = new WinView(
            rows: $rows,
            result: $rows[0] ?? null,
            blocked: $failed ?? $this->winBlockingReason(),
            notice: $this->takeFlash(),
            csrfToken: Csrf::token(),
            csrfField: Csrf::fieldName(),
            win: $this->elevation->state(),
            winutilPath: $this->config['winutil']['path'],
            timeout: $this->config['winutil']['timeout'],
            tz: $this->config['tz'],
            maxOutputBytes: PsRunner::MAX_OUTPUT_BYTES,
            maxParamBytes: WinAction::MAX_PARAM_BYTES,
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
                $n = ($this->makeService)()->clear(ExecutionKind::Windows);
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

        // Só os campos que chegaram como texto: o resto não é entrada válida
        // de formulário, e deixar passar viraria um TypeError lá dentro.
        $entrada = [];
        foreach ($_POST as $chave => $valor) {
            if (is_string($chave) && is_string($valor)) {
                $entrada[$chave] = $valor;
            }
        }

        try {
            $params = $acao->validate($entrada);
        } catch (InvalidArgumentException $e) {
            $this->flash($e->getMessage());
            Respond::redirect('/win');
        }

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

        Respond::redirect('/win');
    }

    /**
     * A linha de comando equivalente, para o registro.
     *
     * @param array<string, string|int|bool> $params
     */
    private static function describe(WinAction $acao, array $params): string
    {
        $partes = ['winutil -Action ' . $acao->value];

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
                . ' Nada é executado sem ele: as ações do winutil-cli exigem Administrador.';
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
