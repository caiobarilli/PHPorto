<?php

declare(strict_types=1);

use App\Http\Pages;
use App\Http\PhpView;
use App\Http\Respond;
use App\Php\C\CPanel;
use App\Php\C\Tcc;
use App\Php\C\TccStatus;
use App\Php\IniFile;
use App\Php\IniForm;
use App\Php\PhpBuild;
use App\Php\PhpExtension;
use App\Php\PhpIni;

function extPasta(): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phporto-ext-' . bin2hex(random_bytes(4));
    mkdir($dir);

    return $dir;
}

function extArquivo(string $dir, string $texto): string
{
    $ini = $dir . DIRECTORY_SEPARATOR . 'php.ini';
    file_put_contents($ini, $texto);

    return $ini;
}

function extLimpar(string $dir): void
{
    foreach ((array) glob($dir . DIRECTORY_SEPARATOR . '{,.}*', GLOB_BRACE) as $arquivo) {
        if (is_string($arquivo) && is_file($arquivo)) {
            unlink($arquivo);
        }
    }

    rmdir($dir);
}

/**
 * @param array<string, bool> $mapa
 */
function extHtml(array $mapa, ?IniForm $ini, ?CPanel $painel = null): string
{
    $view = PhpView::make(new PhpBuild('8.5.12', false, 'C:\\php\\php.exe', 'C:\\php\\php.ini', 'cli-server'), null, true, static fn (string $b): null => null)
        ->with($ini, null, 'tok', '_csrf', null)
        ->withC($painel ?? extPainel())
        ->withExtensions($mapa);

    return Respond::render('php.php', $view);
}

function extPainel(?TccStatus $tcc = null, bool $ffi = true, bool $zip = true): CPanel
{
    return new CPanel($tcc ?? new TccStatus('C:\\t', Tcc::VERSION, 'C:\\t\\tcc\\libtcc.dll'), true, $ffi, 'true', null, $zip);
}

function extForm(bool $gravavel = true): IniForm
{
    return new IniForm('C:\\php\\php.ini', str_repeat('a', 64), [], $gravavel);
}

/**
 * @return array<string, bool>
 */
function extMapa(bool $todas, string ...$faltando): array
{
    $mapa = [];

    foreach (PhpExtension::cases() as $ext) {
        $mapa[$ext->value] = $todas && !in_array($ext->value, $faltando, true);
    }

    return $mapa;
}

it('a lista fechada é a que o PHPorto usa', function () {
    expect(array_map(static fn (PhpExtension $e): string => $e->value, PhpExtension::cases()))
        ->toBe(['zip', 'ffi', 'openssl', 'mbstring', 'pdo_sqlite', 'pdo_mysql'])
        ->and(array_keys(PhpExtension::loadedMap()))->toHaveCount(6);
});

it('a seção lista todas e mostra Ativar só nas não carregadas', function () {
    $html = extHtml(extMapa(true, 'zip', 'ffi'), extForm());

    expect($html)->toContain('id="php-extensoes"')
        ->toContain('id="btn-ativar-zip"')->toContain('id="btn-ativar-ffi"')
        ->not->toContain('id="btn-ativar-openssl"')
        ->toContain('value="' . PhpExtension::ACTION . '"')
        ->toContain('value="' . str_repeat('a', 64) . '"')
        ->toContain('data-sem-reload')
        ->toContain('name="_csrf" value="tok"');

    foreach (PhpExtension::cases() as $ext) {
        expect($html)->toContain('id="ext-' . $ext->value . '"');
    }

    expect(substr_count($html, '>carregada<'))->toBe(4)
        ->and(substr_count($html, '>não carregada<'))->toBe(2);
});

it('sem php.ini não há botão, e php.ini sem escrita desabilita', function () {
    expect(extHtml(extMapa(false), null))->not->toContain('id="btn-ativar-')
        ->and(extHtml(extMapa(false), extForm(false)))->toMatch('/id="btn-ativar-zip" disabled/');
});

it('o botão antigo de FFI saiu da seção C', function () {
    $html = extHtml(extMapa(true, 'ffi'), extForm(), extPainel(ffi: false));

    expect($html)->not->toContain('id="form-ffi"')
        ->toContain('id="c-ffi-extensoes"')
        ->and(substr_count($html, 'id="btn-ativar-ffi"'))->toBe(1);
});

it('ativa cada extensão da lista com .bak e fica idempotente', function (PhpExtension $ext) {
    $dir = extPasta();

    try {
        $ini = extArquivo($dir, "[PHP]\n;extension=" . $ext->value . "\n");
        $msg = Pages::extensionEnable(extMapa(false), $ini, hash_file('sha256', $ini), $ext->value);

        expect($msg)->toContain('php.ini gravado: extension=' . $ext->value)->toContain('php.ini.bak')->toContain('php -S')
            ->and(file_get_contents($ini))->toBe("[PHP]\nextension=" . $ext->value . "\n")
            ->and(file_get_contents($ini . PhpIni::BACKUP_SUFFIX))->toBe("[PHP]\n;extension=" . $ext->value . "\n");

        unlink($ini . PhpIni::BACKUP_SUFFIX);
        $denovo = Pages::extensionEnable(extMapa(false), $ini, hash_file('sha256', $ini), $ext->value);

        expect($denovo)->toContain('não foi tocado')
            ->and(is_file($ini . PhpIni::BACKUP_SUFFIX))->toBeFalse();
    } finally {
        extLimpar($dir);
    }
})->with(PhpExtension::cases());

