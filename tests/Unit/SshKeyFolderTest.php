<?php

declare(strict_types=1);

use App\Http\Pages;
use App\Http\WinTab;
use App\Win\SshKeyFolder;
use App\Win\SshKeyOutput;
use App\Win\WinAction;

function sshPerfil(): string
{
    $dir = sys_get_temp_dir() . '/phporto-ssh-' . bin2hex(random_bytes(6));
    mkdir($dir . '/.ssh', 0700, true);

    return $dir;
}

it('nome da chave aceito: <nome>', function (string $nome) {
    expect(SshKeyFolder::name($nome))->toBe(trim($nome));
})->with(['id_ed25519', 'trabalho-2026', 'A', '  casa_1  ', str_repeat('a', 64)]);

it('nome da chave recusado: <nome>', function (string $nome) {
    SshKeyFolder::name($nome);
})->throws(InvalidArgumentException::class)->with([
    'vazio'      => [''],
    'longo'      => [str_repeat('a', 65)],
    'ponto'      => ['id.ed'],
    'barra'      => ['..\\x'],
    'barra unix' => ['a/b'],
    'espaço'     => ['a b'],
    'aspas'      => ['a"b'],
    'acento'     => ['chavé'],
    'reservado'  => ['NUL'],
    'com1'       => ['com1'],
]);

it('generate e status levam o nome, com padrão id_ed25519', function () {
    expect(WinAction::SshKeys->validate(['SubAction' => 'generate'])['KeyName'])->toBe('id_ed25519')
        ->and(WinAction::SshKeys->validate(['SubAction' => 'generate', 'KeyName' => 'trabalho'])['KeyName'])->toBe('trabalho')
        ->and(WinAction::SshKeys->validate(['SubAction' => 'status', 'KeyName' => 'trabalho'])['KeyName'])->toBe('trabalho')
        ->and(fn () => WinAction::SshKeys->validate(['SubAction' => 'generate', 'KeyName' => '../x']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => WinAction::SshKeys->validate(['SubAction' => 'remove', 'Fingerprint' => DIGITAL_ED, 'KeyName' => 'x']))->toThrow(InvalidArgumentException::class);
});

it('authorize e wsl-user usam a chave nomeada só quando é a gerada', function () {
    $gerada = WinAction::SshKeys->validate(['SubAction' => 'authorize', 'UseGenerated' => '1', 'KeyName' => 'trabalho']);
    $colada = WinAction::SshKeys->validate(['SubAction' => 'authorize', 'PublicKey' => CHAVE_ED, 'KeyName' => 'trabalho']);
    $wsl    = WinAction::SshKeys->validate(['SubAction' => 'wsl-user', 'UseGenerated' => '1', 'KeyName' => 'casa', 'User' => 'dev', 'Distro' => 'Debian']);

    expect($gerada['KeyName'])->toBe('trabalho')
        ->and($colada)->not->toHaveKey('KeyName')
        ->and($wsl['KeyName'])->toBe('casa');
});

it('recusa gerar quando já existe a privada, a pública ou as duas', function (array $arquivos) {
    $perfil = sshPerfil();
    foreach ($arquivos as $a) {
        file_put_contents($perfil . '/.ssh/' . $a, 'NAO-MEXER');
    }

    $params = WinAction::SshKeys->validate(['SubAction' => 'generate', 'KeyName' => 'minha']);
    $msg    = Pages::sshKeyRefusal(WinAction::SshKeys, $params, $perfil);

    expect($msg)->toContain('Já existe')->toContain('nada foi sobrescrito');
    foreach ($arquivos as $a) {
        expect($msg)->toContain($a)
            ->and(file_get_contents($perfil . '/.ssh/' . $a))->toBe('NAO-MEXER');
    }
})->with([
    'privada' => [['minha']],
    'pública' => [['minha.pub']],
    'as duas' => [['minha', 'minha.pub']],
]);

it('deixa gerar quando o nome está livre, e não se mete nas outras subações', function () {
    $perfil = sshPerfil();
    file_put_contents($perfil . '/.ssh/id_ed25519', 'x');

    expect(Pages::sshKeyRefusal(WinAction::SshKeys, ['SubAction' => 'generate', 'KeyName' => 'nova'], $perfil))->toBeNull()
        ->and(Pages::sshKeyRefusal(WinAction::SshKeys, ['SubAction' => 'status', 'KeyName' => 'id_ed25519'], $perfil))->toBeNull()
        ->and(Pages::sshKeyRefusal(WinAction::Rdp, ['SubAction' => 'generate'], $perfil))->toBeNull();
});

it('o handler recusa antes de despachar para o worker', function () {
    $pages  = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Http/Pages.php');
    $ini    = (int) strpos($pages, 'private function handleWinPost(');
    $corpo  = substr($pages, $ini, (int) strpos($pages, 'private function ', $ini + 10) - $ini);

    expect(strpos($corpo, 'sshKeyRefusal('))->toBeLessThan((int) strpos($corpo, '->dispatch('));
});

it('a tela tem o campo de nome, o caminho completo da chave e nada de Explorer', function () {
    $saida = "[ OK ] Key name: trabalho\n[ OK ] Key path: C:\\Users\\dev\\.ssh\\trabalho\n[ OK ] Public key: " . CHAVE_ED . "\n";
    $html  = winHtml(acoes: ['openssh'], aba: WinTab::ChavesSsh, rows: [sshRow('ssh-keys -SubAction generate -KeyName trabalho', $saida)]);

    expect(SshKeyOutput::publicKey($saida)['name'] ?? null)->toBe('trabalho')
        ->and(SshKeyOutput::publicKey($saida)['path'] ?? null)->toBe('C:\\Users\\dev\\.ssh\\trabalho')
        ->and($html)->toContain('name="KeyName"')
        ->and($html)->toContain('value="trabalho"')
        ->and($html)->toContain('<code>C:\\Users\\dev\\.ssh\\trabalho</code>')
        ->and($html)->toContain('<code>C:\\Users\\dev\\.ssh\\trabalho.pub</code>')
        ->and($html)->toContain('Usar a gerada (trabalho)')
        ->and($html)->not->toContain('Ver no Explorer')
        ->and((string) file_get_contents(dirname(__DIR__, 2) . '/src/Win/SshKeyFolder.php'))->not->toMatch('/explorer|proc_open/i');
});

it('o worker tem o mesmo formato de nome', function () {
    $worker = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Win/worker.ps1');
    $acao   = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Win/actions/Invoke-SshKeys.ps1');

    expect($worker)->toContain("'KeyName'      = @{ tipo = 'text'; max = 64; padrao = '" . SshKeyFolder::NAME_PATTERN . "' }")
        ->and($acao)->toContain("'" . SshKeyFolder::NAME_PATTERN . "'");
});
