<?php

declare(strict_types=1);

namespace App\Http\Pages;

use App\Config\Config;
use App\Domain\Execution;
use App\Domain\ExecutionKind;
use App\Http\Csrf;
use App\Http\HypervView;
use App\Http\Respond;
use App\Win\HypervListing;
use App\Win\JobChannel;
use App\Win\PsResult;
use App\Win\WinAction;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class HypervPage extends Page
{
    private const HYPERV_READ_TIMEOUT_S = Config::MIN_WINUTIL_TIMEOUT;

    public function handle(string $method = 'GET'): never
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
            )), $this->network);
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

        Respond::html('PHPorto — Hyper-V', Respond::render('hyperv.php', $view), $this->network);
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
}