it('acrescenta no fim quando não há linha', function () {
    $dir = extPasta();

    try {
        $ini = extArquivo($dir, "[PHP]\nmemory_limit = 128M\n");

        expect(Pages::extensionEnable(extMapa(false), $ini, hash_file('sha256', $ini), 'zip'))->toContain('gravado')
            ->and(file_get_contents($ini))->toBe("[PHP]\nmemory_limit = 128M\nextension=zip\n");
    } finally {
        extLimpar($dir);
    }
});

it('recusa nome fora da lista, já carregada, sem marca ou marca velha', function (mixed $nome, string $trecho, string $hashModo, bool $carregada) {
    $dir = extPasta();

    try {
        $ini  = extArquivo($dir, ";extension=zip\n;extension=xdebug\n");
        $hash = match ($hashModo) {
            'ok'    => hash_file('sha256', $ini),
            'velho' => str_repeat('0', 64),
            default => 'x',
        };

        expect(Pages::extensionEnable(extMapa($carregada), $ini, $hash, $nome))->toContain($trecho)
            ->and(file_get_contents($ini))->toBe(";extension=zip\n;extension=xdebug\n")
            ->and(is_file($ini . PhpIni::BACKUP_SUFFIX))->toBeFalse();
    } finally {
        extLimpar($dir);
    }
})->with([
    'fora da lista'   => ['xdebug', 'fora da lista', 'ok', false],
    'injeção'         => ["zip\nextension=xdebug", 'fora da lista', 'ok', false],
    'não string'      => [['zip'], 'fora da lista', 'ok', false],
    'já carregada'    => ['zip', 'já está carregada', 'ok', true],
    'sem marca'       => ['zip', 'Nada foi gravado', 'x', false],
    'marca velha'     => ['zip', 'mudou no disco', 'velho', false],
]);

it('sem php.ini carregado não grava', function () {
    expect(Pages::extensionEnable(extMapa(false), false, str_repeat('a', 64), 'zip'))->toContain('Nenhum php.ini');
});

it('PhpIni::enableExtension recusa nome fora da lista', function () {
    $dir = extPasta();

    try {
        $ini = extArquivo($dir, ";extension=xdebug\n");

        expect(fn () => PhpIni::enableExtension($ini, hash_file('sha256', $ini), 'xdebug'))->toThrow(RuntimeException::class, 'fora da lista');
    } finally {
        extLimpar($dir);
    }
});

it('IniFile::enableExtension descomenta, acrescenta e respeita linha ativa', function () {
    expect(IniFile::enableExtension("[PHP]\r\n;extension=curl\r\n;extension=ffi\r\n", 'ffi'))->toBe("[PHP]\r\n;extension=curl\r\nextension=ffi\r\n")
        ->and(IniFile::enableExtension("; extension = php_zip.dll\n", 'zip'))->toBe("extension = php_zip.dll\n")
        ->and(IniFile::enableExtension("[HOST=x]\nextension=zip\n", 'zip'))->toBe("[HOST=x]\nextension=zip\n[PHP]\nextension=zip\n")
        ->and(IniFile::enableExtension("extension=php_pdo_sqlite.dll\n", 'pdo_sqlite'))->toBe("extension=php_pdo_sqlite.dll\n");
});

it('Tcc::install sem zip avisa antes de baixar', function () {
    $baixou = false;
    $msg    = Tcc::install(sys_get_temp_dir() . '/phporto-tcc-nao', static function (string $u) use (&$baixou): ?string {
        $baixou = true;

        return null;
    }, Tcc::SHA256, false);

    expect($msg)->toBe(Tcc::ZIP_MISSING)->toContain('Extensões')
        ->and($baixou)->toBeFalse()
        ->and(Pages::tccInstall(extPainel(new TccStatus(sys_get_temp_dir() . '/phporto-tcc-nao'), zip: false), static fn (string $u): ?string => null))->toBe(Tcc::ZIP_MISSING);
});

it('o botão de instalar TCC fica desabilitado sem zip', function () {
    $sem = extHtml(extMapa(true, 'zip'), extForm(), extPainel(new TccStatus('C:\\x'), zip: false));
    $com = extHtml(extMapa(true), extForm(), extPainel(new TccStatus('C:\\x')));

    expect($sem)->toMatch('/id="btn-instalar-tcc" disabled/')->toContain('id="tcc-sem-zip"')
        ->and($com)->not->toMatch('/id="btn-instalar-tcc" disabled/')->not->toContain('id="tcc-sem-zip"');
});

it('o ramo de ativar extensão vem depois do CSRF', function () {
    $fonte = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Http/Pages.php');
    $corpo = substr($fonte, (int) strpos($fonte, 'private function handlePhpPost'));

    expect((int) strpos($corpo, 'Csrf::consume'))->toBeLessThan((int) strpos($corpo, 'PhpExtension::ACTION'));
});
