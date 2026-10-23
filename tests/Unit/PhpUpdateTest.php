<?php

declare(strict_types=1);

use App\Http\Pages;
use App\Http\PhpView;
use App\Http\Respond;
use App\Php\PhpBuild;
use App\Php\PhpRelease;
use App\Php\PhpReleases;
use App\Php\PhpUpdate;
use App\Win\PsResult;
use App\Win\WinAction;

const ATUALIZAR_HASH = 'ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12';

function atualizarBuild(string $versao = '8.5.11', bool $ts = false, ?string $arch = 'x64', string $binario = 'C:\\php\\php.exe'): PhpBuild
{
    return new PhpBuild(
        version: $versao,
        threadSafe: $ts,
        binary: $binario,
        ini: 'C:\\php\\php.ini',
        sapi: 'cli-server',
        compiler: 'Visual C++ 2022',
        arch: $arch,
    );
}

/**
 * @param array<string, mixed> $troca
 */
function atualizarLista(string $versao = '8.5.13', array $troca = []): string
{
    $linha = array_replace([
        'version'      => $versao,
        'ts-vs17-x64'  => ['zip' => ['path' => "php-$versao-Win32-vs17-x64.zip", 'sha256' => str_repeat('ef', 32)]],
        'nts-vs17-x86' => ['zip' => ['path' => "php-$versao-nts-Win32-vs17-x86.zip", 'sha256' => str_repeat('12', 32)]],
        'nts-vs16-x64' => ['zip' => ['path' => "php-$versao-nts-Win32-vs16-x64.zip", 'sha256' => str_repeat('34', 32)]],
        'nts-vs17-x64' => ['zip' => ['path' => "php-$versao-nts-Win32-vs17-x64.zip", 'sha256' => strtoupper(ATUALIZAR_HASH), 'size' => '33.4MB'], 'mtime' => '2026-10-21T13:20:11+00:00'],
        'source'       => ['path' => "php-$versao-src.zip", 'sha256' => str_repeat('56', 32)],
    ], $troca);

    return (string) json_encode([
        '8.4' => ['version' => '8.4.20', 'nts-vs17-x64' => ['zip' => ['path' => 'php-8.4.20-nts-Win32-vs17-x64.zip', 'sha256' => str_repeat('cd', 32)]]],
        '8.5' => $linha,
    ]);
}

/**
 * @param Closure(string): ?string|null $disco
 */
function atualizarPlano(?PhpBuild $ativo, bool $windows = true, ?string $lista = null, ?Closure $disco = null): PhpUpdate
{
    return PhpReleases::plan(
        $ativo,
        $windows,
        static fn (): ?string => $lista ?? atualizarLista(),
        $disco ?? static fn (string $b): ?string => null,
    );
}

function atualizarPasta(): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phporto-rel-' . bin2hex(random_bytes(4));
    mkdir($dir);

    return $dir;
}

it('a linha é o x.y da versão, e só de versão de release', function (string $versao, ?string $linha) {
    expect(PhpReleases::minor($versao))->toBe($linha);
})->with([
    ['8.5.12', '8.5'], ['10.0.1', '10.0'], ['8.5.0', '8.5'],
    ['8.5', null], ['8.5.12-dev', null], ['8.5.12RC1', null], [' 8.5.12', null], ["8.5.12\n", null],
]);

it('só é mais nova a versão maior da MESMA linha', function (string $atual, string $oficial, bool $esperado) {
    expect(PhpReleases::newer($atual, new PhpRelease($oficial, ATUALIZAR_HASH, "php-$oficial-nts-Win32-vs17-x64.zip")))->toBe($esperado);
})->with([
    ['8.5.11', '8.5.13', true],
    ['8.5.9', '8.5.10', true],
    ['8.5.13', '8.5.13', false],
    ['8.5.14', '8.5.13', false],
    ['8.4.20', '8.5.13', false],
    ['8.5.13', '8.6.0', false],
    ['8.5.13', '9.5.14', false],
    ['8.5.13-dev', '8.5.14', false],
]);

it('lê versão, zip NTS x64 e SHA256 da linha da lista oficial', function () {
    $r = PhpReleases::parse(atualizarLista(), '8.5');

    expect($r)->not->toBeNull()
        ->and($r?->version)->toBe('8.5.13')
        ->and($r?->sha256)->toBe(ATUALIZAR_HASH)
        ->and($r?->file)->toBe('php-8.5.13-nts-Win32-vs17-x64.zip')
        ->and($r?->url())->toBe('https://downloads.php.net/~windows/releases/php-8.5.13-nts-Win32-vs17-x64.zip');
});

