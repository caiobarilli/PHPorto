<?php

declare(strict_types=1);

namespace App\Http\Pages;

use App\Domain\Execution;
use App\Domain\ExecutionKind;
use App\Domain\OutputCap;
use App\Http\Csrf;
use App\Http\Respond;
use App\Http\WslView;
use App\Wsl\InputLimit;
use App\Wsl\Runner;
use App\Wsl\RunResult;
use App\Wsl\ScriptBuilder;
use App\Wsl\Upload;
use Closure;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class WslPage extends Page
{
    private const COLD_START_S = 5;

    public function handle(string $method): never
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
}
