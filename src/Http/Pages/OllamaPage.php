<?php

declare(strict_types=1);

namespace App\Http\Pages;

use App\Domain\OllamaExecution;
use App\Domain\OllamaExecutionAction;
use App\Domain\OllamaExecutionStatus;
use App\Http\Csrf;
use App\Http\OllamaView;
use App\Http\Respond;
use App\Win\Ollama;
use App\Win\OllamaChannel;
use App\Win\OllamaModel;
use App\Win\OllamaStatus;
use App\Win\OllamaSub;
use App\Win\PsResult;
use InvalidArgumentException;
use Throwable;

final class OllamaPage extends Page
{
    private const SESSION_CONFIRM = 'phporto_ollama_confirm';

    public function handle(string $method = 'GET'): never
    {
        if ($method === 'POST') {
            if (($_POST['acao'] ?? null) === 'limpar') {
                $this->handleOllamaClear();
            }

            $this->handleOllamaPost();
        }

        $parte = self::ollamaParte($_GET['parte'] ?? null);
        $view  = $this->ollamaView($parte);

        if ($parte === OllamaView::PARTE_ESTADO) {
            Respond::partial(Respond::render('ollama.php', $view));
        }

        Respond::html('PHPorto — Ollama', Respond::render('ollama.php', $view));
    }

    public static function ollamaParte(mixed $valor): string
    {
        return match ($valor) {
            OllamaView::PARTE_ESTADO => OllamaView::PARTE_ESTADO,
            default                  => OllamaView::PARTE_COMPLETA,
        };
    }

    private function ollamaView(string $parte): OllamaView
    {
        $canal   = new OllamaChannel($this->filesDir);
        $blocked = $canal->blocked();
        $status  = $blocked === null ? $canal->status() : null;

        if ($blocked === null) {
            $blocked = self::ollamaLevelRefusal($status);
        }

        $versao = $blocked === null ? $canal->version() : null;
        $loaded = $versao !== null ? $canal->loaded() : null;
        $confirm = $parte === OllamaView::PARTE_ESTADO ? null : $this->takeConfirmModel();

        $this->reconcileOllamaPull($canal);

        $history = [];
        $total = 0;
        $page = 1;
        $pages = 1;
        $historyFailed = null;

        if ($parte !== OllamaView::PARTE_ESTADO) {
            try {
                $pagina = self::ollamaPagina($_GET['pagina'] ?? null);
                $bloco = ($this->makeService)()->ollamaPage($pagina);
                $history = $bloco['rows'];
                $total = $bloco['total'];
                $page = $bloco['page'];
                $pages = $bloco['pages'];
            } catch (Throwable $e) {
                $historyFailed = 'Não foi possível ler o histórico: ' . $e->getMessage();
            }
        }

        return new OllamaView(
            blocked: $blocked,
            status: $status,
            version: $versao,
            models: $versao !== null ? $canal->models() : null,
            installerReady: is_file($canal->installerPath()),
            download: $canal->progress(Ollama::F_DOWNLOAD),
            pull: $canal->progress(Ollama::F_PULL),
            notice: $parte === OllamaView::PARTE_ESTADO ? null : $this->takeFlash(),
            csrfToken: Csrf::token(),
            csrfField: Csrf::fieldName(),
            loaded: $loaded,
            parte: $parte,
            confirmModel: $confirm,
            diskFreeBytes: Ollama::diskFreeBytes(),
            history: $history,
            historyTotal: $total,
            historyPage: $page,
            historyPages: $pages,
            tz: $this->config['tz'],
            historyFailed: $historyFailed,
        );
    }

    public static function ollamaPagina(mixed $valor): int
    {
        if (is_int($valor) && $valor >= 1) {
            return $valor;
        }

        if (is_string($valor) && ctype_digit($valor) && (int) $valor >= 1) {
            return (int) $valor;
        }

        return 1;
    }

    public static function ollamaLevelRefusal(?OllamaStatus $status): ?string
    {
        if ($status === null) {
            return 'Não deu para conferir o Ollama neste Windows. Nada foi feito.';
        }

        return match ($status->nivel) {
            Ollama::NIVEL_MEDIO => null,
            'alto'              => 'O PHPorto está rodando como administrador. O Ollama é instalado e usado só como o seu usuário, então o canal recusa: suba o php -S num terminal comum.',
            default             => 'Não deu para saber se o PHPorto está rodando como administrador. Na dúvida, o canal do Ollama recusa.',
        };
    }

