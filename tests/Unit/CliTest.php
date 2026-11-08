<?php

declare(strict_types=1);

use App\Cli\Console;
use App\Cli\Main;
use App\Cli\Serve;
use App\Cli\Status;

function cliRoot(?string $env): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phporto-cli-' . bin2hex(random_bytes(5));
    mkdir($dir);

    if ($env !== null) {
        file_put_contents($dir . DIRECTORY_SEPARATOR . '.env', $env);
    }

    return $dir;
}

function cliClean(string $dir): void
{
    foreach (scandir($dir) ?: [] as $nome) {
        if ($nome !== '.' && $nome !== '..' && is_file($dir . DIRECTORY_SEPARATOR . $nome)) {
            unlink($dir . DIRECTORY_SEPARATOR . $nome);
        }
    }

    rmdir($dir);
}

/**
 * @param list<string> $args
 * @return array{0: int, 1: string, 2: string}
 */
function cliRun(string $root, array $args, string $input = '', ?Closure $runner = null): array
{
    $in = fopen('php://memory', 'w+');
    $out = fopen('php://memory', 'w+');
    $err = fopen('php://memory', 'w+');
    fwrite($in, $input);
    rewind($in);
    $console = new Console($in, $out, $err);
    $serve = $runner === null ? null : static fn (): Serve => new Serve($root, $console, $runner);
    $code = (new Main($root, $console, $serve))->run($args);
    rewind($out);
    rewind($err);

    return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
}

it('sem argumento e com help mostra a ajuda e sai 0', function (array $args) {
    [$code, $out, $err] = cliRun(sys_get_temp_dir(), $args);

    expect($code)->toBe(Status::OK)
        ->and($out)->toContain('php phporto <comando>')->toContain('serve')->toContain('token')
        ->and($err)->toBe('');
})->with([[[]], [['help']], [['--help']]]);

it('cada comando tem a própria ajuda', function (array $args, string $trecho) {
    [$code, $out] = cliRun(sys_get_temp_dir(), $args);

    expect($code)->toBe(Status::OK)->and($out)->toContain($trecho);
})->with([
    [['serve', '--help'], 'php phporto serve'],
    [['token', '--help'], 'php phporto token'],
    [['help', 'serve'], 'php phporto serve'],
]);

it('comando inválido sai 64 e mostra a ajuda no STDERR', function (array $args) {
    [$code, $out, $err] = cliRun(sys_get_temp_dir(), $args);

    expect($code)->toBe(Status::USAGE)
        ->and($out)->toBe('')
        ->and($err)->toContain('Comando desconhecido')->toContain('php phporto <comando>');
})->with([[['foo']], [['help', 'foo']], [["\x1b[31mx"]]]);

it('argumento sobrando em serve e token sai 64', function (array $args) {
    $root = cliRoot("PHPORTO_AUTH_TOKEN=\n");
    [$code] = cliRun($root, $args, '', static fn (array $c, string $d): int => 0);
    cliClean($root);

    expect($code)->toBe(Status::USAGE);
})->with([[['serve', 'x']], [['token', 'x']]]);

it('serve sem .env sai 78', function () {
    $root = cliRoot(null);
    $chamou = false;
    [$code, , $err] = cliRun($root, ['serve'], '', static function () use (&$chamou): int {
        $chamou = true;

        return 0;
    });
    cliClean($root);

    expect($code)->toBe(Status::CONFIG)->and($chamou)->toBeFalse()->and($err)->toContain('Não há .env');
});

it('serve com .env inválido ou porta inválida sai 78', function (string $env, string $trecho) {
    $root = cliRoot($env);
    $chamou = false;
    [$code, , $err] = cliRun($root, ['serve'], '', static function () use (&$chamou): int {
        $chamou = true;

        return 0;
    });
    cliClean($root);

    expect($code)->toBe(Status::CONFIG)->and($chamou)->toBeFalse()->and($err)->toContain($trecho);
})->with([
    'aspa aberta' => ["PHPORTO_AUTH_TOKEN=\"abc\n", 'inválido'],
    'porta texto' => ["PHPORTO_PORT=abc\n", 'PHPORTO_PORT'],
    'porta zero' => ["PHPORTO_PORT=0\n", 'PHPORTO_PORT'],
    'porta alta' => ["PHPORTO_PORT=65536\n", 'PHPORTO_PORT'],
]);