it('sem o vs17, fica com o outro NTS x64 da linha, e nunca com TS ou x86', function () {
    $sem17 = json_decode(atualizarLista(), true);
    unset($sem17['8.5']['nts-vs17-x64']);

    expect(PhpReleases::parse((string) json_encode($sem17), '8.5')?->file)->toBe('php-8.5.13-nts-Win32-vs16-x64.zip');

    unset($sem17['8.5']['nts-vs16-x64']);

    expect(PhpReleases::parse((string) json_encode($sem17), '8.5'))->toBeNull();
});

it('recusa a lista que não fecha', function (string $json) {
    expect(PhpReleases::parse($json, '8.5'))->toBeNull();
})->with([
    'json quebrado'           => ['{'],
    'sem a linha'             => [(string) json_encode(['8.4' => ['version' => '8.4.20']])],
    'versão de outra linha'   => [atualizarLista('8.6.0')],
    'versão que não é x.y.z'  => [atualizarLista('8.5.13RC1')],
    'zip com outro nome'      => [atualizarLista(troca: ['nts-vs17-x64' => ['zip' => ['path' => 'php-8.5.12-nts-Win32-vs17-x64.zip', 'sha256' => ATUALIZAR_HASH]], 'nts-vs16-x64' => null])],
    'zip em outra pasta'      => [atualizarLista(troca: ['nts-vs17-x64' => ['zip' => ['path' => '../php-8.5.13-nts-Win32-vs17-x64.zip', 'sha256' => ATUALIZAR_HASH]], 'nts-vs16-x64' => null])],
    'hash curto'              => [atualizarLista(troca: ['nts-vs17-x64' => ['zip' => ['path' => 'php-8.5.13-nts-Win32-vs17-x64.zip', 'sha256' => 'abc']], 'nts-vs16-x64' => null])],
    'hash que não é hex'      => [atualizarLista(troca: ['nts-vs17-x64' => ['zip' => ['path' => 'php-8.5.13-nts-Win32-vs17-x64.zip', 'sha256' => str_repeat('zz', 32)]], 'nts-vs16-x64' => null])],
    'hash que não é texto'    => [atualizarLista(troca: ['nts-vs17-x64' => ['zip' => ['path' => 'php-8.5.13-nts-Win32-vs17-x64.zip', 'sha256' => 123]], 'nts-vs16-x64' => null])],
]);

it('o SHA256 da lista sai em minúsculas', function () {
    expect(PhpReleases::parse(atualizarLista(), '8.5')?->sha256)->toBe(strtolower(ATUALIZAR_HASH));
});

it('só aceita https no host oficial, sem porta, usuário, query ou fragmento', function (string $url, bool $esperado) {
    expect(PhpReleases::isAllowedUrl($url))->toBe($esperado);
})->with([
    ['https://downloads.php.net/~windows/releases/releases.json', true],
    ['https://downloads.php.net/~windows/releases/php-8.5.13-nts-Win32-vs17-x64.zip', true],
    ['http://downloads.php.net/~windows/releases/releases.json', false],
    ['https://windows.php.net/downloads/releases/releases.json', false],
    ['https://downloads.php.net.exemplo.test/~windows/releases/releases.json', false],
    ['https://downloads.php.net:443/~windows/releases/releases.json', false],
    ['https://u:p@downloads.php.net/~windows/releases/releases.json', false],
    ['https://downloads.php.net/~windows/releases/releases.json?a=1', false],
    ['https://downloads.php.net/~windows/releases/releases.json#a', false],
    ['https://downloads.php.net/~windows/releases/archives/php-8.5.11-nts-Win32-vs17-x64.zip', false],
    ['https://downloads.php.net/~windows/releases/php-8.5.13-Win32-vs17-x64.zip', false],
    ['https://downloads.php.net/~windows/releases/php-8.5.13-nts-Win32-vs17-x86.zip', false],
    ['https://downloads.php.net/~windows/releases/../releases.json', false],
    ['https://downloads.php.net/~windows/releases/releases.json ', false],
    ["https://downloads.php.net/~windows/releases/releases.json\n", false],
    ['https://downloads.php.net\\@exemplo.test/~windows/releases/releases.json', false],
    ['//downloads.php.net/~windows/releases/releases.json', false],
    ['', false],
]);

