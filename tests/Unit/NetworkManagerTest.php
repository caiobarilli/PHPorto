<?php

declare(strict_types=1);

use App\Network\Firewall;
use App\Network\History;
use App\Network\Ipv4;
use App\Network\Machine;
use App\Network\Manager;
use App\Network\ManagerResult;
use App\Network\Observation;
use App\Network\Plan;
use App\Network\Probe;
use App\Network\State;
use App\Network\Store;
use App\Services\ExecutionLogService;
use App\Win\PsResult;
use App\Win\WinAction;
use Tests\Fakes\FakeProvider;

const NET_RULE_OK = 'RULE=True|Inbound|Allow|Private|TCP|4001|192.168.1.20|192.168.1.0/255.255.255.0';

/**
 * @param array<string, mixed> $over
 */
function netManagerState(array $over = []): State
{
    $state = State::fromArray(array_replace([
        'enabled' => true,
        'bind_ip' => '192.168.1.20',
        'port' => 4001,
        'allow' => ['192.168.1.0/24'],
        'updated_at' => '2026-11-07T10:00:00+00:00',
    ], $over));
    expect($state)->toBeInstanceOf(State::class);

    return $state;
}

function netObs(string $profile = 'Private', string $rules = "RULES=1\n" . NET_RULE_OK): Observation
{
    return Observation::parse("PROFILE={$profile}\n{$rules}\n");
}

/**
 * @return array{0: Manager, 1: FakeProvider, 2: string, 3: ArrayObject<int, array<string, mixed>>}
 */
function netManager(
    string $profile = 'Private',
    string $rules = 'RULES=0',
    bool $fwOk = true,
    string $fwMessage = 'Você recusou o UAC; nada foi executado.',
    array $ips = ['192.168.1.20', '127.0.0.1'],
): array {
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phporto-netmgr-' . bin2hex(random_bytes(5));
    mkdir($dir);
    $provider = new FakeProvider();
    $calls = new ArrayObject();
    $store = new Store($dir, static fn (): string => '2026-11-07T10:00:00+00:00');
    $manager = new Manager(
        $store,
        new Machine(static fn (): array => ['eth' => ['unicast' => array_map(static fn (string $ip): array => ['family' => 2, 'address' => $ip], $ips)]]),
        new Probe($dir, static fn (string $script): string => "PROFILE={$profile}\n{$rules}\n"),
        new Firewall(static function (WinAction $a, array $params, int $t) use ($calls, $fwOk, $fwMessage): PsResult {
            $calls[] = $params;

            if (!$fwOk) {
                throw new RuntimeException($fwMessage);
            }

            return new PsResult("[ OK ] feito\n", 0, false, 1);
        }, 'C:\\php\\php.exe'),
        new History(static fn (): ExecutionLogService => new ExecutionLogService($provider)),
        static fn (string $ip, int $port): bool => false,
    );

    return [$manager, $provider, $dir, $calls];
}

function netClean(string $dir): void
{
    foreach (scandir($dir) ?: [] as $nome) {
        if ($nome !== '.' && $nome !== '..') {
            @unlink($dir . DIRECTORY_SEPARATOR . $nome);
        }
    }

    rmdir($dir);
}

it('o listener só sobe com estado válido, ligado, IP da máquina, perfil Private e regra igual ao estado', function () {
    $ok = Plan::decide(netManagerState(), true, netObs());

    expect($ok->start)->toBeTrue()->and($ok->reason)->toBeNull();
});

