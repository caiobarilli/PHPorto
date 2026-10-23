<?php

declare(strict_types=1);

use App\Http\Pages;
use App\Http\PhpView;
use App\Http\Respond;
use App\Php\IniFile;
use App\Php\IniForm;
use App\Php\IniKey;
use App\Php\IniRow;
use App\Php\PhpBuild;
use App\Php\PhpIni;

function iniPasta(): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phporto-ini-' . bin2hex(random_bytes(4));
    mkdir($dir);

    return $dir;
}

function iniLimpar(string $dir): void
{
    foreach ((array) scandir($dir) as $nome) {
        if (is_string($nome) && $nome !== '.' && $nome !== '..') {
            @chmod($dir, 0700);
            unlink($dir . DIRECTORY_SEPARATOR . $nome);
        }
    }

    @chmod($dir, 0700);
    rmdir($dir);
}

function iniArquivo(string $dir, string $texto): string
{
    $ini = $dir . DIRECTORY_SEPARATOR . 'php.ini';
    file_put_contents($ini, $texto);

    return $ini;
}

/**
 * @return array<string, string>
 */
function iniPost(IniForm $form, array $troca = []): array
{
    $post = [];

    foreach ($form->rows as $linha) {
        $post[$linha->key->value] = $linha->value;
    }

    return array_merge($post, $troca);
}

function iniForm(string $ini): IniForm
{
    $form = PhpIni::load($ini, [], static fn (string $c): false => false);

    expect($form)->not->toBeNull();

    return $form ?? throw new RuntimeException('sem form');
}

it('a lista de chaves é fechada e tem só estas sete', function () {
    expect(array_map(static fn (IniKey $k): string => $k->value, IniKey::cases()))->toBe([
        'session.upload_progress.enabled',
        'upload_max_filesize',
        'post_max_size',
        'memory_limit',
        'max_execution_time',
        'date.timezone',
        'display_errors',
    ]);
});

it('valida cada valor', function (IniKey $chave, string $valor, ?string $esperado) {
    if ($esperado === null) {
        expect(fn () => $chave->validate($valor))->toThrow(InvalidArgumentException::class);

        return;
    }

    expect($chave->validate($valor))->toBe($esperado);
})->with([
    'switch On'              => [IniKey::UploadProgress, 'On', 'On'],
    'switch Off'             => [IniKey::DisplayErrors, 'Off', 'Off'],
    'switch 1'               => [IniKey::UploadProgress, '1', null],
    'switch stderr'          => [IniKey::DisplayErrors, 'stderr', null],
    'upload 64m'             => [IniKey::UploadMaxFilesize, '64m', '64M'],
    'upload 2048K'           => [IniKey::UploadMaxFilesize, '2048K', '2048K'],
    'upload sem unidade'     => [IniKey::UploadMaxFilesize, '2097152', null],
    'upload pequeno'         => [IniKey::UploadMaxFilesize, '512K', null],
    'upload grande'          => [IniKey::UploadMaxFilesize, '17G', null],
    'upload zero'            => [IniKey::UploadMaxFilesize, '0M', null],
    'post 16G'               => [IniKey::PostMaxSize, '16G', '16G'],
    'post com espaço'        => [IniKey::PostMaxSize, ' 8M', null],
    'memory -1'              => [IniKey::MemoryLimit, '-1', '-1'],
    'memory 256M'            => [IniKey::MemoryLimit, '256M', '256M'],
    'memory 16M'             => [IniKey::MemoryLimit, '16M', null],
    'memory -2'              => [IniKey::MemoryLimit, '-2', null],
    'tempo 0'                => [IniKey::MaxExecutionTime, '0', '0'],
    'tempo 3600'             => [IniKey::MaxExecutionTime, '3600', '3600'],
    'tempo 3601'             => [IniKey::MaxExecutionTime, '3601', null],
    'tempo negativo'         => [IniKey::MaxExecutionTime, '-1', null],
    'tempo 030'              => [IniKey::MaxExecutionTime, '030', null],
    'fuso válido'            => [IniKey::Timezone, 'America/Sao_Paulo', 'America/Sao_Paulo'],
    'fuso inventado'         => [IniKey::Timezone, 'Marte/Base', null],
    'fuso com aspas'         => [IniKey::Timezone, '"UTC"', null],
    'quebra de linha'        => [IniKey::MemoryLimit, "128M\nextension=x", null],
    'texto ini cru'          => [IniKey::Timezone, "UTC\nauto_prepend_file=x", null],
    'longo demais'           => [IniKey::Timezone, str_repeat('a', 65), null],
]);

