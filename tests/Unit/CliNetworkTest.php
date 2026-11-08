<?php

declare(strict_types=1);

use App\Cli\Console;
use App\Cli\Main;
use App\Cli\Serve;
use App\Cli\Status;
use App\Cli\Supervisor;
use App\Network\Firewall;
use App\Network\History;
use App\Network\Launcher;
use App\Network\Machine;
use App\Network\Manager;
use App\Network\Probe;
use App\Network\Store;
use App\Services\ExecutionLogService;
use App\Win\PsResult;
use App\Win\WinAction;
use Tests\Fakes\FakeProvider;

const CLI_NET_RULE = 'RULE=True|Inbound|Allow|Private|TCP|4001|192.168.1.20|192.168.1.0/255.255.255.0';

/**
 * @param list<string> $ips
 * @return array{dir: string, provider: FakeProvider, calls: ArrayObject<int, array<string, mixed>>, store: Store, machine: Machine, probe: Probe, history: History, manager: Manager}
 */
function cliNet(string $profile = 'Private', string $rules = 'RULES=0', array $ips = ['192.168.1.20', '127.0.0.1'], bool $uac = true, ?string $env = "PHPORTO_AUTH_TOKEN=x\n"): array
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phporto-clinet-' . bin2hex(random_bytes(5));
    mkdir($dir);
    mkdir($dir . DIRECTORY_SEPARATOR . 'files');

    if ($env !== null) {
        file_put_contents($dir . DIRECTORY_SEPARATOR . '.env', $env);
    }

    $provider = new FakeProvider();
    $calls = new ArrayObject();
    $store = new Store($dir . DIRECTORY_SEPARATOR . 'files', static fn (): string => '2026-11-07T10:00:00+00:00');
    $machine = new Machine(static fn (): array => ['eth' => ['unicast' => array_map(static fn (string $ip): array => ['family' => 2, 'address' => $ip], $ips)]]);
    $probe = new Probe($dir, static fn (string $script): string => "PROFILE={$profile}\n{$rules}\n");
    $history = new History(static fn (): ExecutionLogService => new ExecutionLogService($provider));
    $firewall = new Firewall(static function (WinAction $a, array $params, int $t) use ($calls, $uac): PsResult {
        $calls[] = $params;

        if (!$uac) {
            throw new RuntimeException('Você recusou o UAC; nada foi executado.');
        }

        return new PsResult("[ OK ] feito\n", 0, false, 1);
    }, 'C:\\php\\php.exe');

    return [
        'dir' => $dir, 'provider' => $provider, 'calls' => $calls, 'store' => $store, 'machine' => $machine,
        'probe' => $probe, 'history' => $history,
        'manager' => new Manager($store, $machine, $probe, $firewall, $history, static fn (string $ip, int $port): bool => false),
    ];
}

function cliNetClean(string $dir): void
{
    foreach (scandir($dir . DIRECTORY_SEPARATOR . 'files') ?: [] as $nome) {
        $arquivo = $dir . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . $nome;

        if (is_file($arquivo)) {
            unlink($arquivo);
        }
    }

    @rmdir($dir . DIRECTORY_SEPARATOR . 'files');
    if (is_file($dir . DIRECTORY_SEPARATOR . '.env')) {
        unlink($dir . DIRECTORY_SEPARATOR . '.env');
    }

    @rmdir($dir);
}

/**
 * @param array<string, mixed> $net
 * @param list<string> $args
 * @return array{0: int, 1: string, 2: string}
 */
function cliNetRun(array $net, array $args, string $input = ''): array
{
    $in = fopen('php://memory', 'w+');
    $out = fopen('php://memory', 'w+');
    $err = fopen('php://memory', 'w+');
    fwrite($in, $input);
    rewind($in);
    $console = new Console($in, $out, $err);
    $code = (new Main($net['dir'], $console, null, static fn (): Manager => $net['manager'], $net['machine']))->run($args);
    rewind($out);
    rewind($err);

    return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
}