it('fail-closed: cada falha de pré-condição fecha o listener e diz o motivo', function (State $state, bool $owns, Observation $obs, string $motivo) {
    $plan = Plan::decide($state, $owns, $obs);

    expect($plan->start)->toBeFalse()->and($plan->reason)->toContain($motivo);
})->with([
    'estado ausente' => [State::closed(), true, fn () => netObs(), 'ausente ou inválido'],
    'desligada' => [fn () => netManagerState(['enabled' => false]), true, fn () => netObs(), 'desligada'],
    'IP sumiu' => [fn () => netManagerState(), false, fn () => netObs(), 'não pertence mais'],
    'perfil Public' => [fn () => netManagerState(), true, fn () => netObs('Public'), 'precisa ser Private'],
    'perfil de domínio' => [fn () => netManagerState(), true, fn () => netObs('DomainAuthenticated'), 'precisa ser Private'],
    'perfil ilegível' => [fn () => netManagerState(), true, fn () => Observation::parse("PROFILE=erro\nRULES=1\n" . NET_RULE_OK), 'perfil'],
    'regra ausente' => [fn () => netManagerState(), true, fn () => netObs('Private', 'RULES=0'), 'não existe'],
    'regra ilegível' => [fn () => netManagerState(), true, fn () => netObs('Private', 'RULES=erro'), 'ler a regra'],
    'duas regras' => [fn () => netManagerState(), true, fn () => netObs('Private', "RULES=2\n" . NET_RULE_OK . "\n" . NET_RULE_OK), 'não bate'],
    'regra de outro perfil' => [fn () => netManagerState(), true, fn () => netObs('Private', "RULES=1\nRULE=True|Inbound|Allow|Any|TCP|4001|192.168.1.20|192.168.1.0/255.255.255.0"), 'não bate'],
    'regra de outra porta' => [fn () => netManagerState(), true, fn () => netObs('Private', "RULES=1\nRULE=True|Inbound|Allow|Private|TCP|4002|192.168.1.20|192.168.1.0/255.255.255.0"), 'não bate'],
    'regra de outro IP local' => [fn () => netManagerState(), true, fn () => netObs('Private', "RULES=1\nRULE=True|Inbound|Allow|Private|TCP|4001|192.168.1.21|192.168.1.0/255.255.255.0"), 'não bate'],
    'regra aberta a qualquer remoto' => [fn () => netManagerState(), true, fn () => netObs('Private', "RULES=1\nRULE=True|Inbound|Allow|Private|TCP|4001|192.168.1.20|Any"), 'não bate'],
    'regra com remoto mais largo' => [fn () => netManagerState(), true, fn () => netObs('Private', "RULES=1\nRULE=True|Inbound|Allow|Private|TCP|4001|192.168.1.20|192.168.0.0/255.255.0.0"), 'não bate'],
    'regra desabilitada' => [fn () => netManagerState(), true, fn () => netObs('Private', "RULES=1\nRULE=False|Inbound|Allow|Private|TCP|4001|192.168.1.20|192.168.1.0/255.255.255.0"), 'não bate'],
    'regra bloqueante' => [fn () => netManagerState(), true, fn () => netObs('Private', "RULES=1\nRULE=True|Inbound|Block|Private|TCP|4001|192.168.1.20|192.168.1.0/255.255.255.0"), 'não bate'],
]);

it('a regra aceita máscara pontilhada, prefixo e IP único com a mesma allowlist', function (string $remote) {
    $state = netManagerState(['allow' => ['192.168.1.0/24', '10.0.0.8']]);
    $obs = netObs('Private', "RULES=1\nRULE=True|Inbound|Allow|Private|TCP|4001|192.168.1.20|" . $remote);

    expect($obs->rule($state))->toBe(Observation::RULE_OK);
})->with([
    ['192.168.1.0/255.255.255.0,10.0.0.8'],
    ['10.0.0.8/255.255.255.255,192.168.1.0/255.255.255.0'],
    ['192.168.1.0/24,10.0.0.8'],
]);

it('normaliza máscara pontilhada só quando ela é contígua', function () {
    expect(Ipv4::normalizeRule('192.168.1.0/255.255.255.0'))->toBe('192.168.1.0/24')
        ->and(Ipv4::normalizeRule('10.0.0.0/255.0.0.0'))->toBe('10.0.0.0/8')
        ->and(Ipv4::normalizeRule('10.0.0.8/255.255.255.255'))->toBe('10.0.0.8/32')
        ->and(Ipv4::normalizeRule('10.0.0.0/255.0.255.0'))->toBe('10.0.0.0/255.0.255.0')
        ->and(Ipv4::bounds('10.0.0.0/255.0.255.0'))->toBeNull()
        ->and(Ipv4::bounds('8.8.8.0/255.255.255.0'))->toBeNull();
});

it('a máquina lista só IPv4 e reconhece o próprio IP', function () {
    $machine = new Machine(static fn (): array => [
        'eth' => ['unicast' => [['family' => 2, 'address' => '192.168.1.20'], ['family' => 23, 'address' => 'fe80::1']]],
        'lo' => ['unicast' => [['family' => 2, 'address' => '127.0.0.1']]],
        'quebrada' => 'x',
    ]);

    expect($machine->ipv4())->toBe(['192.168.1.20', '127.0.0.1'])
        ->and($machine->privateIpv4())->toBe(['192.168.1.20'])
        ->and($machine->owns('192.168.1.20'))->toBeTrue()
        ->and($machine->owns('192.168.1.21'))->toBeFalse();
});