it('recusa chave fora da lista, sem gravar nada', function () {
    $dir   = iniPasta();
    $texto = "[PHP]\r\nmemory_limit = 128M\r\n";
    $ini   = iniArquivo($dir, $texto);

    try {
        $form = iniForm($ini);
        $msg  = Pages::phpIniSave($ini, [], $form->hash, iniPost($form, ['auto_prepend_file' => '/tmp/x.php']));

        expect($msg)->toContain('Chave fora da lista: auto_prepend_file')
            ->and($msg)->toContain('Nada foi gravado')
            ->and(file_get_contents($ini))->toBe($texto)
            ->and(is_file($ini . '.bak'))->toBeFalse();
    } finally {
        iniLimpar($dir);
    }
});

it('recusa formulário sem lista, com chave faltando ou valor que não é texto', function () {
    $rows = iniForm(iniArquivo($dir = iniPasta(), "[PHP]\n"))->rows;
    iniLimpar($dir);

    $post = [];

    foreach ($rows as $linha) {
        $post[$linha->key->value] = $linha->value;
    }

    $sem = $post;
    unset($sem['memory_limit']);

    expect(fn () => PhpIni::changes($rows, 'memory_limit=1G'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => PhpIni::changes($rows, $sem))->toThrow(InvalidArgumentException::class, 'memory_limit: faltou o valor.')
        ->and(fn () => PhpIni::changes($rows, array_merge($post, ['memory_limit' => ['1G']])))->toThrow(InvalidArgumentException::class)
        ->and(fn () => PhpIni::changes($rows, array_merge($post, [0 => 'x'])))->toThrow(InvalidArgumentException::class, 'Chave fora da lista')
        ->and(PhpIni::changes($rows, $post))->toBe([]);
});

it('post_max_size menor que upload_max_filesize não passa', function () {
    $dir = iniPasta();

    try {
        $form = iniForm(iniArquivo($dir, "[PHP]\nupload_max_filesize = 2M\npost_max_size = 8M\n"));

        expect(fn () => PhpIni::changes($form->rows, iniPost($form, ['upload_max_filesize' => '64M'])))
            ->toThrow(InvalidArgumentException::class, 'post_max_size não pode ser menor que upload_max_filesize.')
            ->and(PhpIni::changes($form->rows, iniPost($form, ['upload_max_filesize' => '64M', 'post_max_size' => '1G'])))
            ->toBe(['upload_max_filesize' => '64M', 'post_max_size' => '1G']);
    } finally {
        iniLimpar($dir);
    }
});

it('valor estranho já no arquivo não trava as outras chaves', function () {
    $dir = iniPasta();

    try {
        $form = iniForm(iniArquivo($dir, "[PHP]\nmemory_limit = 999999999\n"));

        expect(PhpIni::changes($form->rows, iniPost($form, ['display_errors' => 'Off'])))->toBe(['display_errors' => 'Off']);
    } finally {
        iniLimpar($dir);
    }
});

it('grava com .bak do original e deixa o resto do arquivo igual, CRLF e BOM inclusos', function () {
    $dir   = iniPasta();
    $texto = "\xEF\xBB\xBF[PHP]\r\n"
        . "; comentário com acento\r\n"
        . "engine = On\r\n"
        . ";memory_limit = 64M\r\n"
        . "memory_limit = 128M ; atual\r\n"
        . "  display_errors=On\r\n"
        . "\r\n"
        . "[Date]\r\n"
        . ";date.timezone =\r\n"
        . "\r\n"
        . "[Session]\r\n"
        . "session.save_handler = files\r\n";
    $ini = iniArquivo($dir, $texto);

    try {
        $form = iniForm($ini);
        $msg  = Pages::phpIniSave($ini, [], $form->hash, iniPost($form, [
            'memory_limit'   => '256m',
            'display_errors' => 'Off',
            'date.timezone'  => 'America/Sao_Paulo',
        ]));

        $esperado = "\xEF\xBB\xBF[PHP]\r\n"
            . "; comentário com acento\r\n"
            . "engine = On\r\n"
            . ";memory_limit = 64M\r\n"
            . "memory_limit = 256M\r\n"
            . "  display_errors = Off\r\n"
            . "\r\n"
            . "[Date]\r\n"
            . ";date.timezone =\r\n"
            . "date.timezone = \"America/Sao_Paulo\"\r\n"
            . "\r\n"
            . "[Session]\r\n"
            . "session.save_handler = files\r\n";

        expect($msg)->toContain('php.ini gravado: memory_limit = 256M, date.timezone = America/Sao_Paulo, display_errors = Off')
            ->and($msg)->toContain('php.ini.bak')
            ->and($msg)->toContain('suba o php -S de novo')
            ->and(file_get_contents($ini . '.bak'))->toBe($texto)
            ->and(file_get_contents($ini))->toBe($esperado)
            ->and(glob($dir . DIRECTORY_SEPARATOR . '.phporto-ini-*'))->toBe([]);

        $relido = iniForm($ini);

        expect($relido->rows[3]->value)->toBe('256M')
            ->and($relido->rows[5]->value)->toBe('America/Sao_Paulo')
            ->and($relido->rows[6]->value)->toBe('Off');
    } finally {
        iniLimpar($dir);
    }
});

it('chave sem exemplo vai para o fim, e depois de seção HOST abre [PHP] de novo', function () {
    $lf = "[PHP]\nengine = On\n[HOST=dev.local]\nmemory_limit = 1G";

    expect(IniFile::apply($lf, ['memory_limit' => '256M']))->toBe("[PHP]\nengine = On\n[HOST=dev.local]\nmemory_limit = 1G\n[PHP]\nmemory_limit = 256M\n")
        ->and(IniFile::apply("engine = On\r\n", ['post_max_size' => '64M']))->toBe("engine = On\r\npost_max_size = 64M\r\n")
        ->and(IniFile::apply('', ['post_max_size' => '64M']))->toBe('post_max_size = 64M' . PHP_EOL)
        ->and(IniFile::declared($lf, IniKey::MemoryLimit))->toBeNull();
});

it('troca só a última declaração global da chave', function () {
    $texto = "memory_limit = 64M\n[PATH=C:\\site]\nmemory_limit = 1G\n[PHP]\nmemory_limit = 128M\n";

    expect(IniFile::apply($texto, ['memory_limit' => '512M']))
        ->toBe("memory_limit = 64M\n[PATH=C:\\site]\nmemory_limit = 1G\n[PHP]\nmemory_limit = 512M\n");
});

it('php.ini que mudou no disco depois de aberto não é gravado', function () {
    $dir = iniPasta();
    $ini = iniArquivo($dir, "[PHP]\nmemory_limit = 128M\n");

    try {
        $form = iniForm($ini);
        file_put_contents($ini, "[PHP]\nmemory_limit = 128M\nengine = On\n");

        $msg = Pages::phpIniSave($ini, [], $form->hash, iniPost($form, ['memory_limit' => '256M']));

        expect($msg)->toContain('O php.ini mudou no disco')
            ->and(file_get_contents($ini))->toBe("[PHP]\nmemory_limit = 128M\nengine = On\n")
            ->and(is_file($ini . '.bak'))->toBeFalse()
            ->and(Pages::phpIniSave($ini, [], 'nao-e-hash', iniPost($form)))->toContain('Nada foi gravado');
    } finally {
        iniLimpar($dir);
    }
});

it('sem php.ini carregado recusa', function () {
    expect(Pages::phpIniSave(false, [], str_repeat('a', 64), ['memory_limit' => '256M']))
        ->toContain('Nenhum php.ini carregado')
        ->and(PhpIni::load(null, [], static fn (string $c): false => false))->toBeNull();
});

it('php.ini que não é arquivo comum vira erro e não grava', function () {
    $dir = iniPasta();

    try {
        $falso = $dir . DIRECTORY_SEPARATOR . 'php.ini';
        mkdir($falso);
        $form = PhpIni::load($falso, [], static fn (string $c): false => false);
        rmdir($falso);

        expect($form?->error)->toContain('não é um arquivo comum')
            ->and($form?->writable)->toBeFalse();
    } finally {
        iniLimpar($dir);
    }
});

it('nada mudou: não grava nem cria .bak', function () {
    $dir   = iniPasta();
    $texto = "[PHP]\nmemory_limit = 128M\n";
    $ini   = iniArquivo($dir, $texto);

    try {
        $form = iniForm($ini);

        expect(Pages::phpIniSave($ini, [], $form->hash, iniPost($form)))->toContain('Nenhum valor mudou')
            ->and(is_file($ini . '.bak'))->toBeFalse()
            ->and(file_get_contents($ini))->toBe($texto);
    } finally {
        iniLimpar($dir);
    }
});

it('pasta sem permissão de escrita: recusa e não pede UAC', function () {
    if (PHP_OS_FAMILY === 'Windows' || (function_exists('posix_geteuid') && posix_geteuid() === 0)) {
        $this->markTestSkipped('precisa de usuário comum fora do Windows');
    }

    $dir   = iniPasta();
    $texto = "[PHP]\nmemory_limit = 128M\n";
    $ini   = iniArquivo($dir, $texto);

    try {
        $form = iniForm($ini);
        chmod($dir, 0500);

        $msg = Pages::phpIniSave($ini, [], $form->hash, iniPost($form, ['memory_limit' => '256M']));

        expect(PhpIni::writable($ini))->toBeFalse()
            ->and($msg)->toContain('não grava com UAC')
            ->and(file_get_contents($ini))->toBe($texto)
            ->and(is_file($ini . '.bak'))->toBeFalse();
    } finally {
        chmod($dir, 0700);
        iniLimpar($dir);
    }
});

it('ini escaneado que declara a chave aparece como quem vence', function () {
    $dir = iniPasta();

    try {
        $ini   = iniArquivo($dir, "[PHP]\n");
        $extra = $dir . DIRECTORY_SEPARATOR . '99-extra.ini';
        file_put_contents($extra, "memory_limit = 1G\n");
        $form = PhpIni::load($ini, [$extra], static fn (string $c): false => false);

        expect($form?->rows[3]->overriddenBy)->toBe($extra)
            ->and($form?->rows[2]->overriddenBy)->toBeNull();
    } finally {
        iniLimpar($dir);
    }
});

it('compara tamanhos em bytes e mostra o que difere do padrão', function () {
    expect((new IniRow(IniKey::UploadMaxFilesize, '2048K', true))->differsFromDefault())->toBeFalse()
        ->and((new IniRow(IniKey::UploadMaxFilesize, '64M', true))->differsFromDefault())->toBeTrue()
        ->and((new IniRow(IniKey::MemoryLimit, '256M', true, '128M'))->pendingRestart())->toBeTrue()
        ->and((new IniRow(IniKey::MemoryLimit, '256M', true, '256M'))->pendingRestart())->toBeFalse()
        ->and((new IniRow(IniKey::DisplayErrors, 'Off', true, 'Off'))->differsFromDefault())->toBeTrue();
});

it('fuso não definido em uso conta como o padrão', function () {
    $dir = iniPasta();

    try {
        $form = PhpIni::load(iniArquivo($dir, "[PHP]\n"), [], static fn (string $c): string => '');

        expect($form?->rows[5]->pendingRestart())->toBeFalse();
    } finally {
        iniLimpar($dir);
    }
});

function iniView(IniForm $form, ?string $aviso = null): string
{
    $build = new PhpBuild('8.5.12', false, 'C:\\php\\php.exe', $form->path, 'cli-server', 'Visual C++ 2022', 'x64', true, false);
    $view  = PhpView::make($build, null, true, fn (string $b): ?PhpBuild => null)->with($form, $aviso, 'tok<en>', '_csrf');

    return Respond::render('php.php', $view);
}

it('a tela mostra o formulário com as sete chaves, o que difere e o aviso de reinício', function () {
    $linhas = [];

    foreach (IniKey::cases() as $chave) {
        $linhas[] = new IniRow($chave, $chave === IniKey::MemoryLimit ? '256M' : $chave->default(), $chave === IniKey::MemoryLimit, $chave === IniKey::MemoryLimit ? '128M' : null);
    }

    $html = iniView(new IniForm('C:\\php\\php.ini', str_repeat('a', 64), $linhas, true), 'php.ini gravado: <x>');

    expect($html)->toContain('id="form-ini"')
        ->and($html)->toContain('name="ini[session.upload_progress.enabled]"')
        ->and($html)->toContain('name="ini[date.timezone]"')
        ->and($html)->toContain('name="ini_hash" value="' . str_repeat('a', 64) . '"')
        ->and($html)->toContain('value="tok&lt;en&gt;"')
        ->and(substr_count($html, 'data-difere'))->toBe(1)
        ->and(substr_count($html, 'data-pendente'))->toBe(1)
        ->and($html)->toContain('id="btn-salvar-ini"')
        ->and($html)->toContain('id="modal-ini"')
        ->and($html)->toContain('php.ini.bak')
        ->and($html)->toContain('feche o <code>php -S</code> e suba de novo')
        ->and($html)->toContain('php.ini gravado: &lt;x&gt;')
        ->and($html)->not->toContain('<x>');
});

it('sem permissão de escrita a tela não oferece salvar', function () {
    $linhas = array_map(static fn (IniKey $k): IniRow => new IniRow($k, $k->default(), false), IniKey::cases());
    $html   = iniView(new IniForm('C:\\Program Files\\php\\php.ini', str_repeat('b', 64), $linhas, false));

    expect($html)->toContain('id="ini-somente-leitura"')
        ->and($html)->not->toContain('id="btn-salvar-ini"')
        ->and($html)->not->toContain('id="modal-ini"')
        ->and($html)->toContain('id="btn-reler-ini"');
});

it('o fonte da tela não traz UAC, abas, CDN nem instalação', function () {
    $fonte = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Views/php.php');

    expect($fonte)->not->toContain('data-uac')
        ->and($fonte)->not->toContain('class="abas"')
        ->and($fonte)->not->toContain('https://')
        ->and($fonte)->not->toContain('.zip')
        ->and($fonte)->not->toContain('<textarea');
});
