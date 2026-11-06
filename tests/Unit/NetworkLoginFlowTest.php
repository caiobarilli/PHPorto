<?php

declare(strict_types=1);

use App\Domain\ExecutionKind;
use App\Http\Auth;
use App\Http\AuthOutcome;
use App\Http\LayoutView;
use App\Http\Respond;
use App\Network\LoginAudit;
use App\Network\LoginAttempts;
use App\Network\LoginFlow;
use App\Network\State;
use App\Services\ExecutionLogService;
use Tests\Fakes\FakeProvider;

require_once __DIR__ . '/NetworkLoginAttemptsTest.php';

it('aplica cinco falhas por IP no fluxo, bloqueia a sexta e aceita outro IP', function () {
    $dir = networkLoginDir();
    $provider = new FakeProvider();
    $attempts = new LoginAttempts($dir, static fn (): int => 1_000_000);
    $audit = new LoginAudit(loginNetworkFixture(), static fn (): ExecutionLogService => new ExecutionLogService($provider));
    $flow = new LoginFlow($attempts, loginNetworkFixture(), $audit);
    $auth = new Auth('token-correto', $dir . DIRECTORY_SEPARATOR . 'auth.json', static fn (): int => 1_000_000);

    foreach (range(1, 5) as $attempt) {
        expect($flow->before(loginServerFixture()))->toBeNull()
            ->and($flow->check($auth, 'errado', loginServerFixture())->decision->outcome)->toBe(AuthOutcome::Wrong);
    }

    $blocked = $flow->before(loginServerFixture());
    expect($blocked?->outcome)->toBe(AuthOutcome::Locked)
        ->and($blocked?->retryAfter)->toBe(900)
        ->and($flow->check($auth, 'token-correto', loginServerFixture())->decision->outcome)->toBe(AuthOutcome::Locked)
        ->and($flow->before(loginServerFixture(['REMOTE_ADDR' => '192.168.1.51'])))->toBeNull()
        ->and($flow->check($auth, 'token-correto', loginServerFixture(['REMOTE_ADDR' => '192.168.1.51']))->decision->outcome)->toBe(AuthOutcome::Allowed)
        ->and($attempts->retryAfter('192.168.1.51'))->toBe(0)
        ->and(is_file($dir . DIRECTORY_SEPARATOR . 'auth.json'))->toBeFalse()
        ->and(count($provider->inserted))->toBe(8);
    networkLoginCleanup($dir);
});

it('o sucesso anterior limpa somente o próprio IP e o loopback mantém o fluxo antigo', function () {
    $dir = networkLoginDir();
    $flow = new LoginFlow(new LoginAttempts($dir), loginNetworkFixture(), null);
    $auth = new Auth('token-correto', $dir . DIRECTORY_SEPARATOR . 'has-auth.json');

    foreach (range(1, 4) as $attempt) {
        $flow->check($auth, 'errado', loginServerFixture());
    }
    $flow->check($auth, 'token-correto', loginServerFixture());
    expect($flow->before(loginServerFixture()))->toBeNull()
        ->and($flow->check($auth, 'errado', loginServerFixture(['REMOTE_ADDR' => '127.0.0.1']))->decision->outcome)->toBe(AuthOutcome::Wrong)
        ->and($flow->before(loginServerFixture(['REMOTE_ADDR' => '127.0.0.1'])))->toBeNull();
    networkLoginCleanup(dir: $dir);
});

it('grava entradas rejeitadas e aceitas sem credenciais, cookies ou query', function () {
    $provider = new FakeProvider();
    $audit = new LoginAudit(loginNetworkFixture(), static fn (): ExecutionLogService => new ExecutionLogService($provider));
    $flow = new LoginFlow(new LoginAttempts(networkLoginDir()), loginNetworkFixture(), $audit);
    $auth = new Auth('token-correto', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'unwritten-auth.json');
    $server = loginServerFixture(['REQUEST_URI' => '/config?token=senha&next=<script>alert(1)</script>']);

    $flow->check($auth, 'token=segredo&password=abc', $server);
    $flow->check($auth, 'token-correto', $server);

    expect($provider->inserted)->toHaveCount(2)
        ->and($provider->inserted[0]->kind)->toBe(ExecutionKind::Rede)
        ->and($provider->inserted[0]->exitCode)->toBe(1)
        ->and($provider->inserted[0]->output)->toBe('login de fora recusado; ip=192.168.1.50; motivo=Wrong')
        ->and($provider->inserted[1]->kind)->toBe(ExecutionKind::Rede)
        ->and($provider->inserted[1]->exitCode)->toBe(0)
        ->and($provider->inserted[1]->output)->toContain('login de fora aceito; ip=192.168.1.50; motivo=Allowed')
        ->and(serialize($provider->inserted))->not->toContain('segredo', 'abc', 'token-correto', '<script>', 'next=', 'Cookie');
});