it('serve sobe só o loopback, na porta do .env, com caminhos absolutos da raiz', function () {
    $root = cliRoot("PHPORTO_AUTH_TOKEN=x\nPHPORTO_PORT=4555\n");
    $visto = null;
    [$code, $out] = cliRun($root, ['serve'], '', static function (array $comando, string $cwd) use (&$visto): int {
        $visto = [$comando, $cwd];

        return 0;
    });
    cliClean($root);

    $public = $root . DIRECTORY_SEPARATOR . 'public';

    expect($code)->toBe(Status::OK)
        ->and($visto)->toBe([[PHP_BINARY, '-S', '127.0.0.1:4555', '-t', $public, $public . DIRECTORY_SEPARATOR . 'router.php'], $root])
        ->and($out)->toContain('http://127.0.0.1:4555');
});

it('serve usa 4001 sem PHPORTO_PORT, avisa da falta do token e nunca usa 0.0.0.0', function () {
    $root = cliRoot("DB_PROVIDER=sqlite\n");
    $visto = [];
    [, , $err] = cliRun($root, ['serve'], '', static function (array $comando) use (&$visto): int {
        $visto = $comando;

        return 0;
    });
    cliClean($root);

    expect($visto[2])->toBe('127.0.0.1:4001')
        ->and(implode(' ', $visto))->not->toContain('0.0.0.0')
        ->and($err)->toContain('PHPORTO_AUTH_TOKEN');
});

it('serve devolve a saída do servidor', function () {
    $root = cliRoot("PHPORTO_AUTH_TOKEN=x\n");
    [$code] = cliRun($root, ['serve'], '', static fn (array $c, string $d): int => Status::FAILURE);
    cliClean($root);

    expect($code)->toBe(Status::FAILURE);
});

it('token sem .env sai 78', function () {
    $root = cliRoot(null);
    [$code, , $err] = cliRun($root, ['token']);
    cliClean($root);

    expect($code)->toBe(Status::CONFIG)->and($err)->toContain('Não há .env');
});

it('token grava 64 caracteres hexadecimais no .env e imprime o mesmo valor', function () {
    $root = cliRoot("PHPORTO_WSL_ROOT=/x\nPHPORTO_AUTH_TOKEN=\n");
    [$code, $out] = cliRun($root, ['token']);
    $env = (string) file_get_contents($root . DIRECTORY_SEPARATOR . '.env');
    cliClean($root);

    expect($code)->toBe(Status::OK)
        ->and(preg_match('/^PHPORTO_AUTH_TOKEN=([0-9a-f]{64})$/m', $env, $m))->toBe(1)
        ->and($env)->toContain('PHPORTO_WSL_ROOT=/x')
        ->and($out)->toContain($m[1])->toContain('127.0.0.1:4001');
});

it('token existente só troca com s ou sim, e a recusa sai 1 sem mudar o .env', function (string $resposta, bool $troca) {
    $antigo = str_repeat('a', 64);
    $root = cliRoot("PHPORTO_AUTH_TOKEN={$antigo}\n");
    [$code, $out] = cliRun($root, ['token'], $resposta);
    $env = (string) file_get_contents($root . DIRECTORY_SEPARATOR . '.env');
    cliClean($root);

    expect($out)->toContain('Substituir? [s/N]');

    if ($troca) {
        expect($code)->toBe(Status::OK)->and($env)->not->toContain($antigo);
    } else {
        expect($code)->toBe(Status::FAILURE)
            ->and($env)->toBe("PHPORTO_AUTH_TOKEN={$antigo}\n")
            ->and($out)->toContain('Nada mudou');
    }
})->with([
    's' => ["s\n", true],
    'sim' => ["SIM\n", true],
    'vazio' => ["\n", false],
    'n' => ["n\n", false],
    'fim da entrada' => ['', false],
]);

it('token.php é só um atalho para a mesma rotina', function () {
    $fonte = (string) file_get_contents(dirname(__DIR__, 2) . '/token.php');

    expect($fonte)->toContain("run(['token'])")->and(substr_count($fonte, "\n"))->toBeLessThan(20);
});

it('phporto fora do CLI responde 404 antes de carregar a aplicação', function () {
    $fonte = (string) file_get_contents(dirname(__DIR__, 2) . '/phporto');

    expect(strpos($fonte, "PHP_SAPI !== 'cli'"))->toBeLessThan((int) strpos($fonte, 'require $autoload'))
        ->and($fonte)->toContain('http_response_code(404)');
});
