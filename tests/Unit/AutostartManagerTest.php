<?php

declare(strict_types=1);

use App\Autostart\History;
use App\Autostart\Identity;
use App\Autostart\Manager;
use App\Autostart\Store;
use App\Autostart\Task;
use App\Autostart\TaskObservation;
use App\Cli\Console;
use App\Cli\Main;
use App\Cli\Status;
use App\Network\Store as NetworkStore;
use App\Services\ExecutionLogService;
use App\Win\PsResult;
use App\Win\WinAction;
use Tests\Fakes\FakeProvider;

const ASM_SID = 'S-1-5-21-1111111111-2222222222-3333333333-1001';
const ASM_NAME = 'DESKTOP\\dev';
const ASM_PHP = 'C:\\php\\php.exe';
const ASM_ROOT = 'C:\\Users\\dev\\code\\PHPorto';
const ASM_TASK_OK = "TASK=presente\nSTATE=Ready\nUSER=WORKGROUP\\dev\nSID=S-1-5-21-1111111111-2222222222-3333333333-1001\nLOGON=S4U\nRUNLEVEL=Limited\nEXEC=C:\\php\\php.exe\nARGS=\"C:\\Users\\dev\\code\\PHPorto\\phporto\" serve\nTRIGGERS=MSFT_TaskBootTrigger\n";

/**
 * @return array{dir: string, provider: FakeProvider, calls: ArrayObject<int, array<string, mixed>>, store: Store, manager: Manager, probes: ArrayObject<int, string>}
 */
function asManager(string $probe = 'TASK=ausente', bool $uac = true, string $dispatchOutput = "[ OK ] feito\n", bool $serving = false): array
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phporto-asm-' . bin2hex(random_bytes(5));
    mkdir($dir);
    $provider = new FakeProvider();
    $calls = new ArrayObject();
    $probes = new ArrayObject();
    $store = new Store($dir, static fn (): string => '2026-11-07T10:00:00+00:00');
    $manager = new Manager(
        $store,
        new Task($dir, static function (string $script) use ($probe, $probes): string {
            $probes[] = $script;

            return $probe;
        }),
        static function (WinAction $a, array $params, int $t) use ($calls, $uac, $dispatchOutput): PsResult {
            $calls[] = $params;

            if (!$uac) {
                throw new RuntimeException('Você recusou o UAC; nada foi executado.');
            }

            return new PsResult($dispatchOutput, 0, false, 1);
        },
        new History(static fn (): ExecutionLogService => new ExecutionLogService($provider)),
        ASM_ROOT,
        ASM_PHP,
        new Identity(ASM_NAME, ASM_SID),
        static fn (int $port): bool => $serving,
    );

    return ['dir' => $dir, 'provider' => $provider, 'calls' => $calls, 'store' => $store, 'manager' => $manager, 'probes' => $probes];
}

function asClean(string $dir): void
{
    foreach (scandir($dir) ?: [] as $nome) {
        if (is_file($dir . DIRECTORY_SEPARATOR . $nome)) {
            unlink($dir . DIRECTORY_SEPARATOR . $nome);
        }
    }

    rmdir($dir);
}

it('ligar cria a tarefa uma vez, grava o estado próprio e registra sem segredo', function () {
    $a = asManager();
    $r = $a['manager']->enable();

    expect($r->ok)->toBeTrue()
        ->and($a['store']->enabled())->toBeTrue()
        ->and($a['calls']->getArrayCopy())->toBe([[
            'SubAction' => 'on', 'Sid' => ASM_SID, 'Program' => ASM_PHP, 'Script' => ASM_ROOT . '\\phporto',
        ]])
        ->and(array_map(static fn ($e): string => $e->command, $a['provider']->inserted))->toBe(['iniciar com o Windows ligado'])
        ->and(implode("\n", array_map(static fn ($e): string => $e->output, $a['provider']->inserted)))->not->toMatch('/senha|password|credential|token/i')
        ->and(implode("\n", $r->lines))->not->toMatch('/senha|password|credential/i');

    asClean($a['dir']);
});

it('ligar é idempotente: com a tarefa já igual ao esperado não pede UAC', function () {
    $a = asManager(ASM_TASK_OK);
    $r = $a['manager']->enable();

    expect($r->ok)->toBeTrue()
        ->and(count($a['calls']))->toBe(0)
        ->and($a['store']->enabled())->toBeTrue()
        ->and(implode(' ', $r->lines))->toContain('já estava ligado');

    asClean($a['dir']);
});

it('ligar com a tarefa diferente do esperado recria a tarefa', function (string $probe) {
    $a = asManager($probe);

    expect($a['manager']->enable()->ok)->toBeTrue()->and(count($a['calls']))->toBe(1);

    asClean($a['dir']);
})->with([
    'nível elevado' => [str_replace('Limited', 'Highest', ASM_TASK_OK)],
    'outro logon' => [str_replace('S4U', 'Password', ASM_TASK_OK)],
    'outro programa' => [str_replace('C:\\php\\php.exe', 'C:\\x\\php.exe', ASM_TASK_OK)],
    'sem disparo no boot' => [str_replace('MSFT_TaskBootTrigger', 'MSFT_TaskLogonTrigger', ASM_TASK_OK)],
    'leitura falhou' => ['TASK=erro'],
]);

