<?php

declare(strict_types=1);

use App\Domain\Execution;
use App\Domain\ExecutionKind;
use App\Http\WinTab;
use App\Win\SshKeyOutput;
use App\Win\WinAction;

const CHAVE_ED = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8g notebook';
const DIGITAL_ED = 'SHA256:mKqU+0K8OhKmA8bBQi9Rz0Q5l7/g160hIP+rJYSTNj4';
const CHAVE_RSA = 'ssh-rsa AAAAB3NzaC1yc2EAAAAgAQIDBAUGBwgJCgsMDQ4PEBESExQVFhcYGRobHB0eHyA=';

function sshRow(string $command, string $output): Execution
{
    return new Execution(command: $command, output: $output, exitCode: 0, durationMs: 10, kind: ExecutionKind::Windows, timedOut: false);
}

it('aceita a linha pública e calcula a impressão SHA256 como o ssh-keygen -l', function () {
    expect(WinAction::isSshPublicKey(CHAVE_ED))->toBeTrue()
        ->and(WinAction::isSshPublicKey(CHAVE_RSA))->toBeTrue()
        ->and(WinAction::sshFingerprint(CHAVE_ED))->toBe(DIGITAL_ED);
});

it('recusa o que não é uma linha pública: <nome>', function (string $linha) {
    expect(WinAction::isSshPublicKey($linha))->toBeFalse();

    WinAction::SshKeys->validate(['SubAction' => 'authorize', 'PublicKey' => $linha]);
})->throws(InvalidArgumentException::class)->with([
    'opções na frente'     => ['command="id" ' . CHAVE_ED],
    'privada'              => ['-----BEGIN OPENSSH PRIVATE KEY-----'],
    'tipo que não bate'    => ['ssh-rsa AAAAC3NzaC1lZDI1NTE5AAAAIAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8g'],
    'duas linhas'          => [CHAVE_ED . "\n" . CHAVE_RSA],
    'comentário com espaço' => [CHAVE_ED . ' x y'],
    'tipo fora da lista'   => ['ssh-dss AAAAB3NzaC1kc3MAAAAgAQIDBAUGBwgJCgsMDQ4PEBESExQVFhcYGRobHB0eHyA='],
    'base64 quebrado'      => ['ssh-ed25519 AAAA$$$$AAAAAAAAAAAAAAAAAAAA'],
]);

it('ssh-keys aceita as sete subações, e só elas', function (string $sub) {
    $entrada = match ($sub) {
        'authorize' => ['PublicKey' => CHAVE_ED],
        'remove'    => ['Fingerprint' => DIGITAL_ED],
        'wsl-user'  => ['PublicKey' => CHAVE_ED, 'User' => 'dev', 'Distro' => 'Debian'],
        default     => [],
    };

    expect(WinAction::SshKeys->validate(['SubAction' => strtoupper($sub)] + $entrada)['SubAction'])->toBe($sub);
})->with(WinAction::SSHKEYS_SUBACTIONS);

it('ssh-keys recusa subação fora do conjunto fechado, e exige uma', function (array $input) {
    WinAction::SshKeys->validate($input);
})->throws(InvalidArgumentException::class)->with([
    'sem subação' => [[]],
    'fora'        => [['SubAction' => 'export-private']],
]);

it('ssh-keys: campo de outra subação não passa', function (array $input) {
    WinAction::SshKeys->validate($input);
})->throws(InvalidArgumentException::class)->with([
    'chave no generate'  => [['SubAction' => 'generate', 'PublicKey' => CHAVE_ED]],
    'usuário no authorize' => [['SubAction' => 'authorize', 'PublicKey' => CHAVE_ED, 'User' => 'dev']],
    'chave no remove'    => [['SubAction' => 'remove', 'Fingerprint' => DIGITAL_ED, 'PublicKey' => CHAVE_ED]],
]);

