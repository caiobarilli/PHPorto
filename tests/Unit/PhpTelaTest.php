<?php

declare(strict_types=1);

use App\Http\PhpView;
use App\Http\Respond;
use App\Php\PhpBuild;
use App\Php\PhpProbe;

function phpBuild(?string $ini = 'C:\\php\\php.ini', ?bool $upload = true, bool $padrao = false, string $binario = 'C:\\php\\php.exe'): PhpBuild
{
    return new PhpBuild(
        version: '8.5.12',
        threadSafe: false,
        binary: $binario,
        ini: $ini,
        sapi: 'cli-server',
        compiler: 'Visual C++ 2022',
        arch: 'x64',
        uploadProgress: $upload,
        uploadProgressDefault: $padrao,
    );
}

function phpHtml(PhpView $view): string
{
    return Respond::render('php.php', $view);
}

function phpPastaTemp(): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phporto-php-' . bin2hex(random_bytes(4));
    mkdir($dir);

    return $dir;
}

it('lê o campo do phpinfo em texto e em HTML', function () {
    $texto = "PHP Version => 8.5.12\nCompiler => Visual C++ 2022\nArchitecture => x64\n";
    $html  = '<tr><td class="e">Compiler </td><td class="v">Visual C++ 2022 </td></tr>'
        . '<tr><td class="e">Architecture </td><td class="v">x64 </td></tr>';

    expect(PhpProbe::infoField($texto, 'Compiler'))->toBe('Visual C++ 2022')
        ->and(PhpProbe::infoField($texto, 'Architecture'))->toBe('x64')
        ->and(PhpProbe::infoField($html, 'Compiler'))->toBe('Visual C++ 2022')
        ->and(PhpProbe::infoField($html, 'Architecture'))->toBe('x64')
        ->and(PhpProbe::infoField($texto, 'Thread Safety'))->toBeNull();
});

it('a linha Thread junta TS/NTS, compilador e arquitetura, pulando o que falta', function () {
    expect(phpBuild()->thread())->toBe('NTS Visual C++ 2022 x64')
        ->and((new PhpBuild('8.5.12', true, '/usr/bin/php', null, 'cli'))->thread())->toBe('TS');
});

it('o valor do ini vira On/Off, e ausente vira nulo', function (string|false $valor, ?bool $esperado) {
    expect(PhpProbe::flag($valor))->toBe($esperado);
})->with([
    ['1', true], ['On', true], ['', false], ['0', false], ['Off', false], [false, null],
]);

it('a chave só conta como declarada fora de comentário', function () {
    expect(PhpProbe::declares("[Session]\nsession.upload_progress.enabled = Off\n", PhpProbe::UPLOAD_PROGRESS))->toBeTrue()
        ->and(PhpProbe::declares(";session.upload_progress.enabled = On\n", PhpProbe::UPLOAD_PROGRESS))->toBeFalse()
        ->and(PhpProbe::declares("session.upload_progress.name = x\n", PhpProbe::UPLOAD_PROGRESS))->toBeFalse();
});

it('a lista de inis junta o carregado e os escaneados', function () {
    expect(PhpProbe::iniFiles('/a/php.ini', "/b/10-x.ini,\n/b/20-y.ini\n"))->toBe(['/a/php.ini', '/b/10-x.ini', '/b/20-y.ini'])
        ->and(PhpProbe::iniFiles(null, false))->toBe([]);
});

it('procura o php no PATH na ordem, pulando pasta vazia e aspas', function () {
    $a = phpPastaTemp();
    $b = phpPastaTemp();
    touch($b . DIRECTORY_SEPARATOR . 'php.exe');

    try {
        $caminho = implode(PATH_SEPARATOR, ['', $a, '"' . $b . '"']);

        expect(PhpProbe::onPath($caminho, PATH_SEPARATOR, 'php.exe'))->toBe($b . DIRECTORY_SEPARATOR . 'php.exe')
            ->and(PhpProbe::onPath($a, PATH_SEPARATOR, 'php.exe'))->toBeNull();
    } finally {
        unlink($b . DIRECTORY_SEPARATOR . 'php.exe');
        rmdir($a);
        rmdir($b);
    }
});

it('compara binários com ou sem diferença de caixa', function () {
    expect(PhpProbe::sameFile('C:\\PHP\\php.exe', 'c:\\php\\PHP.EXE', true))->toBeTrue()
        ->and(PhpProbe::sameFile('C:\\PHP\\php.exe', 'c:\\php\\PHP.EXE', false))->toBeFalse();
});

it('a saída do php -r só vira medida se tiver as cinco partes certas', function () {
    $ok = PhpProbe::fromJson('["8.4.26",false,"C:\\\\x\\\\php.exe",false,"cli"]');

    expect($ok)->not->toBeNull()
        ->and($ok?->version)->toBe('8.4.26')
        ->and($ok?->ini)->toBeNull()
        ->and(PhpProbe::fromJson('Warning: algo'))->toBeNull()
        ->and(PhpProbe::fromJson('["8.4.26",false,"x",false]'))->toBeNull()
        ->and(PhpProbe::fromJson('["8.4.26","nao","x",false,"cli"]'))->toBeNull()
        ->and(PhpProbe::fromJson('["",false,"x",false,"cli"]'))->toBeNull();
});

