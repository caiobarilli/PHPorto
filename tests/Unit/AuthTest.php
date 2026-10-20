<?php

declare(strict_types=1);

use App\Http\Auth;
use App\Http\AuthOutcome;

beforeEach(function () {
    $this->arquivo = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phporto_auth_' . bin2hex(random_bytes(4)) . '.json';
    $this->agora   = 1_000_000;
});

afterEach(function () {
    if (is_file($this->arquivo)) {
        unlink($this->arquivo);
    }
});

function portao(object $t, string $token = 'certo'): Auth
{
    return new Auth($token, $t->arquivo, static fn (): int => $t->agora);
}

it('sem credencial é 401, e não conta como erro', function () {
    foreach (range(1, 10) as $_) {
        expect(portao($this)->check(null)->outcome)->toBe(AuthOutcome::Missing);
    }

    expect(AuthOutcome::Missing->status())->toBe(401)
        ->and(file_exists($this->arquivo))->toBeFalse();
});

it('token errado é 401, e token certo passa', function () {
    expect(portao($this)->check('errado')->outcome)->toBe(AuthOutcome::Wrong)
        ->and(AuthOutcome::Wrong->status())->toBe(401)
        ->and(portao($this)->check('certo')->outcome)->toBe(AuthOutcome::Allowed);
});

it('SEM TOKEN CONFIGURADO RECUSA SERVIR, qualquer que seja a senha', function (?string $senha) {
    $decisao = portao($this, '')->check($senha);

    expect($decisao->outcome)->toBe(AuthOutcome::NotConfigured)
        ->and($decisao->outcome->status())->toBe(503);
})->with([[null], [''], ['qualquer']]);

it('o quinto erro seguido bloqueia com 429, por quinze minutos', function () {
    foreach (range(1, Auth::MAX_FAILURES - 1) as $_) {
        expect(portao($this)->check('errado')->outcome)->toBe(AuthOutcome::Wrong);
    }

    $decisao = portao($this)->check('errado');

    expect($decisao->outcome)->toBe(AuthOutcome::Locked)
        ->and($decisao->outcome->status())->toBe(429)
        ->and($decisao->retryAfter)->toBe(Auth::LOCK_SECONDS);
});

it('durante o bloqueio nem o token certo passa, e o que falta diminui', function () {
    foreach (range(1, Auth::MAX_FAILURES) as $_) {
        portao($this)->check('errado');
    }

    $this->agora += 600;
    $decisao = portao($this)->check('certo');

    expect($decisao->outcome)->toBe(AuthOutcome::Locked)
        ->and($decisao->retryAfter)->toBe(Auth::LOCK_SECONDS - 600);

    $this->agora += 300;

    expect(portao($this)->check('certo')->outcome)->toBe(AuthOutcome::Allowed);
});

it('UM ACERTO ZERA O CONTADOR', function () {
    foreach (range(1, Auth::MAX_FAILURES - 1) as $_) {
        portao($this)->check('errado');
    }

    expect(portao($this)->check('certo')->outcome)->toBe(AuthOutcome::Allowed);

    foreach (range(1, Auth::MAX_FAILURES - 1) as $_) {
        expect(portao($this)->check('errado')->outcome)->toBe(AuthOutcome::Wrong);
    }
});

it('TROCAR O TOKEN descarta o bloqueio do token anterior', function () {
    foreach (range(1, Auth::MAX_FAILURES) as $_) {
        portao($this, 'antigo')->check('errado');
    }

    expect(portao($this, 'antigo')->check('antigo')->outcome)->toBe(AuthOutcome::Locked)
        ->and(portao($this, 'novo')->check('novo')->outcome)->toBe(AuthOutcome::Allowed);
});

it('o contador guarda a impressão do token, nunca o token', function () {
    portao($this, 'segredo-do-teste')->check('errado');

    expect((string) file_get_contents($this->arquivo))->not->toContain('segredo-do-teste')
        ->and((string) file_get_contents($this->arquivo))->toContain(hash('sha256', 'segredo-do-teste'));
});