it('ligar com o UAC recusado falha fechado, com o motivo, sem gravar o estado', function () {
    $a = asManager(uac: false);
    $r = $a['manager']->enable();

    expect($r->ok)->toBeFalse()
        ->and(implode(' ', $r->lines))->toContain('recusou o UAC')
        ->and($a['store']->enabled())->toBeFalse()
        ->and($a['provider']->inserted[0]->command)->toBe('iniciar com o Windows ligar falhou')
        ->and($a['provider']->inserted[0]->exitCode)->toBe(1);

    asClean($a['dir']);
});

it('ligar com a ação devolvendo erro ou sem confirmação falha', function (string $saida) {
    $a = asManager(dispatchOutput: $saida);

    expect($a['manager']->enable()->ok)->toBeFalse()->and($a['store']->enabled())->toBeFalse();

    asClean($a['dir']);
})->with(['erro' => ["[ ERROR ] sem acesso\n"], 'sem marca' => ["nada\n"]]);

it('desligar remove a tarefa e é idempotente', function () {
    $a = asManager(ASM_TASK_OK);
    $a['store']->set(true);

    expect($a['manager']->disable()->ok)->toBeTrue()
        ->and($a['store']->enabled())->toBeFalse()
        ->and($a['calls']->getArrayCopy())->toBe([['SubAction' => 'off']]);

    asClean($a['dir']);

    $b = asManager('TASK=ausente');
    $b['store']->set(true);
    $r = $b['manager']->disable();

    expect($r->ok)->toBeTrue()->and(count($b['calls']))->toBe(0)->and($b['store']->enabled())->toBeFalse()
        ->and(implode(' ', $r->lines))->toContain('Nada a fazer');

    asClean($b['dir']);
});

it('desligar não derruba o servidor em execução nem toca na rede', function () {
    $a = asManager(ASM_TASK_OK, serving: true);
    $network = new NetworkStore($a['dir'], static fn (): string => '2026-11-07T10:00:00+00:00');
    $network->writeForCli(true, '192.168.1.20', 4001, ['192.168.1.0/24']);
    $a['store']->set(true);

    $r = $a['manager']->disable();

    expect($r->ok)->toBeTrue()
        ->and($a['calls'][0])->toBe(['SubAction' => 'off'])
        ->and($network->load()->enabled)->toBeTrue()
        ->and(implode(' ', $r->lines))->toContain('não foi parado');

    asClean($a['dir']);
});

it('ligar o início com o Windows não liga a rede, e desligar a rede não desliga o início', function () {
    $a = asManager();
    $network = new NetworkStore($a['dir'], static fn (): string => '2026-11-07T10:00:00+00:00');

    $a['manager']->enable();
    expect($network->load()->enabled)->toBeFalse()->and(is_file($network->path()))->toBeFalse();

    $network->writeForCli(true, '192.168.1.20', 4001, ['192.168.1.0/24']);
    $network->disableFromWeb();
    expect($a['store']->enabled())->toBeTrue();

    asClean($a['dir']);
});

it('desligar com o UAC recusado mantém o estado ligado e diz o motivo', function () {
    $a = asManager(ASM_TASK_OK, uac: false);
    $a['store']->set(true);
    $r = $a['manager']->disable();

    expect($r->ok)->toBeFalse()->and($a['store']->enabled())->toBeTrue()->and(implode(' ', $r->lines))->toContain('recusou o UAC');

    asClean($a['dir']);
});

it('parar agora manda só o script e registra', function () {
    $a = asManager();
    $r = $a['manager']->stopNow();

    expect($r->ok)->toBeTrue()
        ->and($a['calls']->getArrayCopy())->toBe([['SubAction' => 'stop', 'Script' => ASM_ROOT . '\\phporto']])
        ->and($a['provider']->inserted[0]->command)->toBe('iniciar com o Windows parado agora');

    asClean($a['dir']);

    $b = asManager(uac: false);

    expect($b['manager']->stopNow()->ok)->toBeFalse();

    asClean($b['dir']);
});

it('o relatório é só leitura: lê a tarefa e o loopback e não pede UAC', function () {
    $a = asManager(ASM_TASK_OK, serving: true);
    $a['store']->set(true);
    $report = $a['manager']->report(4001);

    expect($report->enabled)->toBeTrue()
        ->and($report->task->present())->toBeTrue()
        ->and($report->task->state())->toBe('Ready')
        ->and($report->matches)->toBeTrue()
        ->and($report->serving)->toBeTrue()
        ->and(count($a['calls']))->toBe(0)
        ->and($a['probes'][0])->not->toMatch('/\b(New|Set|Remove|Register|Unregister|Start|Stop)-/');

    asClean($a['dir']);
});