it('network on percorre as perguntas, mostra o risco e só liga com sim', function () {
    $net = cliNet();
    [$code, $out] = cliNetRun($net, ['network', 'on'], "192.168.1.20\n4001\n192.168.1.0/24\nsim\n");

    expect($code)->toBe(Status::OK)
        ->and($out)->toContain('192.168.1.20')->toContain('RISCO')->toContain('sem TLS')->toContain('Digite "sim"')
        ->and($net['store']->load()->enabled)->toBeTrue()
        ->and($net['store']->load()->allow)->toBe(['192.168.1.0/24'])
        ->and(count($net['calls']))->toBe(1);

    cliNetClean($net['dir']);
});

it('network on com um único IP privado aceita o padrão com Enter e usa a porta do .env', function () {
    $net = cliNet(env: "PHPORTO_PORT=4555\n");
    [$code] = cliNetRun($net, ['network', 'on'], "\n\n10.0.0.8\nsim\n");

    expect($code)->toBe(Status::OK)
        ->and($net['store']->load()->bindIp)->toBe('192.168.1.20')
        ->and($net['store']->load()->port)->toBe(4555)
        ->and($net['store']->load()->allow)->toBe(['10.0.0.8']);

    cliNetClean($net['dir']);
});

it('network on sem a palavra sim não muda nada, venha o que vier', function (string $resposta) {
    $net = cliNet();
    [$code, $out] = cliNetRun($net, ['network', 'on'], "192.168.1.20\n4001\n192.168.1.0/24\n" . $resposta);

    expect($code)->toBe(Status::FAILURE)
        ->and($out)->toContain('Nada mudou')
        ->and(is_file($net['store']->path()))->toBeFalse()
        ->and(count($net['calls']))->toBe(0);

    cliNetClean($net['dir']);
})->with(['vazio' => ["\n"], 's' => ["s\n"], 'yes' => ["yes\n"], 'sim com lixo' => ["sim agora\n"], 'sem entrada' => ['']]);

it('não existe flag nem atalho para pular a confirmação', function (array $args) {
    $net = cliNet();
    [$code] = cliNetRun($net, $args, "192.168.1.20\n4001\n192.168.1.0/24\nsim\n");

    expect($code)->toBe(Status::USAGE)->and(is_file($net['store']->path()))->toBeFalse();

    cliNetClean($net['dir']);
})->with([[['network', 'on', '--yes']], [['network', 'on', '-y']], [['network', '--yes', 'on']], [['network', 'on', '--force']]]);

it('network on recusa respostas inválidas com 64 e sem UAC', function (string $input) {
    $net = cliNet();
    [$code, , $err] = cliNetRun($net, ['network', 'on'], $input);

    expect($code)->toBe(Status::USAGE)->and($err)->toContain('Nada foi feito')->and(count($net['calls']))->toBe(0);

    cliNetClean($net['dir']);
})->with([
    'bind público' => ["8.8.8.8\n4001\n192.168.1.0/24\nsim\n"],
    'bind qualquer' => ["0.0.0.0\n4001\n192.168.1.0/24\nsim\n"],
    'porta texto' => ["192.168.1.20\nabc\n192.168.1.0/24\nsim\n"],
    'porta zero' => ["192.168.1.20\n0\n192.168.1.0/24\nsim\n"],
    'allow vazia' => ["192.168.1.20\n4001\n\nsim\n"],
    'allow pública' => ["192.168.1.20\n4001\n8.8.8.8\nsim\n"],
    'allow qualquer' => ["192.168.1.20\n4001\n0.0.0.0/0\nsim\n"],
]);

it('network on sem .env sai 78 e sem IP privado sai 1', function () {
    $net = cliNet(env: null);
    [$code] = cliNetRun($net, ['network', 'on'], "\n");
    cliNetClean($net['dir']);

    expect($code)->toBe(Status::CONFIG);

    $net = cliNet(ips: ['127.0.0.1', '8.8.8.8']);
    [$code, , $err] = cliNetRun($net, ['network', 'on'], "\n");
    cliNetClean($net['dir']);

    expect($code)->toBe(Status::FAILURE)->and($err)->toContain('nenhum IPv4 privado');
});

it('network on com UAC recusado sai 1 e deixa a rede desligada', function () {
    $net = cliNet(uac: false);
    [$code, , $err] = cliNetRun($net, ['network', 'on'], "192.168.1.20\n4001\n192.168.1.0/24\nsim\n");

    expect($code)->toBe(Status::FAILURE)
        ->and($err)->toContain('recusou o UAC')
        ->and($net['store']->load()->enabled)->toBeFalse();

    cliNetClean($net['dir']);
});

