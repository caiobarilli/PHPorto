<?php

declare(strict_types=1);

use App\Autostart\History;
use App\Autostart\Identity;
use App\Autostart\Level;
use App\Autostart\Manager;
use App\Autostart\Store;
use App\Autostart\Task;
use App\Autostart\TaskObservation;
use App\Autostart\TaskXml;
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
const ASM_TASK_OK = "TASK=presente\nSTATE=Ready\nUSER=WORKGROUP\\dev\nSID=S-1-5-21-1111111111-2222222222-3333333333-1001\nLOGON=Password\nRUNLEVEL=Limited\nEXEC=C:\\php\\php.exe\nARGS=\"C:\\Users\\dev\\code\\PHPorto\\phporto\" serve\nTRIGGERS=MSFT_TaskBootTrigger\n";

/**
 * @return array{dir: string, provider: FakeProvider, calls: ArrayObject<int, array<string, mixed>>, store: Store, manager: Manager, probes: ArrayObject<int, string>}
 */
function asManager(string $probe = 'TASK=ausente', bool $uac = true, string $dispatchOutput = "[ OK ] feito\n", bool $serving = false, ?string $level = 'médio'): array
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
        static fn (): ?string => $level,
        $dir,
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

it('ligar gera o XML para importar, não cria a tarefa, não pede UAC e não liga o estado', function () {
    $a = asManager();
    $r = $a['manager']->enable();
    $xml = $a['dir'] . DIRECTORY_SEPARATOR . TaskXml::FILE;

    expect($r->ok)->toBeTrue()
        ->and(count($a['calls']))->toBe(0)
        ->and($a['store']->enabled())->toBeFalse()
        ->and(is_file($xml))->toBeTrue()
        ->and($a['manager']->xmlPath())->toBe($xml)
        ->and(implode(' ', $r->lines))->toContain('ainda NÃO está criada')->toContain('taskschd.msc')->toContain('nunca pelo PHPorto')
        ->and(array_map(static fn ($e): string => $e->command, $a['provider']->inserted))->toBe(['iniciar com o Windows XML da tarefa gerado'])
        ->and(implode(' ', array_map(static fn ($e): string => $e->output, $a['provider']->inserted)))->not->toMatch('/senha|password|credential|token/i');

    $doc = new DOMDocument();
    expect($doc->loadXML((string) file_get_contents($xml)))->toBeTrue();

    asClean($a['dir']);
});

it('o XML da tarefa: SID, logon com senha, nível limitado, disparo no boot, php.exe e phporto serve, sem nenhuma senha', function () {
    $texto = TaskXml::decode(TaskXml::build(ASM_SID, ASM_PHP, ASM_ROOT . DIRECTORY_SEPARATOR . 'phporto'));
    $xml = simplexml_load_string(str_replace('encoding="UTF-16"', 'encoding="UTF-8"', $texto));

    expect($xml)->not->toBeFalse();

    $ns = $xml->children('http://schemas.microsoft.com/windows/2004/02/mit/task');
    $principal = $ns->Principals->Principal;
    $exec = $ns->Actions->Exec;

    expect((string) $principal->UserId)->toBe(ASM_SID)
        ->and((string) $principal->LogonType)->toBe('Password')
        ->and((string) $principal->RunLevel)->toBe('LeastPrivilege')
        ->and(isset($ns->Triggers->BootTrigger))->toBeTrue()
        ->and((string) $exec->Command)->toBe(ASM_PHP)
        ->and((string) $exec->Arguments)->toBe('"' . ASM_ROOT . DIRECTORY_SEPARATOR . 'phporto" serve')
        ->and((string) $exec->WorkingDirectory)->toBe(ASM_ROOT)
        ->and((string) $ns->Settings->MultipleInstancesPolicy)->toBe('IgnoreNew')
        ->and((string) $ns->Settings->RestartOnFailure->Count)->toBe('3')
        ->and($texto)->not->toContain('S4U')->not->toContain('Highest')->not->toMatch('/<Password>/i');
});

it('o XML escapa o que vem de caminho e começa com BOM UTF-16', function () {
    $bruto = TaskXml::build(ASM_SID, 'C:' . chr(92) . 'a&b' . chr(92) . 'php.exe', 'C:' . chr(92) . 'x<y' . chr(92) . 'phporto');
    $texto = TaskXml::decode($bruto);

    expect(substr($bruto, 0, 2))->toBe("\xFF\xFE")
        ->and($texto)->toContain('a&amp;b')->toContain('x&lt;y')->not->toContain('a&b' . chr(92));
});