it('não busca URL fora da lista, nem com http', function () {
    expect(PhpReleases::fetch('http://downloads.php.net/~windows/releases/releases.json'))->toBeNull()
        ->and(PhpReleases::fetch('https://windows.php.net/downloads/releases/releases.json'))->toBeNull();
});

it('a busca não segue redirecionamento e só aceita 200', function () {
    $fonte = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Php/PhpReleases.php');

    expect($fonte)->toContain("'follow_location' => 0")
        ->and($fonte)->toContain("'verify_peer'      => true")
        ->and($fonte)->toContain('HTTP/\S+ 200');
});

it('a pasta do PHP é a do binário, e só pasta local aceita', function (string $binario, ?string $pasta) {
    expect(PhpReleases::dirOf($binario))->toBe($pasta);
})->with([
    ['C:\\php\\php.exe', 'C:\\php'],
    ['D:\\Ferramentas\\php 8.5\\php.exe', 'D:\\Ferramentas\\php 8.5'],
    ['C:\\php.exe', null],
    ['/usr/bin/php', null],
    ['\\\\servidor\\php\\php.exe', null],
    ['C:\\..\\php\\php.exe', null],
    ['C:\\php/x\\php.exe', null],
    ['C:\\' . str_repeat('a', 200) . '\\php.exe', null],
]);

it('o plano oferece o patch novo da mesma linha, com a pasta do binário', function () {
    $plano = atualizarPlano(atualizarBuild());

    expect($plano->available())->toBeTrue()
        ->and($plano->release?->version)->toBe('8.5.13')
        ->and($plano->dir)->toBe('C:\\php')
        ->and($plano->status)->toBe('Há o 8.5.13 na linha 8.5. Esta tela não troca de linha: só o patch.');
});

it('o plano não oferece nada, e diz por quê', function (?PhpBuild $ativo, bool $windows, ?string $lista, string $texto) {
    $buscas = 0;
    $plano  = PhpReleases::plan($ativo, $windows, function () use ($lista, &$buscas): ?string {
        $buscas++;

        return $lista;
    }, static fn (string $b): ?string => null);

    expect($plano->available())->toBeFalse()
        ->and($plano->release)->toBeNull()
        ->and($plano->status)->toContain($texto);
})->with([
    'sem medida'          => [null, true, '{}', 'Sem a medida do PHP'],
    'fora do Windows'     => [fn () => atualizarBuild(), false, '{}', 'só existe no Windows'],
    'TS'                  => [fn () => atualizarBuild(ts: true), true, '{}', 'Só um PHP NTS x64'],
    'x86'                 => [fn () => atualizarBuild(arch: 'x86'), true, '{}', 'Só um PHP NTS x64'],
    'versão de dev'       => [fn () => atualizarBuild('8.5.13-dev'), true, '{}', 'não é um patch de release'],
    'pasta recusada'      => [fn () => atualizarBuild(binario: '\\\\srv\\php\\php.exe'), true, '{}', 'não tem um caminho que a atualização aceite'],
    'lista fora do ar'    => [fn () => atualizarBuild(), true, null, 'Não deu para consultar a lista oficial'],
    'linha sem zip'       => [fn () => atualizarBuild('8.3.9'), true, atualizarLista(), 'não traz um zip NTS x64 da linha 8.3'],
    'em dia'              => [fn () => atualizarBuild('8.5.13'), true, atualizarLista(), 'Em dia: 8.5.13 é o último patch da linha 8.5.'],
    'mais nova que a lista' => [fn () => atualizarBuild('8.5.14'), true, atualizarLista(), 'Em dia'],
]);

it('o plano só consulta a lista quando o PHP em uso pode ser atualizado', function () {
    $buscas = 0;
    $contar = function () use (&$buscas): ?string {
        $buscas++;

        return atualizarLista();
    };

    PhpReleases::plan(atualizarBuild(), false, $contar, static fn (string $b): ?string => null);
    PhpReleases::plan(atualizarBuild(ts: true), true, $contar, static fn (string $b): ?string => null);

    expect($buscas)->toBe(0);

    PhpReleases::plan(atualizarBuild(), true, $contar, static fn (string $b): ?string => null);

    expect($buscas)->toBe(1);
});