    public static function ollamaNotice(?PsResult $run): string
    {
        if ($run === null) {
            return 'Não deu para rodar o script do Ollama. Nada foi feito.';
        }

        if ($run->timedOut) {
            return 'O script do Ollama passou do tempo e foi encerrado.';
        }

        if (Ollama::nivel($run->output) !== Ollama::NIVEL_MEDIO) {
            return self::ollamaLevelRefusal(new OllamaStatus(Ollama::nivel($run->output), false, 0, null, null, null, null, false)) ?? '';
        }

        $marca = Ollama::marker($run->output, 'OLLAMA') ?? Ollama::marker($run->output, 'FILHO') ?? '';

        return match (true) {
            $marca === 'instalador-aberto'      => 'O instalador do Ollama ' . Ollama::VERSION . ' abriu. Siga a janela dele; não pede administrador.',
            $marca === 'hash-divergente'        => 'O instalador não bate com o SHA-256 fixado. Nada foi executado.',
            $marca === 'sem-instalador'         => 'O instalador não está baixado. Nada foi executado.',
            $marca === 'desinstalador-aberto'   => 'O desinstalador do Ollama abriu. Siga a janela dele.',
            $marca === 'sem-desinstalador'      => 'Não achei o Ollama entre os programas do seu usuário.',
            $marca === 'desinstalador-recusado' => 'O desinstalador registrado não está na pasta do Ollama do seu usuário. Nada foi executado.',
            $marca === 'nao-instalado'          => 'O Ollama não está instalado para o seu usuário.',
            $marca === 'ja-rodando'             => 'O Ollama já está rodando.',
            $marca === 'iniciado'               => 'O Ollama foi iniciado em 127.0.0.1:11434.',
            str_starts_with($marca, 'parado')   => 'Processos do Ollama encerrados: ' . substr($marca, 7) . '.',
            default                             => 'O script do Ollama terminou sem resposta conhecida (código ' . $run->exitCode . ').',
        };
    }

    public static function logsAction(OllamaSub $sub): ?OllamaExecutionAction
    {
        return match ($sub) {
            OllamaSub::Pull => OllamaExecutionAction::Pull,
            OllamaSub::Remove => OllamaExecutionAction::Remove,
            OllamaSub::Install => OllamaExecutionAction::Install,
            default => null,
        };
    }

    private function handleOllamaClear(): never
    {
        if (!Csrf::consume()) {
            $this->flash('Sessão expirada ou formulário antigo. Releia a página e tente de novo. Nada foi feito.');
            Respond::redirect('/ollama');
        }

        try {
            $n = ($this->makeService)()->clearOllama();
            $this->flash($n === 0 ? 'O histórico do Ollama já estava vazio.' : 'Histórico do Ollama apagado: ' . $n . ' registro(s).');
        } catch (Throwable $e) {
            $this->flash('Não deu para apagar o histórico: ' . $e->getMessage());
        }

        Respond::redirect('/ollama');
    }

    private function handleOllamaPost(): never
    {
        if (!Csrf::consume()) {
            $this->flash('Sessão expirada ou formulário antigo. Releia a página e tente de novo. Nada foi feito.');
            Respond::redirect('/ollama');
        }

        try {
            $sub    = OllamaSub::parse($_POST['SubAction'] ?? null);
            $modelo = $sub->needsModel() ? OllamaModel::parse($_POST['Model'] ?? null) : null;
        } catch (InvalidArgumentException $e) {
            $this->flash($e->getMessage() . ' Nada foi feito.');
            Respond::redirect('/ollama');
        }

        $canal  = new OllamaChannel($this->filesDir);
        $recusa = $canal->blocked();
        $status = $recusa === null ? $canal->status() : null;
        $recusa ??= self::ollamaLevelRefusal($status);

        if ($recusa !== null || $sub === OllamaSub::Status || $sub === OllamaSub::List) {
            if ($recusa !== null) {
                $this->flash($recusa);
            }
            Respond::redirect('/ollama');
        }

        $inicio = hrtime(true);
        $confirmado = Ollama::isPullConfirmed($_POST[Ollama::CONFIRM_PULL] ?? null);
        [$aviso, $saida, $codigo, $pullIniciado] = $this->ollamaRun($canal, $sub, $modelo, $status, $confirmado);

        $falhaRegistro = $this->logOllamaAction(
            $sub,
            $modelo,
            $saida,
            $codigo,
            intdiv(hrtime(true) - $inicio, 1000000),
            $pullIniciado,
            $confirmado,
        );

        if ($falhaRegistro !== null) {
            $aviso .= ' ' . $falhaRegistro;
        }

        $this->flash($aviso);
        Respond::redirect('/ollama');
    }

