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
use App\Php\PhpIni;

function ffiPainel(?TccStatus $tcc = null, bool $ffi = true): CPanel
{
    return new CPanel($tcc ?? new TccStatus('C:\\t', Tcc::VERSION, 'C:\\t\\tcc\\libtcc.dll'), true, $ffi, 'true');
}

function ffiPasta(): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phporto-ffi-' . bin2hex(random_bytes(4));
    mkdir($dir);

    return $dir;
}

function ffiArquivo(string $dir, string $texto): string
{
    $ini = $dir . DIRECTORY_SEPARATOR . 'php.ini';
    file_put_contents($ini, $texto);

    return $ini;
}

function ffiLimpar(string $dir): void
{
    foreach ((array) glob($dir . DIRECTORY_SEPARATOR . '{,.}*', GLOB_BRACE) as $arquivo) {
        if (is_string($arquivo) && is_file($arquivo)) {
            unlink($arquivo);
        }
    }

    rmdir($dir);
}

function ffiHtml(CPanel $painel, ?IniForm $ini): string
{
    $view = PhpView::make(new PhpBuild('8.5.12', false, 'C:\\php\\php.exe', 'C:\\php\\php.ini', 'cli-server'), null, true, static fn (string $b): null => null)
        ->with($ini, null, 'tok', '_csrf', null)
        ->withC($painel);

    return Respond::render('php.php', $view);
}

function ffiForm(bool $gravavel = true): IniForm
{
    return new IniForm('C:\\php\\php.ini', str_repeat('a', 64), [], $gravavel);
}

it('o botão de ativar FFI só aparece com TCC instalado e FFI não carregada', function () {
    expect(ffiHtml(ffiPainel(ffi: false), ffiForm()))->toContain('id="btn-ativar-ffi"')
        ->toContain('value="' . CPanel::FFI_ACTION . '"')
        ->toContain('value="' . str_repeat('a', 64) . '"')
        ->and(ffiHtml(ffiPainel(), ffiForm()))->not->toContain('id="btn-ativar-ffi"')
        ->and(ffiHtml(ffiPainel(new TccStatus('C:\\x'), ffi: false), ffiForm()))->not->toContain('id="btn-ativar-ffi"')
        ->and(ffiHtml(ffiPainel(ffi: false), null))->not->toContain('id="btn-ativar-ffi"')
        ->and(ffiHtml(ffiPainel(ffi: false), ffiForm(false)))->toMatch('/id="btn-ativar-ffi" disabled/');
});

it('descomenta ;extension=ffi e preserva o resto', function () {
    $texto = "[PHP]\r\n;extension=curl\r\n;extension=ffi\r\nmemory_limit = 128M\r\n";

    expect(IniFile::enableExtension($texto, 'ffi'))->toBe("[PHP]\r\n;extension=curl\r\nextension=ffi\r\nmemory_limit = 128M\r\n")
        ->and(IniFile::enableExtension("; extension = php_ffi.dll\n", 'ffi'))->toBe("extension = php_ffi.dll\n");
});

it('sem linha nenhuma acrescenta extension=ffi no fim, fora de [HOST=]', function () {
    expect(IniFile::enableExtension("[PHP]\nmemory_limit = 128M", 'ffi'))->toBe("[PHP]\nmemory_limit = 128M\nextension=ffi\n")
        ->and(IniFile::enableExtension("[HOST=x]\nextension=ffi\n", 'ffi'))->toBe("[HOST=x]\nextension=ffi\n[PHP]\nextension=ffi\n")
        ->and(IniFile::enableExtension(";extension=ffi_x\n", 'ffi'))->toBe(";extension=ffi_x\nextension=ffi\n");
});

it('linha já ativa não muda nada', function (string $texto) {
    expect(IniFile::enableExtension($texto, 'ffi'))->toBe($texto);
})->with([
    "extension=ffi\n",
    "  extension = \"ffi\" ; ok\n",
    "extension=php_ffi.dll\n;extension=ffi\n",
]);

it('grava com .bak e fica idempotente', function () {
    $dir = ffiPasta();

    try {
        $ini = ffiArquivo($dir, "[PHP]\n;extension=ffi\n");
        $msg = Pages::ffiExtensionEnable(ffiPainel(ffi: false), $ini, hash_file('sha256', $ini));

        expect($msg)->toContain('php.ini gravado: extension=ffi')->toContain('php.ini.bak')->toContain('php -S')
            ->and(file_get_contents($ini))->toBe("[PHP]\nextension=ffi\n")
            ->and(file_get_contents($ini . PhpIni::BACKUP_SUFFIX))->toBe("[PHP]\n;extension=ffi\n");

        unlink($ini . PhpIni::BACKUP_SUFFIX);
        $denovo = Pages::ffiExtensionEnable(ffiPainel(ffi: false), $ini, hash_file('sha256', $ini));

        expect($denovo)->toContain('não foi tocado')
            ->and(is_file($ini . PhpIni::BACKUP_SUFFIX))->toBeFalse()
            ->and(file_get_contents($ini))->toBe("[PHP]\nextension=ffi\n");
    } finally {
        ffiLimpar($dir);
    }
});

it('recusa sem marca, com marca velha, com FFI carregada ou sem TCC', function () {
    $dir = ffiPasta();

    try {
        $ini = ffiArquivo($dir, ";extension=ffi\n");

        expect(Pages::ffiExtensionEnable(ffiPainel(ffi: false), $ini, 'x'))->toContain('Nada foi gravado')
            ->and(Pages::ffiExtensionEnable(ffiPainel(ffi: false), $ini, str_repeat('0', 64)))->toContain('mudou no disco')
            ->and(Pages::ffiExtensionEnable(ffiPainel(), $ini, hash_file('sha256', $ini)))->toContain('já está carregada')
            ->and(Pages::ffiExtensionEnable(ffiPainel(new TccStatus('C:\\x'), ffi: false), $ini, hash_file('sha256', $ini)))->toContain('Instale')
            ->and(Pages::ffiExtensionEnable(ffiPainel(ffi: false), false, hash_file('sha256', $ini)))->toContain('Nenhum php.ini')
            ->and(file_get_contents($ini))->toBe(";extension=ffi\n")
            ->and(is_file($ini . PhpIni::BACKUP_SUFFIX))->toBeFalse();
    } finally {
        ffiLimpar($dir);
    }
});

it('o ramo de ativar FFI vem depois do CSRF', function () {
    $fonte = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Http/Pages.php');
    $corpo = substr($fonte, (int) strpos($fonte, 'private function handlePhpPost'));

    expect((int) strpos($corpo, 'Csrf::consume'))->toBeLessThan((int) strpos($corpo, 'CPanel::FFI_ACTION'));
});
