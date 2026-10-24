<?php

declare(strict_types=1);

use App\Http\PhpView;
use App\Http\Respond;
use App\Php\C\CCode;
use App\Php\C\CCompiler;
use App\Php\C\CPanel;
use App\Php\C\CRun;
use App\Php\C\Tcc;
use App\Php\C\TccStatus;
use App\Php\IniKey;
use App\Php\PhpBuild;
use App\Win\PsResult;

final class FakeCCompiler implements CCompiler
{
    /** @var list<string> */
    public array $codes = [];

    public function __construct(private readonly PsResult $result = new PsResult("ola\n", 0, false, 12))
    {
    }

    public function run(string $code, TccStatus $tcc): PsResult
    {
        $this->codes[] = $code;

        return $this->result;
    }
}

function cPasta(): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phporto-c-' . bin2hex(random_bytes(4));
    mkdir($dir);

    return $dir;
}

function cRemove(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $itens = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

    foreach ($itens as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($dir);
}

/**
 * @param array<string, string> $entradas
 */
function cZip(array $entradas): string
{
    $arquivo = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phporto-c-' . bin2hex(random_bytes(4)) . '.zip';
    $zip     = new ZipArchive();
    $zip->open($arquivo, ZipArchive::CREATE);

    foreach ($entradas as $nome => $conteudo) {
        $zip->addFromString($nome, $conteudo);
    }

    $zip->close();
    $dados = (string) file_get_contents($arquivo);
    unlink($arquivo);

    return $dados;
}

function cInstalado(string $dir = 'C:\\PHPorto\\storage\\tcc'): TccStatus
{
    return new TccStatus($dir, Tcc::VERSION, $dir . '\\tcc\\libtcc.dll');
}

function cPainel(?TccStatus $tcc = null, bool $windows = true, bool $ffi = true, string $enable = 'true', ?CRun $last = null): CPanel
{
    return new CPanel($tcc ?? cInstalado(), $windows, $ffi, $enable, $last);
}

function cHtml(CPanel $painel): string
{
    $view = PhpView::make(new PhpBuild('8.5.12', false, 'C:\\php\\php.exe', 'C:\\php\\php.ini', 'cli-server'), null, true, static fn (string $b): null => null)
        ->with(null, null, 'tok', '_csrf', null)
        ->withC($painel);

    return Respond::render('php.php', $view);
}

it('a fonte do TCC é o zip oficial em https, com SHA256 fixado', function () {
    expect(Tcc::URL)->toBe('https://download-mirror.savannah.gnu.org/releases/tinycc/tcc-0.9.27-win64-bin.zip')
        ->and(Tcc::SHA256)->toMatch('/^[0-9a-f]{64}$/')
        ->and(Tcc::fetch('https://example.invalid/tcc.zip'))->toBeNull()
        ->and(Tcc::fetch('http://download-mirror.savannah.gnu.org/releases/tinycc/tcc-0.9.27-win64-bin.zip'))->toBeNull();
});

it('a extração só aceita entradas sob tcc/ sem escapar da pasta', function (string $nome, bool $ok) {
    expect(Tcc::safeEntry($nome))->toBe($ok);
})->with([
    ['tcc/libtcc.dll', true],
    ['tcc/include/stdio.h', true],
    ['tcc/', true],
    ['../tcc/x', false],
    ['tcc/../../x', false],
    ['tcc/./x', false],
    ['outro/x', false],
    ['tcc\\x', false],
    ["tcc/x\0", false],
    ['/tcc/x', false],
    ['tcc/c:x', false],
]);

it('zip com hash diferente não instala nada', function () {
    $pasta = cPasta();
    $dir   = $pasta . DIRECTORY_SEPARATOR . 'tcc';
    $msg   = Tcc::install($dir, static fn (string $url): string => cZip(['tcc/libtcc.dll' => 'x']));

    expect($msg)->toContain('diferente do fixado')->toContain('Nada foi instalado')
        ->and(is_dir($dir))->toBeFalse()
        ->and(Tcc::status($dir)->installed())->toBeFalse();

    cRemove($pasta);
});

it('download que falha não instala nada', function () {
    $pasta = cPasta();
    $dir   = $pasta . DIRECTORY_SEPARATOR . 'tcc';
    $pedida = null;
    $msg   = Tcc::install($dir, static function (string $url) use (&$pedida): ?string {
        $pedida = $url;

        return null;
    });

    expect($msg)->toContain('Não deu para baixar')
        ->and($pedida)->toBe(Tcc::URL)
        ->and(is_dir($dir))->toBeFalse();

    cRemove($pasta);
});

it('zip com hash certo extrai, grava a marca e fica idempotente', function () {
    $pasta = cPasta();
    $dir   = $pasta . DIRECTORY_SEPARATOR . 'tcc';
    $zip   = cZip(['tcc/libtcc.dll' => 'dll', 'tcc/include/stdio.h' => '/* h */']);
    $sha   = hash('sha256', $zip);

    $msg = Tcc::install($dir, static fn (string $url): string => $zip, $sha);
    $st  = Tcc::status($dir, $sha);

    expect($msg)->toContain('instalado em')->toContain('sem mexer no PATH')
        ->and($st->installed())->toBeTrue()
        ->and($st->version)->toBe(Tcc::VERSION)
        ->and($st->libtcc)->toBe(Tcc::libDir($dir) . DIRECTORY_SEPARATOR . 'libtcc.dll')
        ->and(Tcc::status($dir)->installed())->toBeFalse()
        ->and(glob($pasta . DIRECTORY_SEPARATOR . 'tcc.tmp-*'))->toBe([]);

    $chamou = false;
    $denovo = Tcc::install($dir, static function (string $url) use (&$chamou): ?string {
        $chamou = true;

        return null;
    }, $sha);

    expect($denovo)->toContain('já está instalado')->and($chamou)->toBeFalse();

    cRemove($pasta);
});

it('zip com hash certo mas entrada perigosa é recusado inteiro', function () {
    $pasta = cPasta();
    $dir   = $pasta . DIRECTORY_SEPARATOR . 'tcc';
    $zip   = cZip(['tcc/libtcc.dll' => 'dll', 'tcc/../../fora.txt' => 'x']);

    $msg = Tcc::install($dir, static fn (string $url): string => $zip, hash('sha256', $zip));

    expect($msg)->toContain('Nada foi instalado')
        ->and(is_dir($dir))->toBeFalse()
        ->and(file_exists($pasta . DIRECTORY_SEPARATOR . 'fora.txt'))->toBeFalse();

    cRemove($pasta);
});

it('zip sem libtcc.dll não instala', function () {
    $pasta = cPasta();
    $dir   = $pasta . DIRECTORY_SEPARATOR . 'tcc';
    $zip   = cZip(['tcc/tcc.exe' => 'exe']);

    $msg = Tcc::install($dir, static fn (string $url): string => $zip, hash('sha256', $zip));

    expect($msg)->toContain('libtcc.dll')->toContain('Nada foi instalado')
        ->and(is_dir($dir))->toBeFalse();

    cRemove($pasta);
});

it('fora do Windows o botão de instalar não baixa nada', function () {
    $chamou = false;
    $msg    = \App\Http\Pages::tccInstall(cPainel(new TccStatus('/tmp/x'), windows: false), static function (string $url) use (&$chamou): ?string {
        $chamou = true;

        return null;
    });

    expect($msg)->toContain('Windows x64')->and($chamou)->toBeFalse();
});

it('valida o código C', function (mixed $raw, ?string $erro) {
    if ($erro === null) {
        expect(CCode::validate($raw))->toBe(str_replace("\r\n", "\n", (string) $raw));

        return;
    }

    expect(static fn () => CCode::validate($raw))->toThrow(InvalidArgumentException::class, $erro);
})->with([
    ["int main(void){return 0;}\r\n", null],
    ['', 'Escreva'],
    ['   ', 'Escreva'],
    [null, 'Escreva'],
    [['x'], 'Escreva'],
    ["int main(){}\0", 'byte nulo'],
    ["\xC3\x28", 'UTF-8'],
]);

it('código acima de 64 KiB é recusado e no limite passa', function () {
    $cheio = str_repeat('a', CCode::MAX_CODE_BYTES);

    expect(CCode::MAX_CODE_BYTES)->toBe(65536)
        ->and(CCode::TIMEOUT_SECONDS)->toBe(10)
        ->and(CCode::MAX_OUTPUT_BYTES)->toBe(262144)
        ->and(CCode::validate($cheio))->toBe($cheio)
        ->and(static fn () => CCode::validate($cheio . 'a'))->toThrow(InvalidArgumentException::class);
});

it('o filho é php com lista de argumentos, sem shell e sem elevação', function () {
    $tcc  = cInstalado();
    $argv = CCode::arguments('C:\\php\\php.exe', $tcc, 'C:\\PHPorto\\files\\c-1.c');

    expect($argv[0])->toBe('C:\\php\\php.exe')
        ->and($argv)->toContain((string) $tcc->libtcc)
        ->and(end($argv))->toBe('C:\\PHPorto\\files\\c-1.c')
        ->and(is_file($argv[3]))->toBeTrue()
        ->and(implode(' ', $argv))->not->toContain('RunAs')->not->toContain('powershell');
});

it('roda pelo compilador falso e devolve saída e exit', function () {
    $fake = new FakeCCompiler(new PsResult("ola\n", 3, false, 40));
    $run  = CCode::execute($fake, cInstalado(), "int main(void){return 3;}\r\n");

    expect($fake->codes)->toBe(["int main(void){return 3;}\n"])
        ->and($run->output)->toBe("ola\n")
        ->and($run->exitCode)->toBe(3)
        ->and($run->durationMs)->toBe(40)
        ->and(CRun::fromArray($run->toArray()))->toEqual($run);
});

it('sem TCC instalado recusa antes de chamar o compilador', function () {
    $fake = new FakeCCompiler();

    expect(static fn () => CCode::execute($fake, new TccStatus('C:\\x'), 'int main(){return 0;}'))
        ->toThrow(InvalidArgumentException::class, 'Instale o Tiny C Compiler')
        ->and($fake->codes)->toBe([]);
});

it('sessão estragada não vira resultado', function (mixed $dados) {
    expect(CRun::fromArray($dados))->toBeNull();
})->with([[null], ['x'], [['code' => 1]], [['code' => 'a']]]);

it('o painel bloqueia sem Windows, sem FFI ou com ffi.enable false', function () {
    expect(cPainel()->blocked())->toBeNull()
        ->and(cPainel()->canRun())->toBeTrue()
        ->and(cPainel(windows: false)->blocked())->toContain('Windows')
        ->and(cPainel(ffi: false)->blocked())->toContain('FFI')
        ->and(cPainel(enable: 'false')->blocked())->toContain('ffi.enable')
        ->and(cPainel(enable: 'preload')->blocked())->toBeNull()
        ->and(cPainel(new TccStatus('C:\\x'))->canRun())->toBeFalse();
});

it('ffi.enable aceita só false, preload e true', function () {
    expect(IniKey::FfiEnable->choices())->toBe(['false', 'preload', 'true'])
        ->and(IniKey::FfiEnable->normalize('preload'))->toBe('preload')
        ->and(IniKey::FfiEnable->normalize('1'))->toBe('true')
        ->and(IniKey::FfiEnable->normalize(''))->toBe('false')
        ->and(IniKey::FfiEnable->validate('true'))->toBe('true')
        ->and(static fn () => IniKey::FfiEnable->validate('talvez'))->toThrow(InvalidArgumentException::class);
});

it('a tela mostra o botão de instalar quando falta o TCC', function () {
    $html = cHtml(cPainel(new TccStatus('C:\\PHPorto\\storage\\tcc')));

    expect($html)->toContain('id="php-c"')
        ->toContain('Instalar Tiny C Compiler')
        ->toContain('value="' . Tcc::ACTION . '"')
        ->toContain(Tcc::SHA256)
        ->toContain('com os seus poderes')
        ->toMatch('/id="c-rodar" disabled/');
});

it('a tela mostra versão, libtcc.dll e o último resultado no console', function () {
    $run  = new CRun('int main(void){return 2;}', "<b>oi</b>\n", 2, false, 1500, 1);
    $html = cHtml(cPainel(last: $run));

    expect($html)->not->toContain('id="btn-instalar-tcc"')
        ->toContain('C:\\PHPorto\\storage\\tcc\\tcc\\libtcc.dll')
        ->toContain('&lt;b&gt;oi&lt;/b&gt;')
        ->toContain('id="c-exit">2<')
        ->toContain('name="' . CCode::FIELD . '"')
        ->toContain('maxlength="65536"')
        ->toContain('data-sem-reload')
        ->not->toMatch('/id="c-rodar" disabled/')
        ->not->toMatch('/<script[^>]+src=/')
        ->not->toMatch('/<link[^>]+href="http/');
});

it('o POST da seção C consome o CSRF antes de qualquer ramo e não eleva', function () {
    $fonte = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Http/Pages.php');
    $corpo = substr($fonte, (int) strpos($fonte, 'private function handlePhpPost'));
    $csrf  = strpos($corpo, 'Csrf::consume');

    expect($csrf)->not->toBeFalse()
        ->and($csrf)->toBeLessThan((int) strpos($corpo, 'Tcc::ACTION'))
        ->and($csrf)->toBeLessThan((int) strpos($corpo, 'CCode::ACTION'));

    foreach (['CCode.php', 'CProcess.php', 'Tcc.php', 'runner.php'] as $arquivo) {
        $c = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Php/C/' . $arquivo);

        expect($c)->not->toContain('RunAs')->not->toContain('OneShot')->not->toContain('Elevation')->not->toContain('shell_exec')->not->toContain('exec(');
    }
});
