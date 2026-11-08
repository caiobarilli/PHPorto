<?php

declare(strict_types=1);

use App\Win\WinAction;

const AS_PHP = 'C:\\php\\php.exe';
const AS_SCRIPT = 'C:\\Users\\dev\\code\\PHPorto\\phporto';
const AS_SID = 'S-1-5-21-1111111111-2222222222-3333333333-1001';

it('autostart on valida e devolve só os campos aceitos', function () {
    expect(WinAction::Autostart->validate([
        'SubAction' => 'on', 'Sid' => AS_SID, 'Program' => AS_PHP, 'Script' => AS_SCRIPT, 'Extra' => 'x',
    ]))->toBe(['SubAction' => 'on', 'Sid' => AS_SID, 'Program' => AS_PHP, 'Script' => AS_SCRIPT]);
});

it('autostart off não leva parâmetro e stop leva só o script', function () {
    expect(WinAction::Autostart->validate(['SubAction' => 'off', 'Sid' => AS_SID]))->toBe(['SubAction' => 'off'])
        ->and(WinAction::Autostart->validate(['SubAction' => 'stop', 'Script' => AS_SCRIPT, 'Program' => AS_PHP]))
        ->toBe(['SubAction' => 'stop', 'Script' => AS_SCRIPT]);
});

it('autostart recusa o que não é do formato', function (array $entrada) {
    $base = ['SubAction' => 'on', 'Sid' => AS_SID, 'Program' => AS_PHP, 'Script' => AS_SCRIPT];

    expect(fn () => WinAction::Autostart->validate(array_replace($base, $entrada)))->toThrow(InvalidArgumentException::class);
})->with([
    'subação' => [['SubAction' => 'status']],
    'sem script' => [['Script' => '']],
    'script de outro nome' => [['Script' => 'C:\\x\\token.php']],
    'script relativo' => [['Script' => 'phporto']],
    'script com subida' => [['Script' => 'C:\\x\\..\\phporto']],
    'script de rede' => [['Script' => '\\\\srv\\x\\phporto']],
    'script com aspas' => [['Script' => 'C:\\x"y\\phporto']],
    'nome no lugar do SID' => [['Sid' => 'DESKTOP\\caiob']],
    'SID vazio' => [['Sid' => '']],
    'SID sem número' => [['Sid' => 'S-1-5-21-1-2-3']],
    'SID com letra' => [['Sid' => 'S-1-5-21-1-2-3-abc']],
    'SID com sufixo' => [['Sid' => AS_SID . ';calc']],
    'SID de outra autoridade' => [['Sid' => 'S-1-5-18']],
    'SID com quebra de linha' => [['Sid' => AS_SID . "\nx"]],
    'SID sem o campo' => [['Sid' => null]],
    'programa fora de php.exe' => [['Program' => 'C:\\Windows\\notepad.exe']],
    'programa relativo' => [['Program' => 'php.exe']],
]);

it('autostart é sensível nas três subações', function (string $sub) {
    expect(WinAction::Autostart->isSensitive(['SubAction' => $sub]))->toBeTrue();
})->with(['on', 'off', 'stop']);

it('o worker usa os mesmos formatos da ação autostart', function () {
    $worker = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Win/worker.ps1');

    expect(preg_match("/^    'autostart'\s*=\s*@\{\r?\n(.*?)\r?\n    \}/ms", $worker, $bloco))->toBe(1)
        ->and($bloco[1])->toContain("valores = @('on', 'off', 'stop')")
        ->and($bloco[1])->toContain("padrao = '" . WinAction::AUTOSTART_SID_PATTERN . "'")
        ->and($bloco[1])->toContain("padrao = '" . WinAction::AUTOSTART_SCRIPT_PATTERN . "'")
        ->and($bloco[1])->toContain("padrao = '" . WinAction::NET_FIREWALL_PROGRAM_PATTERN . "'");
});

it('a tarefa nasce com S4U, RunLevel Limited e nenhuma senha em lugar nenhum do código', function () {
    $raiz = dirname(__DIR__, 2);
    $script = (string) file_get_contents($raiz . '/src/Win/actions/Invoke-Autostart.ps1');

    expect($script)->toContain('-LogonType S4U')->toContain('-RunLevel Limited')->toContain('-AtStartup')
        ->and($script)->toContain('-MultipleInstances IgnoreNew')->toContain('-RestartCount')
        ->and($script)->not->toContain('-RunLevel Highest')
        ->and($script)->not->toMatch('/-Password|Get-Credential|SecureString|LogonType\s+Password|RunAsPassword|Read-Host/i');

    $arquivos = [
        'src/Autostart/Manager.php',
        'src/Autostart/Task.php',
        'src/Autostart/Store.php',
        'src/Cli/AutostartCommand.php',
    ];

    foreach ($arquivos as $arquivo) {
        $texto = (string) file_get_contents($raiz . '/' . $arquivo);

        expect($texto)->not->toMatch('/password|senha|credential|securestring|STDIN|readLine|\$_POST/i');
    }
});