it('network off é idempotente: duas vezes seguidas saem 0', function () {
    $net = cliNet();
    cliNetRun($net, ['network', 'on'], "192.168.1.20\n4001\n192.168.1.0/24\nsim\n");

    [$a] = cliNetRun($net, ['network', 'off']);
    [$b, $out] = cliNetRun($net, ['network', 'off']);

    expect($a)->toBe(Status::OK)->and($b)->toBe(Status::OK)->and($net['store']->load()->enabled)->toBeFalse();

    cliNetClean($net['dir']);
});

it('network off com UAC recusado sai 1, mas o estado já está fechado', function () {
    $net = cliNet(rules: "RULES=1\n" . CLI_NET_RULE);
    $net['store']->writeForCli(true, '192.168.1.20', 4001, ['192.168.1.0/24']);
    $manager = new Manager($net['store'], $net['machine'], $net['probe'], new Firewall(static function (): PsResult {
        throw new RuntimeException('Você recusou o UAC; nada foi executado.');
    }), $net['history']);
    $net['manager'] = $manager;

    [$code, , $err] = cliNetRun($net, ['network', 'off']);

    expect($code)->toBe(Status::FAILURE)
        ->and($err)->toContain('ficou para remover')
        ->and($net['store']->load()->enabled)->toBeFalse();

    cliNetClean($net['dir']);
});

it('network status só lê: não grava, não pede UAC e mostra tudo', function () {
    $net = cliNet(rules: "RULES=1\n" . CLI_NET_RULE);
    $net['store']->writeForCli(true, '192.168.1.20', 4001, ['192.168.1.0/24']);
    $antes = (string) file_get_contents($net['store']->path());

    [$code, $out] = cliNetRun($net, ['network', 'status']);

    expect($code)->toBe(Status::OK)
        ->and($out)->toContain('Rede: ligada')->toContain('192.168.1.20:4001')->toContain('Perfil da rede: Private')
        ->and($out)->toContain('Regra de firewall: presente e igual ao estado')->toContain('Listener responde: não')
        ->and($out)->toContain('O IP ainda é desta máquina: sim')->toContain('subiria agora: sim')
        ->and((string) file_get_contents($net['store']->path()))->toBe($antes)
        ->and(count($net['calls']))->toBe(0);

    cliNetClean($net['dir']);
});

it('network status com a rede desligada e sem estado diz isso, sem erro', function () {
    $net = cliNet();
    [$code, $out] = cliNetRun($net, ['network', 'status']);

    expect($code)->toBe(Status::OK)->and($out)->toContain('Rede: desligada')->toContain('subiria agora: não');

    cliNetClean($net['dir']);
});

it('o relatório de status explica o IP que sumiu, o perfil errado e a regra ausente', function (string $profile, string $rules, array $ips, string $trecho) {
    $net = cliNet($profile, $rules, $ips);
    $net['store']->writeForCli(true, '192.168.1.20', 4001, ['192.168.1.0/24']);
    [, $out] = cliNetRun($net, ['network', 'status']);

    expect($out)->toContain($trecho);

    cliNetClean($net['dir']);
})->with([
    'ip sumiu' => ['Private', "RULES=1\n" . CLI_NET_RULE, ['10.9.9.9'], 'não pertence mais'],
    'perfil Public' => ['Public', "RULES=1\n" . CLI_NET_RULE, ['192.168.1.20'], 'precisa ser Private'],
    'regra ausente' => ['Private', 'RULES=0', ['192.168.1.20'], 'não existe'],
]);

/**
 * @param array<string, mixed> $net
 * @return array{children: array<string, list<string>>, code: int, err: string, out: string, serve: Serve, events: list<string>}
 */