    private function logOllamaAction(
        OllamaSub $sub,
        ?OllamaModel $modelo,
        string $saida,
        int $codigo,
        int $durationMs,
        bool $pullIniciado,
        bool $confirmado,
    ): ?string {
        $acao = self::logsAction($sub);

        if ($acao === null) {
            return null;
        }

        if ($acao === OllamaExecutionAction::Install && $saida === '' && $codigo === 0) {
            return null;
        }

        try {
            $svc = ($this->makeService)();

            if ($acao === OllamaExecutionAction::Pull && $pullIniciado) {
                $output = $confirmado && str_contains($saida, Ollama::REGISTRO_PULL_NOVO_CONFIRMADO)
                    ? Ollama::REGISTRO_PULL_NOVO_CONFIRMADO
                    : 'pull iniciado';

                $svc->recordOllama(new OllamaExecution(
                    action: OllamaExecutionAction::Pull,
                    output: $output,
                    status: OllamaExecutionStatus::Iniciado,
                    durationMs: 0,
                    model: $modelo?->value,
                ));

                return null;
            }

            if ($acao === OllamaExecutionAction::Pull) {
                return null;
            }

            $svc->recordOllama(new OllamaExecution(
                action: $acao,
                output: $saida,
                status: $codigo === 0 ? OllamaExecutionStatus::Ok : OllamaExecutionStatus::Erro,
                durationMs: max(0, $durationMs),
                model: $modelo?->value,
            ));

            return null;
        } catch (Throwable $e) {
            return 'A ação executou, mas o registro falhou: ' . $e->getMessage();
        }
    }

    private function reconcileOllamaPull(OllamaChannel $canal): void
    {
        $progress = $canal->progress(Ollama::F_PULL);

        if ($progress === null) {
            return;
        }

        $estado = $progress['estado'];
        $terminal = $estado === 'success' || str_starts_with($estado, 'erro');

        if (!$terminal) {
            return;
        }

        try {
            $svc = ($this->makeService)();
            $open = $svc->openOllamaPull();

            if ($open === null || $open->id === null) {
                return;
            }

            $duration = $progress['duration_ms'] > 0
                ? $progress['duration_ms']
                : self::elapsedMs($open->createdAt);

            $status = $estado === 'success' ? OllamaExecutionStatus::Ok : OllamaExecutionStatus::Erro;
            $output = $open->output;

            if ($status === OllamaExecutionStatus::Erro) {
                $output = trim($output . "\n" . $estado);
            }

            $svc->finalizeOllama($open->id, $status, $output, $duration);
        } catch (Throwable) {
        }
    }

    public static function elapsedMs(?string $createdAt): int
    {
        if ($createdAt === null || $createdAt === '') {
            return 0;
        }

        try {
            $inicio = new \DateTimeImmutable($createdAt);
            $fim = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

            return max(0, (int) (($fim->getTimestamp() - $inicio->getTimestamp()) * 1000));
        } catch (\Exception) {
            return 0;
        }
    }