it('mede o próprio binário do PHP com php -r', function () {
    $medido = PhpProbe::measure(PHP_BINARY);

    expect($medido)->not->toBeNull()
        ->and($medido?->version)->toBe(PHP_VERSION)
        ->and($medido?->sapi)->toBe('cli');
});

it('binário que não existe não vira medida', function () {
    expect(PhpProbe::measure(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nao-existe-' . bin2hex(random_bytes(4))))->toBeNull();
});

it('a medida do processo atual traz a versão e o binário em uso', function () {
    $atual = PhpProbe::current();

    expect($atual)->not->toBeNull()
        ->and($atual?->version)->toBe(PHP_VERSION)
        ->and($atual?->binary)->toBe(PHP_BINARY)
        ->and($atual?->sapi)->toBe(PHP_SAPI);
});

it('PATH igual ao binário em uso não diverge e não mede de novo', function () {
    $medidas = 0;
    $view    = PhpView::make(phpBuild(), 'c:\\PHP\\php.exe', true, function (string $b) use (&$medidas): ?PhpBuild {
        $medidas++;

        return null;
    });

    $html = phpHtml($view);

    expect($view->pathDiverges)->toBeFalse()
        ->and($medidas)->toBe(0)
        ->and($html)->toContain('8.5.12')
        ->and($html)->toContain('NTS Visual C++ 2022 x64')
        ->and($html)->toContain('C:\\php\\php.ini')
        ->and($html)->toContain('cli-server')
        ->and($html)->toContain('<dd class="bad">On</dd>')
        ->and($html)->not->toContain('id="php-diverge"')
        ->and($html)->not->toContain('id="php-path"')
        ->and($html)->not->toContain('id="php-falha"');
});

it('PATH com outro php avisa e mostra os dois', function () {
    $outro = new PhpBuild('8.4.26', false, 'D:\\outro\\php.exe', 'D:\\outro\\php.ini', 'cli');
    $view  = PhpView::make(phpBuild(), 'D:\\outro\\php.exe', true, fn (string $b): ?PhpBuild => $outro);
    $html  = phpHtml($view);

    expect($view->pathDiverges)->toBeTrue()
        ->and($html)->toContain('id="php-diverge"')
        ->and($html)->toContain('id="php-path"')
        ->and($html)->toContain('8.4.26')
        ->and($html)->toContain('D:\\outro\\php.ini');
});

it('PATH com outro php que não dá para medir mostra só o binário', function () {
    $html = phpHtml(PhpView::make(phpBuild(), 'D:\\outro\\php.exe', true, fn (string $b): ?PhpBuild => null));

    expect($html)->toContain('id="php-path"')
        ->and($html)->toContain('Não deu para medir este binário')
        ->and($html)->toContain('D:\\outro\\php.exe');
});

it('sem php.ini carregado mostra traço, default e a dica', function () {
    $html = phpHtml(PhpView::make(phpBuild(ini: null, padrao: true), null, true, fn (string $b): ?PhpBuild => null));

    expect($html)->toContain('<dt>php.ini</dt><dd>—</dd>')
        ->and($html)->toContain('On <span class="tag">default</span>')
        ->and($html)->not->toContain('class="bad"')
        ->and($html)->toContain('Nenhum <code>php.ini</code> carregado')
        ->and($html)->toContain('Nenhum <code>php</code> no PATH');
});

it('sem extensão de sessão o upload_progress vira traço', function () {
    $html = phpHtml(PhpView::make(phpBuild(upload: null), null, true, fn (string $b): ?PhpBuild => null));

    expect($html)->toContain('sem sessão');
});

it('sem medida do processo mostra a falha e nada mais', function () {
    $html = phpHtml(PhpView::make(null, 'C:\\php\\php.exe', true, fn (string $b): ?PhpBuild => null));

    expect($html)->toContain('id="php-falha"')
        ->and($html)->not->toContain('id="php-ativo"')
        ->and($html)->not->toContain('id="php-path"');
});

it('o texto da medida é escapado', function () {
    $html = phpHtml(PhpView::make(phpBuild(binario: 'C:\\<b>\\php.exe'), null, true, fn (string $b): ?PhpBuild => null));

    expect($html)->toContain('C:\\&lt;b&gt;\\php.exe')
        ->and($html)->not->toContain('<b>');
});

it('a tela só lê: sem formulário, sem UAC, sem abas das próximas fatias', function () {
    $fonte = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Views/php.php');

    expect($fonte)->not->toContain('<form')
        ->and($fonte)->not->toContain('data-uac')
        ->and($fonte)->not->toContain('<script')
        ->and($fonte)->not->toContain('class="abas"')
        ->and($fonte)->not->toContain('https://');
});

it('a rota /php existe no index e no router, e só lê', function () {
    $raiz = dirname(__DIR__, 2);

    expect((string) file_get_contents($raiz . '/public/index.php'))->toContain("'/php'    => \$pages->php(),")
        ->and((string) file_get_contents($raiz . '/public/router.php'))->toContain("'/hyperv', '/php']");
});

it('a /config leva para a /php, e a home não', function () {
    $html = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Views/config.php');

    expect($html)->toContain('<a href="/php" class="btn btn-sm btn-ghost" id="link-php">')
        ->and((string) file_get_contents(dirname(__DIR__, 2) . '/src/Views/home.php'))->not->toContain('/php');
});