it('a sonda trata falha de leitura como desconhecido e não roda com IP que não é privado', function () {
    $chamou = false;
    $probe = new Probe(sys_get_temp_dir(), static function () use (&$chamou): ?string {
        $chamou = true;

        return null;
    });

    expect($probe->observe('192.168.1.20')->profile)->toBeNull()
        ->and($probe->observe('192.168.1.20')->ruleCount)->toBeNull()
        ->and($chamou)->toBeTrue();

    $chamou = false;

    expect($probe->observe('8.8.8.8')->ruleCount)->toBeNull()->and($chamou)->toBeFalse();
});

it('o script da sonda só lê: nenhum cmdlet que muda estado', function () {
    $script = Probe::script('192.168.1.20');

    expect($script)->toContain('Get-NetFirewallRule')->toContain('Get-NetConnectionProfile')
        ->and($script)->not->toMatch('/\b(New|Set|Remove|Disable|Enable)-/')
        ->and(Probe::script(null))->not->toContain('Get-NetConnectionProfile');
});

it('network on grava o estado, cria a regra e registra no histórico sem segredo', function () {
    [$manager, $provider, $dir, $calls] = netManager();
    $r = $manager->enable('192.168.1.20', 4001, ['192.168.1.0/24']);
    $store = new Store($dir);

    expect($r->ok())->toBeTrue()
        ->and($store->load()->enabled)->toBeTrue()
        ->and($calls)->toHaveCount(1)
        ->and($calls[0])->toBe([
            'SubAction' => 'add', 'BindIp' => '192.168.1.20', 'Port' => 4001, 'Allow' => '192.168.1.0/24', 'Program' => 'C:\\php\\php.exe',
        ])
        ->and(array_map(static fn ($e): string => $e->command, $provider->inserted))->toBe(['rede ligada', 'rede regra criada'])
        ->and(implode("\n", array_map(static fn ($e): string => $e->output, $provider->inserted)))->not->toMatch('/token|senha|password/i');

    netClean($dir);
});

it('network on recusa IP público, IP que não é da máquina, porta, allow vazia e perfil Public sem gravar nada', function (array $args, string $profile, string $trecho) {
    [$manager, , $dir, $calls] = netManager(profile: $profile);
    $r = $manager->enable(...$args);

    expect($r->ok())->toBeFalse()
        ->and(implode(' ', $r->lines))->toContain($trecho)
        ->and(is_file((new Store($dir))->path()))->toBeFalse()
        ->and(count($calls))->toBe(0);

    netClean($dir);
})->with([
    'bind público' => [['8.8.8.8', 4001, ['192.168.1.0/24']], 'Private', 'IPv4 privado'],
    'bind qualquer' => [['0.0.0.0', 4001, ['192.168.1.0/24']], 'Private', 'IPv4 privado'],
    'bind loopback' => [['127.0.0.1', 4001, ['192.168.1.0/24']], 'Private', 'IPv4 privado'],
    'bind de outra máquina' => [['192.168.1.99', 4001, ['192.168.1.0/24']], 'Private', 'nenhuma interface'],
    'porta zero' => [['192.168.1.20', 0, ['192.168.1.0/24']], 'Private', 'porta'],
    'allow vazia' => [['192.168.1.20', 4001, []], 'Private', 'não pode ser vazia'],
    'allow pública' => [['192.168.1.20', 4001, ['8.8.8.8']], 'Private', 'inválida'],
    'allow qualquer' => [['192.168.1.20', 4001, ['0.0.0.0/0']], 'Private', 'inválida'],
    'perfil Public' => [['192.168.1.20', 4001, ['192.168.1.0/24']], 'Public', 'perfil Public'],
]);

it('se a regra não for criada, a rede volta a ficar desligada e o motivo vai para o histórico', function () {
    [$manager, $provider, $dir] = netManager(fwOk: false, fwMessage: 'Você recusou o UAC; nada foi executado.');
    $r = $manager->enable('192.168.1.20', 4001, ['192.168.1.0/24']);

    expect($r->ok())->toBeFalse()
        ->and($r->closed)->toBeTrue()
        ->and(implode(' ', $r->lines))->toContain('recusou o UAC')
        ->and((new Store($dir))->load()->enabled)->toBeFalse()
        ->and($provider->inserted[0]->command)->toBe('rede ligar falhou')
        ->and($provider->inserted[0]->exitCode)->toBe(1);

    netClean($dir);
});

