<?php

declare(strict_types=1);

/**
 * PHPorto — front controller.
 *
 * Recebe um comando pelo navegador, executa dentro da distro do WSL, devolve a
 * saída e guarda o registro no banco.
 *
 * Sobe com:  php -S 127.0.0.1:4001 -t public public/router.php
 *
 * O -t public NÃO é detalhe da linha de comando: é a defesa principal. Este
 * diretório é o ÚNICO servido pela web. src/, storage/, files/ e o .env ficam
 * fora dele, então não existe URL que os alcance — o 404 vem de não haver o
 * que servir, não de uma regra ter lembrado de proibir.
 *
 * Antes desta separação, o diretório servido era a raiz do projeto, e a única
 * defesa era uma expressão regular acertar todos os casos: /src/Config/Config.php
 * não é dotfile e passaria por qualquer regra escrita para dotfiles. Defende-se
 * com geografia o que não se deve defender com regex.
 *
 * O router.php continua, como segunda camada e como despachante das rotas.
 * O motivo completo está no docblock dele.
 *
 * E só 127.0.0.1. Nunca 0.0.0.0, nunca o IP da rede, nunca atrás de proxy:
 * esta página executa comando arbitrário com os privilégios do usuário do WSL,
 * e no Windows como Administrador. O token não muda isso: o Basic manda o token
 * em base64, que é codificação e não cifra. Acesso remoto é pelo túnel SSH.
 *
 * O PORTÃO DE TOKEN vem antes de qualquer rota, e vale igual para as telas e
 * para /api/*. Sem PHPORTO_AUTH_TOKEN nada é servido (503). Sem credencial, ou
 * com a errada, a resposta é 401 — exceção deliberada à regra do 404: as
 * superfícies desligadas respondem 404 para não confirmar que existem, mas
 * aqui a pessoa precisa ser solicitada, e só o 401 com WWW-Authenticate abre
 * o diálogo do navegador. Um 403 não abre diálogo nenhum.
 */

use App\Http\Api;
use App\Http\Auth;
use App\Http\AuthOutcome;
use App\Http\Pages;
use App\Http\Respond;
use App\Services\ExecutionLogService;
use App\Win\Elevation;
use App\Wsl\Distro;

/**
 * A forma vem escrita por extenso porque um arquivo de script não tem onde
 * pendurar um @phpstan-import-type — o alias AppConfig vive no docblock da
 * classe Config. Se esta forma divergir de lá, o PHPStan acusa na primeira
 * passagem, que é o comportamento desejado.
 *
 * @var array{
 *     config: array{
 *         db_provider: string,
 *         cors_origin: string,
 *         mongo: array{uri: string, database: string, collection: string},
 *         mysql: array{host: string, port: string, database: string, user: string, password: string, table: string},
 *         sqlite: array{path: string, table: string},
 *         wsl: array{root: string, distro: string, timeout: int},
 *         winutil: array{timeout: int},
 *         tz: string,
 *         dashboard_enabled: bool,
 *         api_enabled: bool,
 *         auth_token: string
 *     },
 *     makeService: callable(): ExecutionLogService
 * } $app
 */
$app = require dirname(__DIR__) . '/src/bootstrap.php';

mb_internal_encoding('UTF-8');

$config = $app['config'];

date_default_timezone_set($config['tz']);

// --- Portão de token ---------------------------------------------------------
$senha    = $_SERVER['PHP_AUTH_PW'] ?? null;
$decisao  = (new Auth(
    $config['auth_token'],
    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . Auth::COUNTER_FILE,
))->check(is_string($senha) ? $senha : null);

if ($decisao->outcome !== AuthOutcome::Allowed) {
    Respond::authRefused($decisao);
}

// O router já resolveu e validou o caminho; o fallback existe para quem
// executar o index.php diretamente (outro servidor, ou teste).
$path = $_SERVER['PHPORTO_PATH'] ?? null;

if (!is_string($path) || $path === '') {
    $uri    = $_SERVER['REQUEST_URI'] ?? '/';
    $parsed = parse_url(is_string($uri) ? $uri : '/', PHP_URL_PATH);
    $path   = is_string($parsed) && $parsed !== '' ? $parsed : '/';

    if ($path !== '/' && str_ends_with($path, '/')) {
        $path = rtrim($path, '/');
    }
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$method = is_string($method) ? strtoupper($method) : 'GET';

$filesDir    = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'files';
$distro      = new Distro($config['wsl']['distro']);
$makeService = Closure::fromCallable($app['makeService']);

// --- API -------------------------------------------------------------------
if (str_starts_with($path, '/api/')) {
    if (!$config['api_enabled']) {
        // 404 e não 403: a flag desligada não deve revelar que existe algo ali.
        Respond::notFound();
    }

    $api = new Api(
        wsl: $config['wsl'],
        corsOrigin: $config['cors_origin'],
        distroChecker: $distro,
        makeService: $makeService,
        filesDir: $filesDir,
    );

    $api->handle($path, $method);
}

// --- Telas -----------------------------------------------------------------
if (!$config['dashboard_enabled']) {
    Respond::notFound();
}

// O marcador da elevação mora em storage/, ao lado do flags.json, e não em
// files/: files/ é área de trabalho descartável do que está executando, e o
// marcador precisa sobreviver a uma limpeza dela.
$pages = new Pages(
    config: $config,
    distroChecker: $distro,
    makeService: $makeService,
    filesDir: $filesDir,
    elevation: new Elevation(
        filesDir: $filesDir,
        storageDir: dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage',
        winDir: dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Win',
    ),
);

match ($path) {
    '/'       => $pages->home(),
    '/config' => $pages->config($method),
    '/wsl'    => $pages->wsl($method),
    '/win'    => $pages->win($method),
    default   => Respond::notFound(),
};