it('com o php.exe da pasta já trocado, pede para subir o php -S de novo em vez do botão', function () {
    $medidos = [];
    $plano   = atualizarPlano(atualizarBuild(), disco: function (string $b) use (&$medidos): ?string {
        $medidos[] = $b;

        return '8.5.13';
    });

    expect($plano->available())->toBeFalse()
        ->and($medidos)->toBe(['C:\\php\\php.exe'])
        ->and($plano->status)->toBe('O php.exe da pasta já é o 8.5.13. Feche o php -S e suba de novo para usar a versão nova.');
});

it('o cache guarda a lista por uma hora e a falha por cinco minutos', function () {
    $dir     = atualizarPasta();
    $arquivo = $dir . '/sub/php-releases.json';
    $buscas  = 0;
    $volta   = 'lista';
    $buscar  = function () use (&$buscas, &$volta): ?string {
        $buscas++;

        return $volta;
    };

    try {
        expect(PhpReleases::cached($arquivo, 1000, $buscar))->toBe('lista')
            ->and(PhpReleases::cached($arquivo, 1000 + 3599, $buscar))->toBe('lista')
            ->and($buscas)->toBe(1);

        $volta = null;

        expect(PhpReleases::cached($arquivo, 1000 + 3600, $buscar))->toBeNull()
            ->and($buscas)->toBe(2)
            ->and(PhpReleases::cached($arquivo, 1000 + 3600 + 299, $buscar))->toBeNull()
            ->and($buscas)->toBe(2);

        $volta = 'nova';

        expect(PhpReleases::cached($arquivo, 1000 + 3600 + 300, $buscar))->toBe('nova')
            ->and($buscas)->toBe(3);

        file_put_contents($arquivo, 'lixo');

        expect(PhpReleases::cached($arquivo, 5000, $buscar))->toBe('nova')
            ->and($buscas)->toBe(4);
    } finally {
        @unlink($arquivo);
        @rmdir($dir . '/sub');
        @rmdir($dir);
    }
});

it('a ação php-update valida versão, hash e pasta, e devolve o hash em minúsculas', function () {
    expect(WinAction::PhpUpdate->validate(['Version' => '8.5.13', 'Sha256' => strtoupper(ATUALIZAR_HASH), 'PhpDir' => 'C:\\php']))
        ->toBe(['Version' => '8.5.13', 'Sha256' => ATUALIZAR_HASH, 'PhpDir' => 'C:\\php']);
});

it('a ação php-update recusa o que não é do formato', function (array $entrada, string $mensagem) {
    expect(fn () => WinAction::PhpUpdate->validate($entrada))->toThrow(InvalidArgumentException::class, $mensagem);
})->with([
    'vazio'             => [[], 'Preencha Version, Sha256 e PhpDir.'],
    'sem pasta'         => [['Version' => '8.5.13', 'Sha256' => ATUALIZAR_HASH], 'Preencha Version, Sha256 e PhpDir.'],
    'versão curta'      => [['Version' => '8.5', 'Sha256' => ATUALIZAR_HASH, 'PhpDir' => 'C:\\php'], 'Version não é uma versão x.y.z.'],
    'versão com sufixo' => [['Version' => '8.5.13-dev', 'Sha256' => ATUALIZAR_HASH, 'PhpDir' => 'C:\\php'], 'Version não é uma versão x.y.z.'],
    'hash curto'        => [['Version' => '8.5.13', 'Sha256' => 'abc', 'PhpDir' => 'C:\\php'], 'Sha256 não é um SHA256 em hexadecimal.'],
    'raiz do disco'     => [['Version' => '8.5.13', 'Sha256' => ATUALIZAR_HASH, 'PhpDir' => 'C:\\'], 'PhpDir não é uma pasta local do Windows aceita.'],
    'barra no fim'      => [['Version' => '8.5.13', 'Sha256' => ATUALIZAR_HASH, 'PhpDir' => 'C:\\php\\'], 'PhpDir não é uma pasta local do Windows aceita.'],
    'subida'            => [['Version' => '8.5.13', 'Sha256' => ATUALIZAR_HASH, 'PhpDir' => 'C:\\..\\x'], 'PhpDir não é uma pasta local do Windows aceita.'],
    'barra normal'      => [['Version' => '8.5.13', 'Sha256' => ATUALIZAR_HASH, 'PhpDir' => 'C:/php'], 'PhpDir não é uma pasta local do Windows aceita.'],
    'rede'              => [['Version' => '8.5.13', 'Sha256' => ATUALIZAR_HASH, 'PhpDir' => '\\\\srv\\php'], 'PhpDir não é uma pasta local do Windows aceita.'],
    'longa'             => [['Version' => '8.5.13', 'Sha256' => ATUALIZAR_HASH, 'PhpDir' => 'C:\\' . str_repeat('a', 200)], 'PhpDir não é uma pasta local do Windows aceita.'],
]);