    /**
     * @return array{0: string, 1: string, 2: int, 3: bool}
     */
    private function ollamaRun(OllamaChannel $canal, OllamaSub $sub, ?OllamaModel $modelo, ?OllamaStatus $status, bool $pullConfirmado = false): array
    {
        if (!$canal->ensureDir()) {
            return ['Não deu para criar a pasta ' . $canal->dir() . '. Nada foi feito.', '', 1, false];
        }

        if ($sub === OllamaSub::Install) {
            if (Ollama::installerMatches($canal->installerPath())) {
                $r = $canal->runScript('install', Ollama::installScript($canal->installerPath()));

                return [self::ollamaNotice($r), $r->output ?? '', $r->exitCode ?? 1, false];
            }

            $atual = $canal->progress(Ollama::F_DOWNLOAD);
            $marca = @filemtime($canal->dir() . DIRECTORY_SEPARATOR . Ollama::F_DOWNLOAD);

            if ($atual !== null && $atual['estado'] === 'baixando' && is_int($marca) && time() - $marca < 120) {
                return ['O instalador ainda está baixando. Acompanhe o progresso abaixo.', '', 0, false];
            }

            @unlink($canal->installerPath());
            $canal->clearProgress(Ollama::F_DOWNLOAD);
            $r = $canal->spawnChild('baixar', Ollama::downloadChild(
                $canal->installerPath(),
                $canal->dir() . DIRECTORY_SEPARATOR . Ollama::F_DOWNLOAD,
            ));

            return [
                Ollama::marker($r->output ?? '', 'FILHO') === 'iniciado'
                    ? 'Baixando o instalador ' . Ollama::VERSION . ' (1,6 GB). Quando terminar e o SHA-256 conferir, clique em Instalar de novo.'
                    : self::ollamaNotice($r),
                $r->output ?? '',
                $r->exitCode ?? 1,
                false,
            ];
        }

        if ($sub === OllamaSub::Remove && $modelo !== null) {
            return $canal->remove($modelo)
                ? ['O modelo ' . $modelo->value . ' foi removido.', 'DELETE /api/delete 200', 0, false]
                : ['O Ollama não removeu o ' . $modelo->value . '. Confira se ele está rodando e se o modelo está baixado.', 'DELETE /api/delete falhou', 1, false];
        }

        if ($sub === OllamaSub::Pull && $modelo !== null) {
            if ($canal->version() === null) {
                return ['O Ollama não está respondendo em 127.0.0.1:11434. Inicie antes de baixar um modelo.', '', 1, false];
            }

            $instalados = $canal->models();
            $precisaConfirm = Ollama::pullNeedsConfirm($modelo, $instalados);
            $semConfirm = Ollama::pullUnconfirmedRefusal($modelo, $instalados, $pullConfirmado);

            if ($semConfirm !== null) {
                $this->storeConfirmModel($modelo->value);

                return [$semConfirm, '', 1, false];
            }

            $vram = Ollama::vramRefusal($modelo, $status);

            if ($vram !== null) {
                return [$vram, '', 1, false];
            }

            $canal->clearProgress(Ollama::F_PULL);
            $r = $canal->spawnChild('pull', Ollama::pullChild($modelo, $canal->dir() . DIRECTORY_SEPARATOR . Ollama::F_PULL));
            $saida = $r->output ?? '';
            $iniciado = Ollama::marker($saida, 'FILHO') === 'iniciado';

            if ($precisaConfirm && $pullConfirmado && $iniciado) {
                $saida = ($saida === '' ? '' : $saida . "\n") . Ollama::REGISTRO_PULL_NOVO_CONFIRMADO;
            }

            return [
                $iniciado
                    ? 'Baixando o ' . $modelo->value . '. Acompanhe o progresso abaixo.'
                    : self::ollamaNotice($r),
                $saida,
                $r->exitCode ?? 1,
                $iniciado,
            ];
        }

        $r = $canal->runScript($sub->value, match ($sub) {
            OllamaSub::Uninstall => Ollama::uninstallScript(),
            OllamaSub::Start     => Ollama::startScript(),
            default              => Ollama::stopScript(),
        });

        return [self::ollamaNotice($r), $r->output ?? '', $r->exitCode ?? 1, false];
    }

    private function storeConfirmModel(string $modelo): void
    {
        Csrf::start();
        $_SESSION[self::SESSION_CONFIRM] = $modelo;
    }

    private function takeConfirmModel(): ?string
    {
        Csrf::start();
        $modelo = $_SESSION[self::SESSION_CONFIRM] ?? null;
        unset($_SESSION[self::SESSION_CONFIRM]);

        return is_string($modelo) && OllamaModel::isValid($modelo) ? $modelo : null;
    }
}
