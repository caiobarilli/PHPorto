<?php

declare(strict_types=1);

use App\Http\Api;
use App\Http\Auth;
use App\Http\AuthOutcome;
use App\Http\Front;
use App\Http\Login;
use App\Http\Pages\ConfigPage;
use App\Http\Pages\HomePage;
use App\Http\Pages\HypervPage;
use App\Http\Pages\OllamaPage;
use App\Http\Pages\PhpPage;
use App\Http\Pages\WinPage;
use App\Http\Pages\WslPage;
use App\Http\Respond;
use App\Network\Store as NetworkStore;
use App\Php\C\CProcess;
use App\Services\ExecutionLogService;
use App\Win\Elevation;
use App\Win\HypervGate;
use App\Win\OneShot;
use App\Win\PsTerminalProcess;
use App\Win\UacPolicy;
use App\Wsl\Distro;

/**
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
$filesDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'files';
$network = (new NetworkStore($filesDir))->load();

date_default_timezone_set($config['tz']);

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

$recusa = Front::refusal($_SERVER, $path, $config['api_enabled'], $network);

if ($recusa !== null) {
    Front::refuse($recusa);
}

$auth = new Auth(
    $config['auth_token'],
    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . Auth::COUNTER_FILE,
);

if (str_starts_with($path, '/api/')) {
    $senha   = $_SERVER['PHP_AUTH_PW'] ?? null;
    $decisao = $auth->check(is_string($senha) ? $senha : null);

    if ($decisao->outcome !== AuthOutcome::Allowed) {
        Respond::authRefused($decisao);
    }
} else {
    Login::guard($auth, $method);
}

$distro      = new Distro($config['wsl']['distro']);
$makeService = Closure::fromCallable($app['makeService']);

if (str_starts_with($path, '/api/')) {
    if (!$config['api_enabled']) {
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

if (!$config['dashboard_enabled']) {
    Respond::notFound();
}

$winDir    = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Win';
$elevation = new Elevation(
    filesDir: $filesDir,
    storageDir: dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage',
    winDir: $winDir,
);

$uac = new UacPolicy();

$deps = [
    'config'        => $config,
    'distroChecker' => $distro,
    'makeService'   => $makeService,
    'filesDir'      => $filesDir,
    'elevation'     => $elevation,
    'hyperv'        => new HypervGate(
        storageDir: dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage',
        filesDir: $filesDir,
    ),
    'oneShot'       => new OneShot($filesDir, $winDir, $elevation, $uac),
    'uac'           => $uac,
    'terminal'      => new PsTerminalProcess($filesDir),
    'c'             => new CProcess($filesDir),
    'storageDir'    => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage',
];

match ($path) {
    '/'       => (new HomePage(...$deps))->handle(),
    '/config' => (new ConfigPage(...$deps))->handle($method),
    '/wsl'    => (new WslPage(...$deps))->handle($method),
    '/win'    => (new WinPage(...$deps))->handle($method),
    '/hyperv' => (new HypervPage(...$deps))->handle($method),
    '/php'    => (new PhpPage(...$deps))->handle($method),
    '/ollama' => (new OllamaPage(...$deps))->handle($method),
    default   => Respond::notFound(),
};
