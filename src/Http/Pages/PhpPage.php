<?php

declare(strict_types=1);

namespace App\Http\Pages;

use App\Domain\Execution;
use App\Domain\ExecutionKind;
use App\Http\Csrf;
use App\Http\PhpView;
use App\Http\Respond;
use App\Php\C\CCode;
use App\Php\C\CPanel;
use App\Php\C\CRun;
use App\Php\C\Tcc;
use App\Php\IniKey;
use App\Php\PhpBuild;
use App\Php\PhpExtension;
use App\Php\PhpIni;
use App\Php\PhpProbe;
use App\Php\PhpReleases;
use App\Php\PhpUpdate;
use App\Win\PsResult;
use App\Win\WinAction;
use Closure;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class PhpPage extends Page
{
    public function handle(string $method = 'GET'): never
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
        )->withC($this->cPanel())->withExtensions(PhpExtension::loadedMap());

        Respond::html('PHPorto — PHP', Respond::render('php.php', $view), $this->network);
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

        if ($acao === PhpExtension::ACTION) {
            $this->flash(self::extensionEnable(PhpExtension::loadedMap(), php_ini_loaded_file(), $_POST[PhpIni::HASH_FIELD] ?? null, $_POST[PhpExtension::FIELD] ?? null));
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
            zipLoaded: extension_loaded('zip'),
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

        return Tcc::install($painel->tcc->dir, $baixar, Tcc::SHA256, $painel->zipLoaded);
    }

    /**
     * @param array<string, bool> $carregadas
     */
    public static function extensionEnable(array $carregadas, string|false $ini, mixed $hash, mixed $nome): string
    {
        $ext = is_string($nome) ? PhpExtension::tryFrom($nome) : null;

        if ($ext === null) {
            return 'Extensão fora da lista do PHPorto. Nada foi gravado.';
        }

        if ($carregadas[$ext->value] ?? false) {
            return 'A extensão ' . $ext->value . ' já está carregada. O php.ini não foi tocado.';
        }

        if ($ini === false) {
            return 'Nenhum php.ini carregado. Esta tela só grava num php.ini que o PHP já leu. Nada foi gravado.';
        }

        if (!is_string($hash) || preg_match('/\A[0-9a-f]{64}\z/', $hash) !== 1) {
            return 'Formulário sem a marca do php.ini lido. Releia a página. Nada foi gravado.';
        }

        try {
            $mudou = PhpIni::enableExtension($ini, $hash, $ext->value);
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }

        if (!$mudou) {
            return 'extension=' . $ext->value . ' já está ativa no php.ini. O php.ini não foi tocado. Feche e suba o php -S de novo para carregar.';
        }

        return 'php.ini gravado: extension=' . $ext->value . '. A versão anterior ficou em ' . basename($ini) . PhpIni::BACKUP_SUFFIX
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
}