it('não grava nem limita fora de uma LAN aceita', function (bool $enabled, array $overrides, bool $loopback) {
    $provider = new FakeProvider();
    $state = loginNetworkFixture($enabled);
    $audit = new LoginAudit($enabled ? $state : null, static fn (): ExecutionLogService => new ExecutionLogService($provider));
    $flow = new LoginFlow(new LoginAttempts(networkLoginDir()), $enabled ? $state : null, $audit);
    $dir = networkLoginDir();
    $auth = new Auth('correto', $dir . DIRECTORY_SEPARATOR . 'counter.json');
    $server = loginServerFixture($overrides);
    $decision = $flow->check($auth, 'errado', $server)->decision;

    expect($decision->outcome)->toBe(AuthOutcome::Wrong)
        ->and($flow->before($server))->toBeNull()
        ->and($provider->inserted)->toHaveCount(0);
    networkLoginCleanup($dir);
})->with([
    'rede desligada' => [false, [], false],
    'fora da allow' => [true, ['REMOTE_ADDR' => '10.0.0.1'], false],
    'listener errado' => [true, ['SERVER_ADDR' => '192.168.1.21'], false],
    'loopback' => [true, ['REMOTE_ADDR' => '127.0.0.1'], true],
]);

it('mostra o aviso somente com rede ligada e escapa todo o endereço e a allow', function (bool $enabled) {
    $html = Respond::render('layout.php', new LayoutView('teste', '<div class="wrap">x</div>', new State(
        $enabled,
        '192.168.1.20<',
        4001,
        ['192.168.1.0/24<script>alert(1)</script>'],
        false,
        'agora',
    )));

    expect(str_contains($html, 'class="aviso-rede"'))->toBe($enabled);

    if ($enabled) {
        expect($html)->toContain('Acessível pela rede interna em http://192.168.1.20&lt;:4001')
            ->and($html)->toContain('192.168.1.0/24&lt;script&gt;alert(1)&lt;/script&gt;')
            ->and($html)->toContain('<a href="/config">Configurações</a>')
            ->and($html)->not->toContain('<script>alert(1)</script>')
            ->and(strpos($html, 'aviso-rede'))->toBeLessThan(strpos($html, '<main id="conteudo">'));
    }
})->with([true, false]);

it('o aviso usa um link de configurações escapado', function () {
    $html = Respond::render('layout.php', new LayoutView('teste', '<div>conteúdo</div>', new State(true, '192.168.1.20', 4001, ['192.168.1.0/24'], false, 'agora')));

    expect($html)->toContain('position: fixed;', 'top: 0;', '<a href="/config">Configurações</a>');
});

it('mantém o aviso ausente quando não há estado de rede', function () {
    $html = Respond::render('layout.php', new LayoutView('teste', '<div>x</div>'));

    expect($html)->not->toContain('aviso-rede', 'Acessível pela rede interna');
});

it('um cookie válido continua independente do limite de novos tokens', function () {
    $dir = networkLoginDir();
    $attempts = new LoginAttempts($dir, static fn (): int => 1_000_000);
    $audit = new LoginAudit(loginNetworkFixture(), static fn (): ExecutionLogService => new ExecutionLogService(new FakeProvider()));
    $flow = new LoginFlow($attempts, loginNetworkFixture(), $audit);
    $server = loginServerFixture();
    $auth = new Auth('token-correto', $dir . DIRECTORY_SEPARATOR . 'auth.json');

    foreach (range(1, 5) as $attempt) {
        $flow->check($auth, 'errado', $server);
    }
    $cookie = $auth->issueCookie();
    $_COOKIE[Auth::COOKIE] = $cookie['value'];
    $_SERVER = $server;
    $_POST = [];

    ob_start();
    try {
        \App\Http\Login::guard($auth, 'GET', $flow);
        $valid = true;
    } finally {
        ob_end_clean();
        unset($_COOKIE[Auth::COOKIE]);
    }

    expect($valid)->toBeTrue()
        ->and($flow->before($server)?->outcome)->toBe(AuthOutcome::Locked);
    networkLoginCleanup($dir);
});

it('o login inicial e as mensagens de sessão recebem a rede no layout', function () {
    $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Http/Login.php');

    expect($source)->toContain('AuthOutcome::Missing       => self::page($decisao, \'\', network: $network),')
        ->and($source)->toContain('AuthOutcome::Wrong         => self::page($decisao, \'A sessão deste navegador não vale mais. Entre de novo.\', true, $network),')
        ->and($source)->toContain('AuthOutcome::Expired       => self::page($decisao, \'A sessão expirou. Entre de novo.\', true, $network),');
});

it('não abre o histórico quando não há login de fora', function () {
    $opened = false;
    $audit = new LoginAudit(null, static function () use (&$opened): ExecutionLogService {
        $opened = true;

        throw new RuntimeException('O banco não deve ser aberto.');
    });
    $dir = networkLoginDir();
    $flow = new LoginFlow(new LoginAttempts($dir), null, $audit);
    $auth = new Auth('correto', $dir . DIRECTORY_SEPARATOR . 'auth.json');

    expect($flow->check($auth, 'errado', loginServerFixture())->decision->outcome)->toBe(AuthOutcome::Wrong)
        ->and($opened)->toBeFalse();
    networkLoginCleanup($dir);
});