it('ligar é idempotente: com a tarefa já igual ao esperado não pede UAC nem gera XML', function () {
    $a = asManager(ASM_TASK_OK);
    $r = $a['manager']->enable();

    expect($r->ok)->toBeTrue()
        ->and(count($a['calls']))->toBe(0)
        ->and(is_file($a['manager']->xmlPath()))->toBeFalse()
        ->and($a['store']->enabled())->toBeTrue()
        ->and(implode(' ', $r->lines))->toContain('já estava ligado');

    asClean($a['dir']);
});

it('ligar com a tarefa diferente (S4U, elevada, outro php, sem boot) a remove com UAC antes de gerar o XML', function (string $probe) {
    $a = asManager($probe);
    $a['store']->set(true);
    $r = $a['manager']->enable();

    expect($r->ok)->toBeTrue()
        ->and($a['calls']->getArrayCopy())->toBe([['SubAction' => 'off']])
        ->and($a['store']->enabled())->toBeFalse()
        ->and(is_file($a['manager']->xmlPath()))->toBeTrue()
        ->and(implode(' ', $r->lines))->toContain('foi removida para ser substituída');

    asClean($a['dir']);
})->with([
    'logon S4U (a antiga)' => [str_replace('LOGON=Password', 'LOGON=S4U', ASM_TASK_OK)],
    'nível elevado' => [str_replace('Limited', 'Highest', ASM_TASK_OK)],
    'outro programa' => [str_replace('C:\\php\\php.exe', 'C:\\x\\php.exe', ASM_TASK_OK)],
    'sem disparo no boot' => [str_replace('MSFT_TaskBootTrigger', 'MSFT_TaskLogonTrigger', ASM_TASK_OK)],
    'outro SID' => [str_replace('1001', '1002', ASM_TASK_OK)],
]);

it('ligar sem conseguir ler a tarefa só gera o XML', function () {
    $a = asManager('TASK=erro');

    expect($a['manager']->enable()->ok)->toBeTrue()->and(count($a['calls']))->toBe(0)->and(is_file($a['manager']->xmlPath()))->toBeTrue();

    asClean($a['dir']);
});

it('ligar com o UAC recusado ao remover a tarefa antiga falha fechado, sem XML e sem mexer no estado', function () {
    $a = asManager(str_replace('LOGON=Password', 'LOGON=S4U', ASM_TASK_OK), uac: false);
    $a['store']->set(true);
    $r = $a['manager']->enable();

    expect($r->ok)->toBeFalse()
        ->and(implode(' ', $r->lines))->toContain('recusou o UAC')->toContain('S4U')
        ->and($a['store']->enabled())->toBeTrue()
        ->and(is_file($a['manager']->xmlPath()))->toBeFalse()
        ->and($a['provider']->inserted[0]->command)->toBe('iniciar com o Windows substituir falhou')
        ->and($a['provider']->inserted[0]->exitCode)->toBe(1);

    asClean($a['dir']);
});

it('ligar com a remoção devolvendo erro ou sem confirmação falha sem gerar XML', function (string $saida) {
    $a = asManager(str_replace('LOGON=Password', 'LOGON=S4U', ASM_TASK_OK), dispatchOutput: $saida);

    expect($a['manager']->enable()->ok)->toBeFalse()->and(is_file($a['manager']->xmlPath()))->toBeFalse();

    asClean($a['dir']);
})->with(['erro' => ["[ ERROR ] sem acesso\n"], 'sem marca' => ["nada\n"]]);

it('ligar sem SID legível ou com caminho fora do formato falha e nada é criado', function () {
    $a = asManager();
    $sem = new Manager($a['store'], new Task($a['dir'], static fn (): string => 'TASK=ausente'), static fn (): PsResult => new PsResult('', 0, false, 1), new History(static fn (): ExecutionLogService => new ExecutionLogService($a['provider'])), ASM_ROOT, ASM_PHP, null, null, null, $a['dir']);
    $fora = new Manager($a['store'], new Task($a['dir'], static fn (): string => 'TASK=ausente'), static fn (): PsResult => new PsResult('', 0, false, 1), new History(static fn (): ExecutionLogService => new ExecutionLogService($a['provider'])), ASM_ROOT, 'C:' . chr(92) . 'x' . chr(92) . 'notepad.exe', new Identity(ASM_NAME, ASM_SID), null, null, $a['dir']);

    expect($sem->enable()->ok)->toBeFalse()->and(implode(' ', $sem->enable()->lines))->toContain('SID')
        ->and($fora->enable()->ok)->toBeFalse()
        ->and(is_file($a['manager']->xmlPath()))->toBeFalse();

    asClean($a['dir']);
});