function cliServeNet(array $net, ?Closure $runner = null): array
{
    $captured = ['children' => [], 'events' => []];
    $in = fopen('php://memory', 'w+');
    $out = fopen('php://memory', 'w+');
    $err = fopen('php://memory', 'w+');
    $console = new Console($in, $out, $err);
    $serve = new Serve(
        $net['dir'],
        $console,
        $runner ?? static function (array $children, string $cwd, Closure $tick, Closure $event) use (&$captured): int {
            $captured['children'] = $children;
            $captured['tick'] = $tick;
            $captured['event'] = $event;

            return 0;
        },
        new Launcher($net['store'], $net['machine'], $net['probe']),
        $net['history'],
        $net['store'],
    );
    $code = $serve->run([]);
    rewind($out);
    rewind($err);

    return ['children' => $captured['children'], 'code' => $code, 'err' => (string) stream_get_contents($err), 'out' => (string) stream_get_contents($out), 'serve' => $serve, 'events' => $captured['events'], 'captured' => &$captured];
}

it('serve com a rede desligada sobe só o loopback e não faz barulho', function () {
    $net = cliNet();
    $r = cliServeNet($net);

    expect(array_keys($r['children']))->toBe(['loopback'])->and($r['err'])->toBe('')->and(count($net['provider']->inserted))->toBe(0);

    cliNetClean($net['dir']);
});

it('serve com a rede ligada e tudo conferindo sobe um segundo listener no IP privado, nunca em 0.0.0.0', function () {
    $net = cliNet(rules: "RULES=1\n" . CLI_NET_RULE);
    $net['store']->writeForCli(true, '192.168.1.20', 4001, ['192.168.1.0/24']);
    $r = cliServeNet($net);

    expect(array_keys($r['children']))->toBe(['loopback', 'rede'])
        ->and($r['children']['loopback'][2])->toBe('127.0.0.1:4001')
        ->and($r['children']['rede'][2])->toBe('192.168.1.20:4001')
        ->and(implode(' ', $r['children']['rede']))->not->toContain('0.0.0.0');

    cliNetClean($net['dir']);
});

it('serve fail-closed: cada pré-condição que falha mantém só o loopback, mostra o motivo e grava no histórico', function (string $profile, string $rules, array $ips, string $motivo) {
    $net = cliNet($profile, $rules, $ips);
    $net['store']->writeForCli(true, '192.168.1.20', 4001, ['192.168.1.0/24']);
    $r = cliServeNet($net);

    expect(array_keys($r['children']))->toBe(['loopback'])
        ->and($r['code'])->toBe(Status::OK)
        ->and($r['err'])->toContain('nenhum listener de rede sobe')->toContain($motivo)
        ->and($net['provider']->inserted[0]->command)->toBe('rede listener não subiu')
        ->and($net['provider']->inserted[0]->exitCode)->toBe(1)
        ->and($net['provider']->inserted[0]->output)->toContain($motivo);

    cliNetClean($net['dir']);
})->with([
    'IP sumiu' => ['Private', "RULES=1\n" . CLI_NET_RULE, ['10.9.9.9'], 'não pertence mais'],
    'perfil Public' => ['Public', "RULES=1\n" . CLI_NET_RULE, ['192.168.1.20'], 'precisa ser Private'],
    'regra ausente' => ['Private', 'RULES=0', ['192.168.1.20'], 'não existe'],
    'regra ilegível' => ['Private', 'RULES=erro', ['192.168.1.20'], 'ler a regra'],
]);

it('serve com network.json inválido não sobe rede e diz o motivo', function () {
    $net = cliNet();
    file_put_contents($net['store']->path(), '{"enabled": true, "bind_ip": "8.8.8.8"}');
    $r = cliServeNet($net);

    expect(array_keys($r['children']))->toBe(['loopback'])->and($r['err'])->toContain('ausente ou inválido');

    cliNetClean($net['dir']);
});

it('o servidor vigia o estado e derruba só o listener de rede quando a rede é desligada', function () {
    $net = cliNet(rules: "RULES=1\n" . CLI_NET_RULE);
    $net['store']->writeForCli(true, '192.168.1.20', 4001, ['192.168.1.0/24']);
    $tick = null;
    cliServeNet($net, static function (array $children, string $cwd, Closure $t, Closure $event) use (&$tick): int {
        $tick = $t;

        return 0;
    });

    expect($tick())->toBe([]);

    $net['store']->disableFromWeb();
    $segundo = $tick;

    $net2 = cliNet(rules: "RULES=1\n" . CLI_NET_RULE);
    $net2['store']->writeForCli(true, '192.168.1.20', 4001, ['192.168.1.0/24']);
    $tick2 = null;
    cliServeNet($net2, static function (array $children, string $cwd, Closure $t, Closure $event) use (&$tick2): int {
        $tick2 = $t;

        return 0;
    });
    $net2['store']->disableFromWeb();

    expect($tick2())->toBe(['rede']);

    cliNetClean($net['dir']);
    cliNetClean($net2['dir']);
});