it('network off desliga o estado, remove a regra e é idempotente', function () {
    [$manager, $provider, $dir, $calls] = netManager();
    $manager->enable('192.168.1.20', 4001, ['192.168.1.0/24']);
    $r = $manager->disable();

    expect($r->ok())->toBeTrue()
        ->and($r->closed)->toBeTrue()
        ->and((new Store($dir))->load()->enabled)->toBeFalse()
        ->and($calls[1])->toBe(['SubAction' => 'remove'])
        ->and(array_map(static fn ($e): string => $e->command, $provider->inserted))->toContain('rede desligada', 'rede regra removida');

    $again = $manager->disable();

    expect($again->ok())->toBeTrue()->and((new Store($dir))->load()->enabled)->toBeFalse();

    netClean($dir);
});

it('network off sem rede e sem regra legível não pede UAC à toa', function () {
    [$manager, , $dir, $calls] = netManager(rules: 'RULES=0');
    $r = $manager->disable();

    expect($r->ok())->toBeTrue()->and(count($calls))->toBe(0)->and(implode(' ', $r->lines))->toContain('Nada a fazer');

    netClean($dir);
});

it('network off com UAC recusado deixa o estado fechado e avisa que a regra ficou', function () {
    [$manager, $provider, $dir] = netManager(rules: "RULES=1\n" . NET_RULE_OK, fwOk: false);
    (new Store($dir, static fn (): string => '2026-11-07T10:00:00+00:00'))->writeForCli(true, '192.168.1.20', 4001, ['192.168.1.0/24']);

    $r = $manager->disable();

    expect($r->kind)->toBe(ManagerResult::FAILURE)
        ->and($r->closed)->toBeTrue()
        ->and((new Store($dir))->load()->enabled)->toBeFalse()
        ->and(implode(' ', $r->lines))->toContain('ficou para remover')
        ->and(array_map(static fn ($e): string => $e->command, $provider->inserted))->toContain('rede desligada', 'rede regra não removida');

    netClean($dir);
});

it('o relatório junta estado, IP, perfil, regra e se o listener responde', function () {
    [$manager, , $dir] = netManager(rules: "RULES=1\n" . NET_RULE_OK);
    (new Store($dir, static fn (): string => '2026-11-07T10:00:00+00:00'))->writeForCli(true, '192.168.1.20', 4001, ['192.168.1.0/24']);

    $report = $manager->report();

    expect($report->state->enabled)->toBeTrue()
        ->and($report->ownsIp)->toBeTrue()
        ->and($report->observation->profileIsPrivate())->toBeTrue()
        ->and($report->plan->start)->toBeTrue()
        ->and($report->listenerResponds)->toBeFalse();

    netClean($dir);
});

it('o relatório com a rede desligada não sonda o listener', function () {
    [$manager, , $dir] = netManager();
    $report = $manager->report();

    expect($report->state->enabled)->toBeFalse()->and($report->listenerResponds)->toBeNull()->and($report->ownsIp)->toBeNull();

    netClean($dir);
});

it('o resultado da ação de firewall só é ok com [ OK ], saída 0 e sem [ ERROR ]', function (PsResult $r, bool $ok) {
    expect(Firewall::interpret($r)->ok)->toBe($ok);
})->with([
    'ok' => [new PsResult("[ OK ] feito\n", 0, false, 1), true],
    'erro' => [new PsResult("[ ERROR ] falhou\n", 0, false, 1), false],
    'ok e erro' => [new PsResult("[ OK ] a\n[ ERROR ] b\n", 0, false, 1), false],
    'saída 1' => [new PsResult("[ OK ] a\n", 1, false, 1), false],
    'sem marca' => [new PsResult("nada\n", 0, false, 1), false],
    'prazo' => [new PsResult("[ OK ] a\n", null, true, 1), false],
]);

it('a regra de firewall nunca recebe estado sem IP nem parâmetro inválido', function () {
    $fw = new Firewall(static fn (WinAction $a, array $p, int $t): PsResult => new PsResult("[ OK ] x\n", 0, false, 1), 'C:\\Windows\\notepad.exe');

    expect($fw->add(netManagerState())->ok)->toBeFalse()
        ->and($fw->add(State::closed())->ok)->toBeFalse();
});