it('reiniciar pela tarefa pede a ação restart com o script, registra, e recusa sem tarefa', function () {
    $a = asManager(ASM_TASK_OK);
    $r = $a['manager']->restart();

    expect($r->ok)->toBeTrue()
        ->and($a['calls']->getArrayCopy())->toBe([['SubAction' => 'restart', 'Script' => ASM_ROOT . '\\phporto']])
        ->and($a['provider']->inserted[0]->command)->toBe('iniciar com o Windows reiniciado pela tarefa')
        ->and(implode(' ', $r->lines))->toContain('fora do ar');

    asClean($a['dir']);

    $b = asManager('TASK=ausente');
    $r2 = $b['manager']->restart();

    expect($r2->ok)->toBeFalse()->and(count($b['calls']))->toBe(0)->and(implode(' ', $r2->lines))->toContain('Não há tarefa');

    asClean($b['dir']);

    $c = asManager(ASM_TASK_OK, uac: false);

    expect($c['manager']->restart()->ok)->toBeFalse();

    asClean($c['dir']);
});

it('o nível de integridade vem do SID S-1-16 do whoami /groups', function (string $saida, ?string $esperado, bool $elevado) {
    expect(Level::parse($saida))->toBe($esperado)->and(Level::elevated($esperado))->toBe($elevado);
})->with([
    'médio' => ['"Mandatory Label\\Medium Mandatory Level","Label","S-1-16-8192",""', 'médio', false],
    'médio em pt-BR' => ['"Rótulo Obrigatório\\Nível Obrigatório Médio","Rótulo","S-1-16-8192",""', 'médio', false],
    'alto' => ['"x","Label","S-1-16-12288",""', 'alto', true],
    'sistema' => ['"x","Label","S-1-16-16384",""', 'sistema', true],
    'baixo' => ['"x","Label","S-1-16-4096",""', 'baixo', false],
    'sem rótulo' => ['"Todos","Well-known group","S-1-1-0",""', null, false],
]);

it('o nível lido da saída injetada e sem leitura', function () {
    expect(Level::current(static fn (): string => '"x","Label","S-1-16-12288",""'))->toBe('alto')
        ->and(Level::current(static fn (): ?string => null))->toBeNull();
});

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
    $a['store']->set(true);
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
        ->and($report->level)->toBe('médio')
        ->and($report->task->logon())->toBe('Password')
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

    [$on, $onOut] = asRun($a, ['autostart', 'on']);
    [$off] = asRun($a, ['autostart', 'off']);
    [$off2] = asRun($a, ['autostart', 'off']);

    expect($on)->toBe(Status::OK)->and($onOut)->toContain('ainda NÃO está criada')->and($off)->toBe(Status::OK)->and($off2)->toBe(Status::OK);

    $antes = (string) file_get_contents($a['store']->path());
    $calls = count($a['calls']);
    [$st, $out] = asRun($a, ['autostart', 'status']);

    expect($st)->toBe(Status::OK)
        ->and($out)->toContain('Início com o Windows: desligado')->toContain('Tarefa do Agendador: ausente')->toContain('Nível do servidor (este processo): médio')->toContain('Servidor responde no loopback: não')
        ->and((string) file_get_contents($a['store']->path()))->toBe($antes)
        ->and(count($a['calls']))->toBe($calls);

    asClean($a['dir']);
});

it('php phporto autostart sai 64 com argumento inválido, sem pedir senha nem confirmação', function (array $args) {
    $a = asManager();
    [$code, , $err] = asRun($a, $args);

    expect($code)->toBe(Status::USAGE)->and($err)->toContain('autostart on|off|status|restart')->and(count($a['calls']))->toBe(0);

    asClean($a['dir']);
})->with([[['autostart']], [['autostart', 'foo']], [['autostart', 'on', '--password']], [['autostart', 'stop']], [['autostart', 'restart', 'x']]]);

it('php phporto autostart on com UAC recusado ao trocar a tarefa antiga sai 1 e mostra o motivo no STDERR', function () {
    $a = asManager(str_replace('LOGON=Password', 'LOGON=S4U', ASM_TASK_OK), uac: false);
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
