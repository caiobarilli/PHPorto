<?php

declare(strict_types=1);

use App\Autostart\Identity;

const AI_SID = 'S-1-5-21-1111111111-2222222222-3333333333-1001';

it('lê o nome e o SID da saída do whoami /user', function () {
    $i = Identity::current(static fn (): string => '"desktop' . chr(92) . 'caiob","' . AI_SID . "\"\r\n");

    expect($i)->toBeInstanceOf(Identity::class)
        ->and($i->name)->toBe('desktop' . chr(92) . 'caiob')
        ->and($i->sid)->toBe(AI_SID);
});

it('recusa saída sem SID válido e falha de leitura', function (?string $saida) {
    expect(Identity::current(static fn (): ?string => $saida))->toBeNull();
})->with([
    'sem saída' => [null],
    'vazia' => [''],
    'só o nome' => ['"desktop' . "\\" . 'caiob"'],
    'SID malformado' => ['"d\u","S-1-5-21-1-2-3"'],
    'SID de sistema' => ['"nt authority\system","S-1-5-18"'],
    'SID com lixo' => ['"d\u","' . AI_SID . ';calc"'],
    'campo extra' => ['"d\u","' . AI_SID . '","x"'],
    'nome vazio' => ['"","' . AI_SID . '"'],
]);

it('as variáveis USERDOMAIN e USERNAME não influem no SID', function () {
    if (DIRECTORY_SEPARATOR !== chr(92)) {
        $this->markTestSkipped('whoami do Windows');
    }

    $antes = Identity::current();
    expect($antes)->toBeInstanceOf(Identity::class)
        ->and(Identity::validSid($antes->sid))->toBeTrue();

    $domain = getenv('USERDOMAIN');
    $user = getenv('USERNAME');
    putenv('USERDOMAIN=DOMINIO-FALSO');
    putenv('USERNAME=outra-pessoa');

    try {
        $depois = Identity::current();
    } finally {
        putenv($domain === false ? 'USERDOMAIN' : 'USERDOMAIN=' . $domain);
        putenv($user === false ? 'USERNAME' : 'USERNAME=' . $user);
    }

    expect($depois)->not->toBeNull()
        ->and($depois->sid)->toBe($antes->sid)
        ->and($depois->name)->not->toContain('DOMINIO-FALSO')->not->toContain('outra-pessoa');
});

it('o SID bate com o do token do próprio processo (PowerShell)', function () {
    if (DIRECTORY_SEPARATOR !== chr(92)) {
        $this->markTestSkipped('PowerShell do Windows');
    }

    $saida = trim((string) shell_exec('powershell -NoProfile -Command "[Security.Principal.WindowsIdentity]::GetCurrent().User.Value"'));

    expect(Identity::current()?->sid)->toBe($saida);
});

it('nenhum código do autostart lê USERDOMAIN ou USERNAME do ambiente', function () {
    $raiz = dirname(__DIR__, 2);

    foreach (['src/Cli/Main.php', 'src/Http/Pages/ConfigPage.php', 'src/Autostart/Manager.php', 'src/Autostart/Identity.php'] as $arquivo) {
        expect((string) file_get_contents($raiz . '/' . $arquivo))->not->toMatch('/USERDOMAIN|[\'"]USERNAME[\'"]/');
    }
});
