<?php

declare(strict_types=1);

use App\Http\Csrf;
use App\Wsl\Runner;
use App\Wsl\RunResult;
use App\Wsl\ScriptBuilder;
use App\Wsl\Upload;

/**
 * O envio do card de anexos, sem servidor e sem WSL.
 *
 * A rota sai por exit, então aqui ficam as peças que ela chama: o limite, a
 * conferência do arquivo recebido, o destino e a cópia temporária. O
 * is_uploaded_file e o move_uploaded_file só valem dentro de um POST de
 * verdade, então entram falsos; o WSL entra como um run falso que devolve o
 * RunResult. O script do cp é exercitado num bash local, fora do Windows.
 */

function uploadDir(): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phporto-upload-' . bin2hex(random_bytes(4));
    mkdir($dir, 0o775, true);

    return $dir;
}

function uploadFake(string $filesDir, bool $veioDeEnvio = true): Upload
{
    return new Upload(
        $filesDir,
        static fn (string $path): bool => $veioDeEnvio && is_file($path),
        static fn (string $from, string $to): bool => rename($from, $to),
    );
}

/**
 * @return array{name: string, type: string, tmp_name: string, error: int, size: int}
 */
function uploadArquivo(string $dir, string $conteudo, string $nome = 'notas.txt'): array
{
    $tmp = $dir . DIRECTORY_SEPARATOR . 'php' . bin2hex(random_bytes(3)) . '.tmp';
    file_put_contents($tmp, $conteudo);

    return ['name' => $nome, 'type' => 'text/plain', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($conteudo)];
}

function uploadLimpa(string $dir): void
{
    foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
        is_dir($f) ? uploadLimpa($f) : @unlink($f);
    }
    @rmdir($dir);
}

// ---- Limite -------------------------------------------------------------------

it('o limite é o menor entre 25 MB, upload_max_filesize e post_max_size', function (string $upload, string $post, int $esperado) {
    expect(Upload::limit($upload, $post))->toBe($esperado);
})->with([
    'php.ini padrão'      => ['2M', '8M', 2 * 1024 * 1024],
    'post menor'          => ['64M', '10M', 10 * 1024 * 1024],
    'os dois acima'       => ['100M', '1G', Upload::MAX_BYTES],
    'em K'                => ['512K', '8M', 512 * 1024],
    'post sem limite (0)' => ['30M', '0', Upload::MAX_BYTES],
    'valor ilegível'      => ['abc', '', Upload::MAX_BYTES],
]);

it('o limite sem argumento lê o php.ini da hora', function () {
    $esperado = min(
        Upload::MAX_BYTES,
        Upload::iniBytes((string) ini_get('upload_max_filesize')) ?: Upload::MAX_BYTES,
        Upload::iniBytes((string) ini_get('post_max_size')) ?: Upload::MAX_BYTES,
    );

    expect(Upload::limit())->toBe($esperado);
});

it('o corpo acima do post_max_size é reconhecido antes do token', function () {
    $server = ['REQUEST_METHOD' => 'POST', 'CONTENT_LENGTH' => (string) (9 * 1024 * 1024)];

    expect(Upload::postOverflow($server, [], [], '8M'))->toBeTrue()
        ->and(Upload::postOverflow(['CONTENT_LENGTH' => '100'] + $server, [], [], '8M'))->toBeFalse()
        ->and(Upload::postOverflow($server, ['acao' => 'anexo'], [], '8M'))->toBeFalse()
        ->and(Upload::postOverflow(['REQUEST_METHOD' => 'GET'] + $server, [], [], '8M'))->toBeFalse()
        ->and(Upload::postOverflow($server, [], [], '0'))->toBeFalse();
});

it('a /wsl confere o estouro antes do token, e o token antes do anexo', function () {
    $fonte = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Http/Pages.php');
    $corpo = substr($fonte, (int) strpos($fonte, 'private function handlePost('));

    $estouro = strpos($corpo, 'Upload::postOverflow(');
    $token   = strpos($corpo, 'Csrf::consume()');
    $anexo   = strpos($corpo, '$this->handleUpload();');

    expect($estouro)->toBeInt()
        ->and($estouro)->toBeLessThan($token)
        ->and($token)->toBeLessThan($anexo);
});

// ---- Token --------------------------------------------------------------------

it('o anexo sem token é recusado, mesmo com arquivo e destino', function () {
    Csrf::token();
    $_POST  = ['acao' => 'anexo', 'destino' => '~/notas.txt'];
    $_FILES = [Upload::FIELD => ['name' => 'notas.txt', 'tmp_name' => '/tmp/x', 'error' => UPLOAD_ERR_OK, 'size' => 3]];

    expect(Csrf::consume())->toBeFalse();

    $_POST[Csrf::fieldName()] = 'outro-token';
    expect(Csrf::consume())->toBeFalse();

    $_POST  = [];
    $_FILES = [];
});

// ---- Destino ------------------------------------------------------------------