it('contador ilegível conta como zero, em vez de derrubar a requisição', function () {
    file_put_contents($this->arquivo, '{isto não é json');

    expect(portao($this)->check('certo')->outcome)->toBe(AuthOutcome::Allowed);
});

it('a comparação é por hash_equals', function () {
    $fonte = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Http/Auth.php');

    expect($fonte)->toContain('hash_equals($this->token, $password)')
        ->and($fonte)->not->toMatch('/[!=]==?\s*\$password|\$password\s*[!=]==?\s*\$this->token/');
});

it('o cookie emitido passa, e não carrega o token', function () {
    $cookie = portao($this, 'segredo-do-teste')->issueCookie();

    expect(portao($this, 'segredo-do-teste')->checkCookie($cookie['value'])->outcome)->toBe(AuthOutcome::Allowed)
        ->and($cookie['value'])->not->toContain('segredo-do-teste')
        ->and($cookie['value'])->toMatch('/^\d+\.[0-9a-f]{64}$/')
        ->and($cookie['expires'])->toBe($this->agora + Auth::COOKIE_SECONDS);
});

it('sem cookie é Missing, e não conta como erro', function (?string $cookie) {
    foreach (range(1, 10) as $_) {
        expect(portao($this)->checkCookie($cookie)->outcome)->toBe(AuthOutcome::Missing);
    }

    expect(file_exists($this->arquivo))->toBeFalse();
})->with([[null], ['']]);

it('COOKIE ADULTERADO conta como erro, e o quinto bloqueia', function (string $adulterado) {
    foreach (range(1, Auth::MAX_FAILURES - 1) as $_) {
        expect(portao($this)->checkCookie($adulterado)->outcome)->toBe(AuthOutcome::Wrong);
    }

    expect(portao($this)->checkCookie($adulterado)->outcome)->toBe(AuthOutcome::Locked);
})->with([
    'lixo'             => ['qualquer-coisa'],
    'assinatura falsa' => [(1_000_000 + 100) . '.' . str_repeat('a', 64)],
    'hex maiúsculo'    => [(1_000_000 + 100) . '.' . str_repeat('A', 64)],
]);

it('estender o prazo de um cookie verdadeiro invalida a assinatura', function () {
    [, $mac] = explode('.', portao($this)->issueCookie()['value']);
    $falso   = ($this->agora + Auth::COOKIE_SECONDS - 10) . '.' . $mac;

    expect(portao($this)->checkCookie($falso)->outcome)->toBe(AuthOutcome::Wrong);
});

it('cookie vencido é Expired, e não conta como erro', function () {
    $cookie = portao($this)->issueCookie()['value'];
    $this->agora += Auth::COOKIE_SECONDS;

    foreach (range(1, 10) as $_) {
        expect(portao($this)->checkCookie($cookie)->outcome)->toBe(AuthOutcome::Expired);
    }

    expect(AuthOutcome::Expired->status())->toBe(401)
        ->and(file_exists($this->arquivo))->toBeFalse();
});

it('TROCAR O TOKEN invalida o cookie emitido com o anterior', function () {
    $cookie = portao($this, 'antigo')->issueCookie()['value'];

    expect(portao($this, 'novo')->checkCookie($cookie)->outcome)->toBe(AuthOutcome::Wrong);
});

it('durante o bloqueio nem o cookie válido passa', function () {
    $cookie = portao($this)->issueCookie()['value'];

    foreach (range(1, Auth::MAX_FAILURES) as $_) {
        portao($this)->check('errado');
    }

    expect(portao($this)->checkCookie($cookie)->outcome)->toBe(AuthOutcome::Locked);
});

it('sem token configurado o cookie também recusa servir', function () {
    expect(portao($this, '')->checkCookie('1.' . str_repeat('a', 64))->outcome)->toBe(AuthOutcome::NotConfigured);
});

it('a assinatura do cookie é HMAC com o token e é conferida por hash_equals', function () {
    $fonte = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Http/Auth.php');

    expect($fonte)->toContain("hash_hmac('sha256', self::COOKIE_LABEL . '|' . \$ate, \$this->token)")
        ->and($fonte)->toContain('hash_equals($this->sign((int) $m[1]), $m[2])');
});