it('ssh-keys: authorize pede a colada ou a gerada, não as duas nem nenhuma', function () {
    expect(WinAction::SshKeys->validate(['SubAction' => 'authorize', 'UseGenerated' => '1']))
        ->toBe(['SubAction' => 'authorize', 'UseGenerated' => true])
        ->and(WinAction::SshKeys->validate(['SubAction' => 'authorize', 'PublicKey' => '  ' . CHAVE_ED . "\n"]))
        ->toBe(['SubAction' => 'authorize', 'PublicKey' => CHAVE_ED])
        ->and(fn () => WinAction::SshKeys->validate(['SubAction' => 'authorize']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => WinAction::SshKeys->validate(['SubAction' => 'authorize', 'UseGenerated' => '1', 'PublicKey' => CHAVE_ED]))->toThrow(InvalidArgumentException::class);
});

it('ssh-keys: remove só com impressão SHA256 bem formada', function (string $digital) {
    WinAction::SshKeys->validate(['SubAction' => 'remove', 'Fingerprint' => $digital]);
})->throws(InvalidArgumentException::class)->with(['', 'MD5:aa:bb', 'SHA256:curta', DIGITAL_ED . 'x']);

it('ssh-keys: usuário do WSL recusado: <u>', function (string $u) {
    WinAction::SshKeys->validate(['SubAction' => 'wsl-user', 'UseGenerated' => '1', 'User' => $u, 'Distro' => 'Debian']);
})->throws(InvalidArgumentException::class)->with([
    'vazio' => [''], 'maiúscula' => ['Dev'], 'começa com número' => ['1dev'], 'ponto e vírgula' => ['dev;id'],
    'espaço' => ['de v'], 'longo' => [str_repeat('a', 33)], 'root' => ['root'], 'cifrão' => ['$(id)'],
]);

it('ssh-keys: distro fora do formato não passa', function (string $d) {
    WinAction::SshKeys->validate(['SubAction' => 'wsl-user', 'UseGenerated' => '1', 'User' => 'dev', 'Distro' => $d]);
})->throws(InvalidArgumentException::class)->with(['', 'Deb ian', '-d', 'a;b']);

it('ssh-keys: puttygen, authorize, remove e wsl-user são sensíveis; status, generate e list não', function () {
    foreach (WinAction::SSHKEYS_SUBACTIONS as $sub) {
        expect(WinAction::SshKeys->isSensitive(['SubAction' => $sub]))
            ->toBe(in_array($sub, ['puttygen', 'authorize', 'remove', 'wsl-user'], true));
    }
    expect(WinAction::SshKeys->stateChange(['SubAction' => 'authorize']))->toBeNull();
});

it('ssh-keys: o worker tem o mesmo conjunto, as mesmas sensíveis e os mesmos formatos', function () {
    $worker = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Win/worker.ps1');
    $acao   = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Win/actions/Invoke-SshKeys.ps1');

    expect($worker)->toContain("valores = @('" . implode("', '", WinAction::SSHKEYS_SUBACTIONS) . "')")
        ->and($worker)->toContain("'ssh-keys'  = @('puttygen', 'authorize', 'remove', 'wsl-user')")
        ->and($worker)->toContain("padrao = '" . WinAction::SSH_PUBLIC_KEY_PATTERN . "'")
        ->and($worker)->toContain("padrao = '" . WinAction::SSH_FINGERPRINT_PATTERN . "'")
        ->and($worker)->toContain("padrao = '" . WinAction::WSL_USER_PATTERN . "'")
        ->and($worker)->toContain("padrao = '" . WinAction::WSL_DISTRO_PATTERN . "'")
        ->and($acao)->toContain("'" . WinAction::SSH_PUBLIC_KEY_PATTERN . "'")
        ->and($acao)->toContain("\$wingetId = '" . WinAction::PUTTYGEN_WINGET_ID . "'")
        ->and(preg_match(WinAction::WINGET_ID_PATTERN, WinAction::PUTTYGEN_WINGET_ID))->toBe(1);
});

it('a saída do worker vira a pública gerada, a lista e os usuários, sem nada além da pública', function () {
    $rows = [
        sshRow('ssh-keys -SubAction list', "[ INFO ] Authorized key: ed25519 " . DIGITAL_ED . " notebook\n[ INFO ] Authorized key: rsa SHA256:zqsl5TQN3NwFuiemj7Upvu2vNIzY6dQuKMBmnQ6iNQU"),
        sshRow('ssh-keys -SubAction wsl-user -User dev -Distro Debian', '[ OK ] WSL user: dev Debian ' . DIGITAL_ED),
        sshRow('ssh-keys -SubAction generate', "[ OK ] Public key: " . CHAVE_ED . "\r\n[ OK ] Fingerprint: " . DIGITAL_ED),
        sshRow('ssh-keys -SubAction list', '[ INFO ] No authorized key on this Windows.'),
    ];

    expect(SshKeyOutput::generated($rows))->toBe(['key' => CHAVE_ED, 'fingerprint' => DIGITAL_ED])
        ->and(SshKeyOutput::authorized($rows))->toBe([
            ['kind' => 'ed25519', 'fingerprint' => DIGITAL_ED, 'comment' => 'notebook'],
            ['kind' => 'rsa', 'fingerprint' => 'SHA256:zqsl5TQN3NwFuiemj7Upvu2vNIzY6dQuKMBmnQ6iNQU', 'comment' => ''],
        ])
        ->and(SshKeyOutput::authorized(array_slice($rows, 3)))->toBe([])
        ->and(SshKeyOutput::authorized([]))->toBeNull()
        ->and(SshKeyOutput::wslUsers($rows))->toBe([['user' => 'dev', 'distro' => 'Debian', 'fingerprint' => DIGITAL_ED]])
        ->and(SshKeyOutput::publicKey('[ OK ] Public key: -----BEGIN OPENSSH PRIVATE KEY-----'))->toBeNull()
        ->and(SshKeyOutput::generated([sshRow('install -Apps X', '[ OK ] Public key: ' . CHAVE_ED)]))->toBeNull();
});

it('a aba Chaves SSH vem depois de Acesso Remoto e traz as quatro seções', function () {
    $html   = winHtml(aba: WinTab::ChavesSsh, acoes: ['openssh'], distros: ['Debian']);
    $painel = winPainel($html, WinTab::ChavesSsh);

    expect(strpos($html, 'data-aba="acesso-remoto"'))->toBeLessThan((int) strpos($html, 'data-aba="chaves-ssh"'))
        ->and($painel)->toContain('<h2>Gerar chave neste Windows</h2>')
        ->and($painel)->toContain('<h2>Arquivo .ppk</h2>')
        ->and($painel)->toContain('<h2>Autorizar no Windows</h2>')
        ->and($painel)->toContain('<h2>Usuário e chave no WSL</h2>')
        ->and($painel)->toContain('<option value="Debian">Debian</option>')
        ->and($painel)->not->toContain('data-chaves-aviso')
        ->and($painel)->not->toMatch('/name="PublicKey"[^>]*disabled/');
});

it('com o OpenSSH desligado, o aviso aparece e autorizar e WSL nascem desabilitados', function () {
    $painel = winPainel(winHtml(aba: WinTab::ChavesSsh, distros: ['Debian']), WinTab::ChavesSsh);

    expect($painel)->toContain('data-chaves-aviso')
        ->and($painel)->toContain('O OpenSSH Server está desligado.')
        ->and(preg_match_all('/name="PublicKey"[^>]*disabled/', $painel))->toBe(2)
        ->and($painel)->toContain('name="User" maxlength="32" pattern="[a-z][a-z0-9\-]{0,31}" placeholder="somente minúsculas, números, hífen" disabled');
});

it('a tela mostra a pública e a lista da saída, e o remover pede confirmação', function () {
    $rows = [
        sshRow('ssh-keys -SubAction authorize -PublicKey "' . CHAVE_ED . '"', '[ INFO ] Authorized key: ed25519 ' . DIGITAL_ED . ' notebook'),
        sshRow('ssh-keys -SubAction generate', '[ OK ] Public key: ' . CHAVE_ED),
    ];
    $painel = winPainel(winHtml(aba: WinTab::ChavesSsh, acoes: ['openssh'], rows: $rows, distros: ['Debian']), WinTab::ChavesSsh);

    expect($painel)->toContain('<textarea id="chave-publica" readonly>' . CHAVE_ED . '</textarea>')
        ->and($painel)->toContain('data-remover-chave data-rotulo="notebook"')
        ->and($painel)->toContain('<input type="hidden" name="Fingerprint" value="' . DIGITAL_ED . '">')
        ->and(winHtml())->toContain('<dialog id="modal-remover-chave">');
});

it('o que os formulários da aba mandam passa pela validação', function () {
    $html = winPainel(winHtml(aba: WinTab::ChavesSsh, acoes: ['openssh'], distros: ['Debian']), WinTab::ChavesSsh);

    preg_match_all('/name="SubAction" value="([a-z-]+)"/', $html, $m);

    expect(array_values(array_unique($m[1])))->toBe(['generate', 'status', 'puttygen', 'authorize', 'list', 'wsl-user']);
});