it('o destino passa pela mesma validação de antes: preenchido e até 4.096 bytes', function () {
    expect(Upload::destination('  ~/pasta/notas.txt '))->toBe('~/pasta/notas.txt')
        ->and(Upload::destination(str_repeat('a', 4096)))->toHaveLength(4096);
});

it('o destino vazio, que não é texto ou acima do teto é recusado', function (mixed $destino, string $mensagem) {
    expect(fn () => Upload::destination($destino))->toThrow(InvalidArgumentException::class, $mensagem);
})->with([
    'vazio'      => ['', 'Preencha o destino do anexo.'],
    'espaços'    => ['   ', 'Preencha o destino do anexo.'],
    'ausente'    => [null, 'Preencha o destino do anexo.'],
    'lista'      => [['~/x'], 'Preencha o destino do anexo.'],
    'acima'      => [str_repeat('a', 4097), 'Destino passou do teto de 4096 bytes (tem 4097).'],
]);

// ---- Arquivo recebido ---------------------------------------------------------

it('aceita um arquivo que veio de envio e cabe no limite', function () {
    $dir = uploadDir();
    $arquivo = uploadArquivo($dir, 'abc', 'C:\\fakepath\\notas.txt');

    expect(uploadFake($dir)->accept($arquivo, 1024))
        ->toBe(['name' => 'notas.txt', 'size' => 3, 'tmp' => $arquivo['tmp_name']]);

    uploadLimpa($dir);
});

it('recusa arquivo acima do limite, pelo tamanho ou pelo código do PHP', function () {
    $dir = uploadDir();
    $grande = uploadArquivo($dir, str_repeat('x', 2048));

    expect(fn () => uploadFake($dir)->accept($grande, 1024))
        ->toThrow(InvalidArgumentException::class, 'O arquivo passou do limite');

    foreach ([UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE] as $erro) {
        expect(fn () => uploadFake($dir)->accept(['error' => $erro, 'name' => 'a', 'tmp_name' => '', 'size' => 0], 2 * 1024 * 1024))
            ->toThrow(InvalidArgumentException::class, 'O arquivo passou do limite de 2 MB por envio. Nada foi copiado.');
    }

    uploadLimpa($dir);
});

it('recusa o arquivo que não veio de um envio deste formulário', function () {
    $dir = uploadDir();

    expect(fn () => uploadFake($dir, false)->accept(uploadArquivo($dir, 'abc'), 1024))
        ->toThrow(InvalidArgumentException::class, 'O arquivo não chegou por um envio deste formulário.');

    uploadLimpa($dir);
});

it('cada código de erro do envio vira uma frase, sem copiar', function (mixed $arquivo, string $mensagem) {
    expect(fn () => uploadFake(sys_get_temp_dir())->accept($arquivo, 1024))->toThrow(InvalidArgumentException::class, $mensagem);
})->with([
    'sem campo'      => [null, 'Escolha um arquivo para enviar.'],
    'sem arquivo'    => [['error' => UPLOAD_ERR_NO_FILE, 'name' => '', 'tmp_name' => '', 'size' => 0], 'Escolha um arquivo para enviar.'],
    'dois arquivos'  => [['error' => [0, 0], 'name' => ['a', 'b'], 'tmp_name' => ['x', 'y'], 'size' => [1, 1]], 'Envie um arquivo por vez.'],
    'pela metade'    => [['error' => UPLOAD_ERR_PARTIAL, 'name' => 'a', 'tmp_name' => '', 'size' => 0], 'O arquivo chegou pela metade.'],
    'sem pasta tmp'  => [['error' => UPLOAD_ERR_NO_TMP_DIR, 'name' => 'a', 'tmp_name' => '', 'size' => 0], 'O servidor não conseguiu guardar'],
    'sem gravar'     => [['error' => UPLOAD_ERR_CANT_WRITE, 'name' => 'a', 'tmp_name' => '', 'size' => 0], 'O servidor não conseguiu guardar'],
    'extensão'       => [['error' => UPLOAD_ERR_EXTENSION, 'name' => 'a', 'tmp_name' => '', 'size' => 0], 'Uma extensão do PHP barrou o envio.'],
]);

it('o nome que vai para o registro perde caminho e caractere de controle', function () {
    expect(Upload::displayName("C:\\Users\\x\\rel\x01at\x7f\nório.csv"))->toBe('relatório.csv')
        ->and(Upload::displayName('../../etc/passwd'))->toBe('passwd')
        ->and(strlen(Upload::displayName(str_repeat('a', 400))))->toBe(255);
});

it('o registro leva nome, tamanho e destino, e nunca o conteúdo', function () {
    expect(Upload::record('notas.txt', 1234567, '~/notas.txt'))
        ->toBe('cp -v -- "$PHPORTO_SRC" "$PHPORTO_DST"' . "\n"
            . '  arquivo enviado: notas.txt (1.234.567 bytes)' . "\n"
            . '  destino (PHPORTO_DST): ~/notas.txt');
});

// ---- Cópia temporária para o WSL ----------------------------------------------