it('a ação php-update é sensível: só roda com UAC próprio', function () {
    expect(WinAction::PhpUpdate->isSensitive(['Version' => '8.5.13', 'Sha256' => ATUALIZAR_HASH, 'PhpDir' => 'C:\\php']))->toBeTrue();
});

it('o worker usa os mesmos formatos da ação php-update', function () {
    $worker = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Win/worker.ps1');

    expect(preg_match("/^    'php-update'\s*=\s*@\{\r?\n(.*?)\r?\n    \}/ms", $worker, $bloco))->toBe(1);
    expect(preg_match_all("/'(\w+)'\s*=\s*@\{ tipo = 'text'; max = (\d+);(?: min = (\d+);)? padrao = '([^']+)' \}/", $bloco[1], $regras, PREG_SET_ORDER))->toBe(3);

    $porCampo = [];

    foreach ($regras as $r) {
        $porCampo[$r[1]] = ['max' => (int) $r[2], 'padrao' => $r[4]];
    }

    expect(array_keys($porCampo))->toBe(WinAction::PHP_UPDATE_FIELDS)
        ->and($porCampo['Version']['padrao'])->toBe(WinAction::PHP_UPDATE_VERSION_PATTERN)
        ->and($porCampo['Sha256']['padrao'])->toBe(WinAction::PHP_UPDATE_SHA256_PATTERN)
        ->and($porCampo['PhpDir']['padrao'])->toBe(WinAction::PHP_UPDATE_DIR_PATTERN)
        ->and($porCampo['PhpDir']['max'])->toBe(WinAction::PHP_UPDATE_DIR_MAX);
});

it('o POST monta os parâmetros do plano, e não do navegador', function () {
    expect(Pages::phpUpdateParams(atualizarPlano(atualizarBuild()), '8.5.13'))
        ->toBe(['Version' => '8.5.13', 'Sha256' => ATUALIZAR_HASH, 'PhpDir' => 'C:\\php']);
});

it('o POST recusa versão que não é a do plano, e plano sem atualização', function () {
    expect(fn () => Pages::phpUpdateParams(atualizarPlano(atualizarBuild()), '8.5.14'))
        ->toThrow(InvalidArgumentException::class, 'A lista oficial mudou desde que a tela abriu: agora o patch é o 8.5.13. Releia a página. Nada foi feito.')
        ->and(fn () => Pages::phpUpdateParams(atualizarPlano(atualizarBuild()), ['8.5.13']))
        ->toThrow(InvalidArgumentException::class, 'A lista oficial mudou')
        ->and(fn () => Pages::phpUpdateParams(atualizarPlano(atualizarBuild('8.5.13')), '8.5.13'))
        ->toThrow(InvalidArgumentException::class, 'Em dia: 8.5.13 é o último patch da linha 8.5. Nada foi feito.');
});

it('o aviso do POST traz o backup no sucesso e o último erro na falha', function () {
    $params = ['Version' => '8.5.13', 'Sha256' => ATUALIZAR_HASH, 'PhpDir' => 'C:\\php'];

    $ok = new PsResult("[ INFO ] Downloading x\n[ OK ] SHA256 checked: x\n[ OK ] Backup: C:\\php-8.5.11\n[ OK ] PHP 8.5.11 -> 8.5.13 in C:\\php.\n", 0, false, 10);
    expect(Pages::phpUpdateNotice($params, $ok))
        ->toBe('PHP atualizado para 8.5.13 na mesma pasta. A versão anterior ficou em C:\\php-8.5.11. Feche o php -S e suba de novo para usar a versão nova.');

    $ja = new PsResult("[ OK ] php.exe in C:\\php is already 8.5.13. Nothing was changed.\n", 0, false, 10);
    expect(Pages::phpUpdateNotice($params, $ja))->toContain('já é o 8.5.13. Nada mudou.');

    $falha = new PsResult("[ INFO ] Downloading x\n[ ERROR ] SHA256 mismatch: expected a, got b. The zip was discarded. Nothing was changed.\n", 1, false, 10);
    expect(Pages::phpUpdateNotice($params, $falha))
        ->toBe('A atualização do PHP para 8.5.13 não foi feita. SHA256 mismatch: expected a, got b. The zip was discarded. Nothing was changed. Detalhes no histórico do Windows.');

    $prazo = new PsResult('', null, true, 600000);
    expect(Pages::phpUpdateNotice($params, $prazo))->toContain('passou do prazo');
});