it('os eventos do listener de rede vão para o histórico sem segredo e o loopback continua', function () {
    $net = cliNet(rules: "RULES=1\n" . CLI_NET_RULE);
    $net['store']->writeForCli(true, '192.168.1.20', 4001, ['192.168.1.0/24']);
    $event = null;
    $r = cliServeNet($net, static function (array $children, string $cwd, Closure $t, Closure $e) use (&$event): int {
        $event = $e;

        return 0;
    });

    $event('loopback', 'start', null);
    $event('rede', 'start', null);
    $event('rede', 'exit', 7);
    $event('rede', 'stopped', null);
    $event('rede', 'failed', null);

    $comandos = array_map(static fn ($e): string => $e->command, $net['provider']->inserted);

    expect($comandos)->toBe(['rede listener subiu', 'rede listener caiu', 'rede listener encerrado', 'rede listener não iniciou'])
        ->and(implode("\n", array_map(static fn ($e): string => $e->output, $net['provider']->inserted)))->not->toMatch('/token|senha|password/i');

    cliNetClean($net['dir']);
});

it('o supervisor devolve a saída do principal e derruba o secundário junto', function () {
    $php = PHP_BINARY;
    $eventos = [];
    $t0 = microtime(true);
    $code = Supervisor::run(
        ['loopback' => [$php, '-r', 'usleep(400000); exit(0);'], 'rede' => [$php, '-r', 'sleep(30);']],
        sys_get_temp_dir(),
        static fn (): array => [],
        static function (string $n, string $k, ?int $c) use (&$eventos): void {
            $eventos[] = $n . ':' . $k;
        },
    );

    expect($code)->toBe(Status::OK)
        ->and(microtime(true) - $t0)->toBeLessThan(10.0)
        ->and($eventos)->toContain('loopback:start', 'rede:start', 'loopback:exit');
});

it('o supervisor mantém o principal de pé quando o secundário cai', function () {
    $php = PHP_BINARY;
    $eventos = [];
    $code = Supervisor::run(
        ['loopback' => [$php, '-r', 'usleep(1200000); exit(0);'], 'rede' => [$php, '-r', 'exit(3);']],
        sys_get_temp_dir(),
        static fn (): array => [],
        static function (string $n, string $k, ?int $c) use (&$eventos): void {
            $eventos[] = $n . ':' . $k . ':' . ($c ?? '');
        },
    );

    expect($code)->toBe(Status::OK)
        ->and($eventos)->toContain('rede:exit:3')
        ->and(array_search('rede:exit:3', $eventos, true))->toBeLessThan((int) array_search('loopback:exit:0', $eventos, true));
});

it('o supervisor para só o secundário quando o tick manda', function () {
    $php = PHP_BINARY;
    $eventos = [];
    $code = Supervisor::run(
        ['loopback' => [$php, '-r', 'usleep(1200000); exit(0);'], 'rede' => [$php, '-r', 'sleep(30);']],
        sys_get_temp_dir(),
        static fn (): array => ['rede'],
        static function (string $n, string $k, ?int $c) use (&$eventos): void {
            $eventos[] = $n . ':' . $k;
        },
    );

    expect($code)->toBe(Status::OK)->and($eventos)->toContain('rede:stopped')->and($eventos)->toContain('loopback:exit');
});

it('o supervisor devolve 1 quando o principal sai com erro', function () {
    $code = Supervisor::run(
        ['loopback' => [PHP_BINARY, '-r', 'exit(5);']],
        sys_get_temp_dir(),
        static fn (): array => [],
        static function (string $n, string $k, ?int $c): void {
        },
    );

    expect($code)->toBe(Status::FAILURE);
});

