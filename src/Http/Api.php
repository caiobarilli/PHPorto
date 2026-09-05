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
 * A API JSON. Só existe quando PHPORTO_API_ENABLED está ligada, e ela nasce
 * DESLIGADA: quem clona não ganha uma superfície de execução por HTTP sem ter
 * pedido.
 *
 *   GET  /api/executions   lista os registros
 *   POST /api/executions   executa
 *
 * POR QUE A API EXECUTA. Uma API que só lista não justificaria uma flag: seria
 * espelho somente-leitura de uma tabela que a tela já mostra. A capacidade que
 * justifica existir é exatamente a execução — o problema que a ferramenta
 * resolve é que quem está do outro lado, inclusive um agente que só fala HTTP,
 * não tem como abrir um shell dentro da distro.
 *
 * AS DUAS TRAVAS, e por que o CORS sozinho não é uma delas:
 *
 *   O CORS NÃO IMPEDE A REQUISIÇÃO DE SAIR. Ele impede a RESPOSTA de ser LIDA.
 *
 * Exigir Content-Type: application/json é a trava de verdade, porque esse
 * cabeçalho torna a requisição "não simples" e obriga o navegador a fazer
 * preflight — e é o preflight que o CORS barra, antes de qualquer efeito. Um
 * formulário HTML não consegue mandar esse Content-Type. A checagem de Origin
 * é a segunda camada, para o caso de um cliente não-navegador anunciar origem.
 */
final class Api
{
    /**
     * @param array{root: string, distro: string, timeout: int} $wsl
     * @param Closure(): ExecutionLogService                    $makeService
     */
    public function __construct(
        private readonly array $wsl,
        private readonly string $corsOrigin,
        private readonly Distro $distroChecker,
        private readonly Closure $makeService,
        private readonly string $filesDir,
    ) {
    }

    public function handle(string $path, string $method): never
    {
        header('Access-Control-Allow-Origin: ' . $this->corsOrigin);
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Max-Age: 86400');

        if ($method === 'OPTIONS') {
            http_response_code(204);
            exit;
        }

        if ($path !== '/api/executions') {
            Respond::notFound();
        }

        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
        if (is_string($origin) && $origin !== '' && $origin !== $this->corsOrigin) {
            Respond::json(['ok' => false, 'error' => 'Origem não permitida.'], 403);
        }

        match ($method) {
            'GET'  => $this->list(),
            'POST' => $this->run(),
            default => Respond::json(['ok' => false, 'error' => 'Método não permitido.'], 405),
        };
    }

    private function list(): never
    {
        $limit = $_GET['limit'] ?? null;
        $limit = is_string($limit) && ctype_digit($limit) ? (int) $limit : 100;

        try {
            $rows = ($this->makeService)()->recent($limit);
        } catch (InvalidArgumentException $e) {
            Respond::json(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (Throwable $e) {
            Respond::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }

        Respond::json([
            'ok'         => true,
            'count'      => count($rows),
            'executions' => array_map(self::toArray(...), $rows),
        ]);
    }

    private function run(): never
    {
        $type = $_SERVER['CONTENT_TYPE'] ?? '';
        $type = is_string($type) ? strtolower($type) : '';

        if (!str_contains($type, 'application/json')) {
            Respond::json(
                ['ok' => false, 'error' => 'Content-Type: application/json é obrigatório.'],
                415
            );
        }

        $raw  = (string) file_get_contents('php://input');
        $body = json_decode($raw, true);

        if (!is_array($body)) {
            Respond::json(['ok' => false, 'error' => 'Corpo JSON inválido.'], 400);
        }

        $blocked = $this->blockingReason();
        if ($blocked !== null) {
            Respond::json(['ok' => false, 'error' => $blocked], 409);
        }

        $runner = new Runner($this->wsl, $this->filesDir);

        $src = $body['src'] ?? null;
        $dst = $body['dst'] ?? null;

        if (is_string($src) && is_string($dst) && trim($src) !== '' && trim($dst) !== '') {
            $script   = ScriptBuilder::attachment();
            $record   = ScriptBuilder::attachmentRecord(trim($src), trim($dst));
            $kind     = ExecutionKind::Anexo;
            $extraEnv = ['PHPORTO_SRC' => trim($src), 'PHPORTO_DST' => trim($dst)];
        } else {
            $command = $body['command'] ?? null;

            if (!is_string($command) || trim($command) === '') {
                Respond::json(
                    ['ok' => false, 'error' => 'Informe "command", ou "src" e "dst" para anexo.'],
                    400
                );
            }

            $script   = ScriptBuilder::command($command);
            $record   = ScriptBuilder::normalize($command);
            $kind     = ExecutionKind::Comando;
            $extraEnv = [];
        }

        try {
            $run = $runner->run($script, $extraEnv);
        } catch (RuntimeException $e) {
            Respond::json(['ok' => false, 'error' => $e->getMessage()], 409);
        }

        $execution = new Execution(
            command: $record,
            output: $run->output,
            exitCode: $run->exitCode,
            durationMs: $run->durationMs,
            kind: $kind,
            timedOut: $run->timedOut,
        );

        $recorded = true;
        $note     = null;

        try {
            // O retorno traz o created_at atribuído pelo banco; sem ele o
            // cliente precisaria de uma segunda chamada só para a data.
            $execution = ($this->makeService)()->record($execution);
        } catch (Throwable $e) {
            // A execução ACONTECEU. Devolver 500 aqui faria o cliente achar que
            // nada rodou, e ele tentaria de novo — executando duas vezes.
            $recorded = false;
            $note     = 'A execução ocorreu, mas o registro falhou: ' . $e->getMessage();
        }

        Respond::json([
            'ok'        => true,
            'recorded'  => $recorded,
            'note'      => $note,
            'execution' => self::toArray($execution),
        ]);
    }

    private function blockingReason(): ?string
    {
        if ($this->wsl['root'] === '') {
            return 'PHPORTO_WSL_ROOT está vazio ou ausente no .env.';
        }

        $status = $this->distroChecker->status();

        return $status->isUsable() ? null : $status->reason($this->wsl['distro']);
    }

    /**
     * @return array{command: string, output: string, exit_code: ?int, duration_ms: int, kind: string, timed_out: bool, created_at: ?string}
     */
    private static function toArray(Execution $e): array
    {
        return [
            'command'     => $e->command,
            'output'      => $e->output,
            'exit_code'   => $e->exitCode,
            'duration_ms' => $e->durationMs,
            'kind'        => $e->kind->value,
            'timed_out'   => $e->timedOut,
            'created_at'  => $e->createdAt,
        ];
    }
}
