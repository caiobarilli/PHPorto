<?php

declare(strict_types=1);

use App\Win\WinAction;

const AS_SCRIPT = 'C:\\Users\\dev\\code\\PHPorto\\phporto';

it('autostart off não leva parâmetro; stop e restart levam só o script', function () {
    expect(WinAction::Autostart->validate(['SubAction' => 'off', 'Script' => AS_SCRIPT]))->toBe(['SubAction' => 'off'])
        ->and(WinAction::Autostart->validate(['SubAction' => 'stop', 'Script' => AS_SCRIPT, 'Program' => 'x', 'Sid' => 'y']))
        ->toBe(['SubAction' => 'stop', 'Script' => AS_SCRIPT])
        ->and(WinAction::Autostart->validate(['SubAction' => 'restart', 'Script' => AS_SCRIPT, 'Extra' => 'x']))
        ->toBe(['SubAction' => 'restart', 'Script' => AS_SCRIPT]);
});

it('autostart recusa o que não é do formato', function (array $entrada) {
    $base = ['SubAction' => 'restart', 'Script' => AS_SCRIPT];

    expect(fn () => WinAction::Autostart->validate(array_replace($base, $entrada)))->toThrow(InvalidArgumentException::class);
})->with([
    'subação on não existe mais' => [['SubAction' => 'on']],
    'subação status' => [['SubAction' => 'status']],
    'sem script' => [['Script' => '']],
    'script de outro nome' => [['Script' => 'C:\\x\\token.php']],
    'script relativo' => [['Script' => 'phporto']],
    'script com subida' => [['Script' => 'C:\\x\\..\\phporto']],
    'script de rede' => [['Script' => '\\\\srv\\x\\phporto']],
    'script com aspas' => [['Script' => 'C:\\x"y\\phporto']],
]);

it('autostart é sensível nas três subações', function (string $sub) {
    expect(WinAction::Autostart->isSensitive(['SubAction' => $sub]))->toBeTrue();
})->with(['off', 'stop', 'restart']);

it('o worker usa os mesmos formatos da ação autostart', function () {
    $worker = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Win/worker.ps1');

    expect(preg_match("/^    'autostart'\s*=\s*@\{\r?\n(.*?)\r?\n    \}/ms", $worker, $bloco))->toBe(1)
        ->and($bloco[1])->toContain("valores = @('off', 'stop', 'restart')")
        ->and($bloco[1])->toContain("padrao = '" . WinAction::AUTOSTART_SCRIPT_PATTERN . "'")
        ->and($bloco[1])->not->toContain('Sid')->not->toContain('Program')->not->toContain('UserName');
});

it('a ação elevada não cria tarefa nenhuma: só remove, para e reinicia, e senha não passa por nenhum código', function () {
    $raiz = dirname(__DIR__, 2);
    $script = (string) file_get_contents($raiz . '/src/Win/actions/Invoke-Autostart.ps1');

    expect($script)->not->toMatch('/Register-ScheduledTask|New-ScheduledTask|-LogonType|S4U|-RunLevel/')
        ->and($script)->toContain('Unregister-ScheduledTask')->toContain('Start-ScheduledTask')->toContain('Stop-ScheduledTask')
        ->and($script)->not->toMatch('/-Password|Get-Credential|SecureString|RunAsPassword|Read-Host/i');

    $arquivos = [
        'src/Autostart/Manager.php',
        'src/Autostart/Task.php',
        'src/Autostart/TaskXml.php',
        'src/Autostart/Store.php',
        'src/Cli/AutostartCommand.php',
    ];

    foreach ($arquivos as $arquivo) {
        $texto = (string) file_get_contents($raiz . '/' . $arquivo);

        expect($texto)->not->toMatch('/securestring|STDIN|readLine|\$_POST|Get-Credential|-Password|<Password>/i');
    }
});