it('o servidor decide a volta: o loopback volta igual, e a rede só volta se tudo ainda conferir', function () {
    $net = cliNet(rules: "RULES=1\n" . CLI_NET_RULE);
    $net['store']->writeForCli(true, '192.168.1.20', 4001, ['192.168.1.0/24']);
    $respawn = null;
    cliServeNet($net, static function (array $children, string $cwd, Closure $t, Closure $e, Closure $r) use (&$respawn): int {
        $respawn = $r;

        return 0;
    });

    expect($respawn('loopback')[2])->toBe('127.0.0.1:4001')
        ->and($respawn('rede')[2])->toBe('192.168.1.20:4001');

    $net['store']->disableFromWeb();

    expect($respawn('rede'))->toBeNull()
        ->and(count($net['provider']->inserted))->toBe(0);

    cliNetClean($net['dir']);
});

it('a rede não volta se o IP sumiu, e o motivo vai para o histórico', function () {
    $net = cliNet(rules: "RULES=1\n" . CLI_NET_RULE);
    $net['store']->writeForCli(true, '192.168.1.20', 4001, ['192.168.1.0/24']);
    $respawn = null;
    $serve = cliServeNet($net, static function (array $children, string $cwd, Closure $t, Closure $e, Closure $r) use (&$respawn): int {
        $respawn = $r;

        return 0;
    });
    $semIp = cliNet(rules: "RULES=1\n" . CLI_NET_RULE, ips: ['10.9.9.9']);
    $semIp['store']->writeForCli(true, '192.168.1.20', 4001, ['192.168.1.0/24']);
    $respawnSemIp = null;
    cliServeNet($semIp, static function (array $children, string $cwd, Closure $t, Closure $e, Closure $r) use (&$respawnSemIp): int {
        $respawnSemIp = $r;

        return 0;
    });

    expect($respawnSemIp('rede'))->toBeNull()
        ->and($semIp['provider']->inserted[1]->command)->toBe('rede listener não voltou')
        ->and($semIp['provider']->inserted[1]->output)->toContain('não pertence mais');

    cliNetClean($net['dir']);
    cliNetClean($semIp['dir']);
});

it('reinício e desistência do listener de rede vão para o histórico sem segredo', function () {
    $net = cliNet(rules: "RULES=1\n" . CLI_NET_RULE);
    $net['store']->writeForCli(true, '192.168.1.20', 4001, ['192.168.1.0/24']);
    $event = null;
    cliServeNet($net, static function (array $children, string $cwd, Closure $t, Closure $e) use (&$event): int {
        $event = $e;

        return 0;
    });

    $event('rede', 'restart', 2);
    $event('rede', 'gaveup', 5);
    $event('loopback', 'exit', 1);

    $comandos = array_map(static fn ($e): string => $e->command, $net['provider']->inserted);

    expect($comandos)->toBe(['rede listener reiniciando', 'rede listener desistiu'])
        ->and(implode(' ', array_map(static fn ($e): string => $e->output, $net['provider']->inserted)))->not->toMatch('/token|senha|password/i');

    cliNetClean($net['dir']);
});

it('o serve e o supervisor nunca criam regra de firewall nem pedem elevação: o boot não toca no firewall', function () {
    $raiz = dirname(__DIR__, 2);

    foreach (['src/Cli/Serve.php', 'src/Cli/Supervisor.php', 'src/Network/Launcher.php', 'src/Network/Plan.php', 'src/Network/Probe.php'] as $arquivo) {
        $texto = (string) file_get_contents($raiz . '/' . $arquivo);

        expect($texto)->not->toMatch('/Firewall\b|Firewall::|OneShot|Elevation|WinAction|dispatch|RunAs|New-NetFirewallRule/');
    }
});

it('network on confere o IP de escuta logo depois de perguntar, antes do risco e do sim', function () {
    $net = cliNet();
    [$code, $out, $err] = cliNetRun($net, ['network', 'on'], "192.168.1.99\n4001\n192.168.1.0/24\nsim\n");

    expect($code)->toBe(Status::USAGE)
        ->and($err)->toContain('192.168.1.99')->toContain('não pertence a nenhuma interface')->toContain('Nada foi feito')
        ->and($out)->not->toContain('RISCO')->not->toContain('Digite "sim"')->not->toContain('Porta')
        ->and($net['store']->load()->enabled)->toBeFalse()
        ->and(count($net['calls']))->toBe(0);

    cliNetClean($net['dir']);
});