it('a cópia vai pela pasta de trabalho, com o caminho visto do WSL, e a temporária some no fim', function () {
    $dir = uploadDir();
    $files = $dir . DIRECTORY_SEPARATOR . 'files';
    $upload = uploadFake($files);
    $arquivo = $upload->accept(uploadArquivo($dir, 'conteudo'), 1024);

    $visto = [];
    $run = $upload->copy($arquivo, '~/notas.txt', function (string $script, array $env) use (&$visto, $files): RunResult {
        $temp = (string) array_values(glob($files . DIRECTORY_SEPARATOR . 'anexo-*.tmp') ?: [''])[0];
        $visto = ['script' => $script, 'env' => $env, 'temp' => $temp, 'conteudo' => (string) @file_get_contents($temp)];

        return new RunResult("'x' -> 'y'\n", 0, false, 10);
    });

    expect($run->exitCode)->toBe(0)
        ->and($visto['script'])->toBe(ScriptBuilder::upload())
        ->and($visto['env'])->toBe(['PHPORTO_SRC' => Runner::toWslPath($visto['temp']), 'PHPORTO_DST' => '~/notas.txt'])
        ->and($visto['conteudo'])->toBe('conteudo')
        ->and(glob($files . DIRECTORY_SEPARATOR . 'anexo-*') ?: [])->toBe([])
        ->and(is_file($arquivo['tmp']))->toBeFalse();

    uploadLimpa($dir);
});

it('a temporária some também quando o WSL falha', function () {
    $dir = uploadDir();
    $files = $dir . DIRECTORY_SEPARATOR . 'files';
    $upload = uploadFake($files);
    $arquivo = $upload->accept(uploadArquivo($dir, 'conteudo'), 1024);

    expect(fn () => $upload->copy($arquivo, '~/x', static function (): RunResult {
        throw new RuntimeException('wsl caiu');
    }))->toThrow(RuntimeException::class, 'wsl caiu')
        ->and(glob($files . DIRECTORY_SEPARATOR . 'anexo-*') ?: [])->toBe([]);

    $existe = $upload->accept(uploadArquivo($dir, 'conteudo'), 1024);
    $run = $upload->copy($existe, '~/x', static fn (): RunResult => new RunResult("[phporto] o destino ja existe\n", Upload::EXIT_DESTINATION_EXISTS, false, 5));

    expect($run->exitCode)->toBe(Upload::EXIT_DESTINATION_EXISTS)
        ->and(glob($files . DIRECTORY_SEPARATOR . 'anexo-*') ?: [])->toBe([]);

    uploadLimpa($dir);
});

// ---- O script do cp, num bash de verdade --------------------------------------

function uploadBash(string $root, string $src, string $dst): array
{
    $script = $root . DIRECTORY_SEPARATOR . 'cmd.sh';
    file_put_contents($script, ScriptBuilder::upload());

    $env = ['PHPORTO_WSL_ROOT' => $root, 'PHPORTO_SRC' => $src, 'PHPORTO_DST' => $dst, 'HOME' => $root . '/home', 'PATH' => (string) getenv('PATH')];
    $pipes = [];
    $proc = proc_open(['bash', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
    $out = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($proc), $out];
}

it('o script copia para destino novo, e recusa destino que já existe sem tocar nele', function () {
    $root = uploadDir();
    mkdir($root . '/home');
    mkdir($root . '/pasta');
    file_put_contents($root . '/origem.tmp', 'novo');
    file_put_contents($root . '/home/velho.txt', 'velho');

    [$ok, $saida] = uploadBash($root, $root . '/origem.tmp', '~/novo.txt');
    expect($ok)->toBe(0)
        ->and(file_get_contents($root . '/home/novo.txt'))->toBe('novo')
        ->and($saida)->toContain('novo.txt');

    [$relativo] = uploadBash($root, $root . '/origem.tmp', 'pasta/rel.txt');
    expect($relativo)->toBe(0)
        ->and(file_get_contents($root . '/pasta/rel.txt'))->toBe('novo');

    [$existe, $aviso] = uploadBash($root, $root . '/origem.tmp', '~/velho.txt');
    expect($existe)->toBe(Upload::EXIT_DESTINATION_EXISTS)
        ->and($aviso)->toContain('o destino ja existe, nada foi copiado')
        ->and(file_get_contents($root . '/home/velho.txt'))->toBe('velho');

    [$pasta] = uploadBash($root, $root . '/origem.tmp', 'pasta');
    expect($pasta)->toBe(Upload::EXIT_DESTINATION_EXISTS)
        ->and(is_file($root . '/pasta/origem.tmp'))->toBeFalse();

    symlink($root . '/nao-existe', $root . '/link-quebrado');
    [$link] = uploadBash($root, $root . '/origem.tmp', 'link-quebrado');
    expect($link)->toBe(Upload::EXIT_DESTINATION_EXISTS);

    uploadLimpa($root);
})->skip(PHP_OS_FAMILY === 'Windows' || trim((string) shell_exec('command -v bash')) === '', 'precisa de um bash local; no Windows o script roda dentro do WSL');