it('o POST da /php separa a atualização da gravação do php.ini, depois do CSRF', function () {
    $fonte = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Http/Pages.php');
    $ini   = strpos($fonte, 'private function handlePhpPost(');
    $fim   = strpos($fonte, 'private function handlePhpUpdate(');

    expect($ini)->toBeInt()->and($fim)->toBeInt();

    $corpo = substr($fonte, (int) $ini, (int) $fim - (int) $ini);
    $csrf  = strpos($corpo, 'Csrf::');
    $campo = strpos($corpo, 'PhpReleases::POST_FIELD');
    $grava = strpos($corpo, 'phpIniSave(');

    expect($csrf)->toBeInt()
        ->and($campo)->toBeInt()
        ->and($grava)->toBeInt()
        ->and($csrf < $campo && $campo < $grava)->toBeTrue();

    $atualiza = substr($fonte, (int) $fim, 2000);

    expect($atualiza)->toContain('$this->oneShot->blockingReason()')
        ->and($atualiza)->toContain('$this->oneShot->dispatch(WinAction::PhpUpdate');
});

it('com patch novo, a tela mostra o botão, o modal do UAC e o nome do backup', function () {
    $view = PhpView::make(atualizarBuild(), 'C:\\php\\php.exe', true, static fn (string $b): ?PhpBuild => null)
        ->with(null, null, 'tok', '_csrf', atualizarPlano(atualizarBuild()));
    $html = Respond::render('php.php', $view);

    expect($html)->toContain('id="php-atualizar"')
        ->and($html)->toContain('Há o 8.5.13 na linha 8.5.')
        ->and($html)->toContain('id="btn-atualizar-php"')
        ->and($html)->toContain('id="modal-atualizar"')
        ->and($html)->toMatch('/<button type="submit"[^>]*id="btn-confirmar-atualizar" data-uac>/')
        ->and($html)->toContain('name="' . PhpReleases::POST_FIELD . '" value="8.5.13"')
        ->and($html)->toContain('name="_csrf" value="tok"')
        ->and($html)->toContain('https://downloads.php.net/~windows/releases/php-8.5.13-nts-Win32-vs17-x64.zip')
        ->and($html)->toContain(ATUALIZAR_HASH)
        ->and($html)->toContain('<code>C:\\php-8.5.11</code>')
        ->and($html)->toContain('Abre o UAC')
        ->and($html)->toContain('feche o <code>php -S</code> e suba de novo')
        ->and($html)->not->toContain('<script src=')
        ->and($html)->not->toContain('<link rel="stylesheet"');
});

it('sem patch novo, a tela só diz o porquê, sem botão', function () {
    $view = PhpView::make(atualizarBuild('8.5.13'), 'C:\\php\\php.exe', true, static fn (string $b): ?PhpBuild => null)
        ->with(null, null, 'tok', '_csrf', atualizarPlano(atualizarBuild('8.5.13')));
    $html = Respond::render('php.php', $view);

    expect($html)->toContain('id="php-atualizar"')
        ->and($html)->toContain('Em dia: 8.5.13 é o último patch da linha 8.5.')
        ->and($html)->not->toContain('id="btn-atualizar-php"')
        ->and($html)->not->toContain('id="modal-atualizar"');
});

it('sem plano, a seção nem aparece', function () {
    $view = PhpView::make(atualizarBuild(), 'C:\\php\\php.exe', true, static fn (string $b): ?PhpBuild => null)
        ->with(null, null, 'tok', '_csrf');

    expect(Respond::render('php.php', $view))->not->toContain('id="php-atualizar"');
});

it('o texto do plano é escapado', function () {
    $view = PhpView::make(atualizarBuild(), 'C:\\php\\php.exe', true, static fn (string $b): ?PhpBuild => null)
        ->with(null, null, 'tok', '_csrf', new PhpUpdate('<b>x</b>'));

    expect(Respond::render('php.php', $view))->toContain('&lt;b&gt;x&lt;/b&gt;');
});
