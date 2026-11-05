<?php

declare(strict_types=1);

namespace App\Http\Pages;

use App\Domain\Execution;
use App\Domain\ExecutionKind;
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
    public function handle(string $method = 'GET'): never
    {
        if ($method === 'POST') {
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
        );
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
        [$aviso, $saida, $codigo] = $this->ollamaRun($canal, $sub, $modelo, $status);

        try {
            ($this->makeService)()->record(new Execution(
                command: 'ollama ' . $sub->value . ($modelo !== null ? ' ' . $modelo->value : ''),
                output: $saida,
                exitCode: $codigo,
                durationMs: intdiv(hrtime(true) - $inicio, 1000000),
                kind: ExecutionKind::Windows,
                timedOut: false,
            ));
        } catch (Throwable $e) {
            $aviso .= ' A ação executou, mas o registro falhou: ' . $e->getMessage();
        }

        $this->flash($aviso);
        Respond::redirect('/ollama');
    }

    /**
     * @return array{0: string, 1: string, 2: int}
     */
    private function ollamaRun(OllamaChannel $canal, OllamaSub $sub, ?OllamaModel $modelo, ?OllamaStatus $status): array
    {
        if (!$canal->ensureDir()) {
            return ['Não deu para criar a pasta ' . $canal->dir() . '. Nada foi feito.', '', 1];
        }

        if ($sub === OllamaSub::Install) {
            if (Ollama::installerMatches($canal->installerPath())) {
                $r = $canal->runScript('install', Ollama::installScript($canal->installerPath()));

                return [self::ollamaNotice($r), $r->output ?? '', $r->exitCode ?? 1];
            }

            $atual = $canal->progress(Ollama::F_DOWNLOAD);
            $marca = @filemtime($canal->dir() . DIRECTORY_SEPARATOR . Ollama::F_DOWNLOAD);

            if ($atual !== null && $atual['estado'] === 'baixando' && is_int($marca) && time() - $marca < 120) {
                return ['O instalador ainda está baixando. Acompanhe o progresso abaixo.', '', 0];
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
            ];
        }

        if ($sub === OllamaSub::Remove && $modelo !== null) {
            return $canal->remove($modelo)
                ? ['O modelo ' . $modelo->value . ' foi removido.', 'DELETE /api/delete 200', 0]
                : ['O Ollama não removeu o ' . $modelo->value . '. Confira se ele está rodando e se o modelo está baixado.', 'DELETE /api/delete falhou', 1];
        }

        if ($sub === OllamaSub::Pull && $modelo !== null) {
            if ($canal->version() === null) {
                return ['O Ollama não está respondendo em 127.0.0.1:11434. Inicie antes de baixar um modelo.', '', 1];
            }

            $vram = Ollama::vramRefusal($modelo, $status);

            if ($vram !== null) {
                return [$vram, '', 1];
            }

            $canal->clearProgress(Ollama::F_PULL);
            $r = $canal->spawnChild('pull', Ollama::pullChild($modelo, $canal->dir() . DIRECTORY_SEPARATOR . Ollama::F_PULL));

            return [
                Ollama::marker($r->output ?? '', 'FILHO') === 'iniciado'
                    ? 'Baixando o ' . $modelo->value . '. Acompanhe o progresso abaixo.'
                    : self::ollamaNotice($r),
                $r->output ?? '',
                $r->exitCode ?? 1,
            ];
        }

        $r = $canal->runScript($sub->value, match ($sub) {
            OllamaSub::Uninstall => Ollama::uninstallScript(),
            OllamaSub::Start     => Ollama::startScript(),
            default              => Ollama::stopScript(),
        });

        return [self::ollamaNotice($r), $r->output ?? '', $r->exitCode ?? 1];
    }
}