it('a observação compara pelo SID do principal, não pelo nome da conta', function () {
    $nomeDiferente = TaskObservation::parse(str_replace('USER=WORKGROUP\\dev', 'USER=DESKTOP\\outro-nome', ASM_TASK_OK));
    $outroSid = TaskObservation::parse(str_replace('1001', '1002', ASM_TASK_OK));
    $semSid = TaskObservation::parse(str_replace("SID=S-1-5-21-1111111111-2222222222-3333333333-1001\n", "SID=\n", ASM_TASK_OK));
    $minusculo = TaskObservation::parse(ASM_TASK_OK);

    expect($nomeDiferente->matches(ASM_SID, ASM_PHP, ASM_ROOT . '\\phporto'))->toBeTrue()
        ->and($outroSid->matches(ASM_SID, ASM_PHP, ASM_ROOT . '\\phporto'))->toBeFalse()
        ->and($semSid->matches(ASM_SID, ASM_PHP, ASM_ROOT . '\\phporto'))->toBeFalse()
        ->and($minusculo->matches('nao-e-sid', ASM_PHP, ASM_ROOT . '\\phporto'))->toBeFalse()
        ->and(TaskObservation::unknown()->matches(ASM_SID, ASM_PHP, ASM_ROOT . '\\phporto'))->toBeFalse();
});

it('sem o SID da conta, ligar falha com o motivo e nada é criado', function () {
    $a = asManager();
    $r = (new ReflectionClass(Manager::class))->newInstanceArgs([
        $a['store'], new Task($a['dir'], static fn (): string => 'TASK=ausente'), static function () use ($a): PsResult {
            $a['calls'][] = [];

            return new PsResult("[ OK ] feito\\n", 0, false, 1);
        }, new History(static fn (): ExecutionLogService => new ExecutionLogService($a['provider'])), ASM_ROOT, ASM_PHP, null,
    ])->enable();

    expect($r->ok)->toBeFalse()
        ->and(implode(' ', $r->lines))->toContain('SID')->toContain('Nada foi criado')
        ->and(count($a['calls']))->toBe(0)
        ->and($a['store']->enabled())->toBeFalse();

    asClean($a['dir']);
});
it('o script da sonda do Agendador só lê', function () {
    expect(Task::script())->toContain('Get-ScheduledTask')->not->toMatch('/\b(New|Set|Remove|Register|Unregister|Start|Stop|Disable|Enable)-/');
});

/**
 * @param array<string, mixed> $a
 * @param list<string> $args
 * @return array{0: int, 1: string, 2: string}
 */
function asRun(array $a, array $args): array
{
    $in = fopen('php://memory', 'w+');
    $out = fopen('php://memory', 'w+');
    $err = fopen('php://memory', 'w+');
    $console = new Console($in, $out, $err);
    $code = (new Main($a['dir'], $console, null, null, null, static fn (): Manager => $a['manager']))->run($args);
    rewind($out);
    rewind($err);

    return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
}

it('php phporto autostart on e off saem 0, repetidos, e status só lê', function () {
    $a = asManager();

    [$on] = asRun($a, ['autostart', 'on']);
    [$off] = asRun($a, ['autostart', 'off']);
    [$off2] = asRun($a, ['autostart', 'off']);

    expect($on)->toBe(Status::OK)->and($off)->toBe(Status::OK)->and($off2)->toBe(Status::OK);

    $antes = (string) file_get_contents($a['store']->path());
    $calls = count($a['calls']);
    [$st, $out] = asRun($a, ['autostart', 'status']);

    expect($st)->toBe(Status::OK)
        ->and($out)->toContain('Início com o Windows: desligado')->toContain('Tarefa do Agendador: ausente')->toContain('Servidor responde no loopback: não')
        ->and((string) file_get_contents($a['store']->path()))->toBe($antes)
        ->and(count($a['calls']))->toBe($calls);

    asClean($a['dir']);
});

it('php phporto autostart sai 64 com argumento inválido, sem pedir senha nem confirmação', function (array $args) {
    $a = asManager();
    [$code, , $err] = asRun($a, $args);

    expect($code)->toBe(Status::USAGE)->and($err)->toContain('autostart on|off|status')->and(count($a['calls']))->toBe(0);

    asClean($a['dir']);
})->with([[['autostart']], [['autostart', 'foo']], [['autostart', 'on', '--password']], [['autostart', 'stop']]]);

it('php phporto autostart on com UAC recusado sai 1 e mostra o motivo no STDERR', function () {
    $a = asManager(uac: false);
    [$code, , $err] = asRun($a, ['autostart', 'on']);

    expect($code)->toBe(Status::FAILURE)->and($err)->toContain('recusou o UAC');

    asClean($a['dir']);
});

it('nenhum histórico nem log do fluxo carrega senha, token ou credencial', function () {
    $a = asManager();
    asRun($a, ['autostart', 'on']);
    asRun($a, ['autostart', 'off']);
    $a['manager']->stopNow();

    $tudo = implode("\n", array_map(static fn ($e): string => $e->command . ' ' . $e->output, $a['provider']->inserted)) . "\n" . (string) file_get_contents($a['store']->path());

    expect($a['provider']->inserted)->not->toBe([])
        ->and($tudo)->not->toMatch('/senha|password|credential|secret|token/i');

    asClean($a['dir']);
});
