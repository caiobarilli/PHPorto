<?php

declare(strict_types=1);

use App\Win\Elevation;
use App\Win\ElevationState;

beforeEach(function () {
    $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phporto_elev_' . bin2hex(random_bytes(6));
    mkdir($base . DIRECTORY_SEPARATOR . 'files', 0o775, true);
    mkdir($base . DIRECTORY_SEPARATOR . 'storage', 0o775, true);

    mkdir($base . DIRECTORY_SEPARATOR . 'Win', 0o775, true);

    $this->base    = $base;
    $this->files   = $base . DIRECTORY_SEPARATOR . 'files';
    $this->storage = $base . DIRECTORY_SEPARATOR . 'storage';
    $this->win     = $base . DIRECTORY_SEPARATOR . 'Win';

    foreach ([Elevation::SCRIPT_WORKER, Elevation::SCRIPT_BOOTSTRAP] as $nome) {
        file_put_contents($this->win . DIRECTORY_SEPARATOR . $nome, "# stub\r\n");
    }
});

afterEach(function () {
    if (!is_string($this->base) || !is_dir($this->base)) {
        return;
    }

    foreach (['files', 'storage', 'Win'] as $sub) {
        foreach (glob($this->base . DIRECTORY_SEPARATOR . $sub . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->base . DIRECTORY_SEPARATOR . $sub);
    }

    foreach (glob($this->base . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($this->base);
});

function elevacao(object $ctx, ?string $winDir = null): Elevation
{
    return new Elevation(
        filesDir: (string) $ctx->files,
        storageDir: (string) $ctx->storage,
        winDir: $winDir ?? (string) $ctx->win,
    );
}

function gravarMarcador(object $ctx, ?int $phpPid = null, int $psPid = 4242, string $nonce = 'abc123'): void
{
    file_put_contents(
        (string) $ctx->storage . DIRECTORY_SEPARATOR . Elevation::F_MARCADOR,
        (string) json_encode([
            'php_pid'    => $phpPid ?? getmypid(),
            'ps_pid'     => $psPid,
            'nonce'      => $nonce,
            'provado_em' => gmdate(DATE_ATOM),
        ])
    );
}

function baterHeartbeat(object $ctx, int $segundosAtras = 0): void
{
    $caminho = (string) $ctx->files . DIRECTORY_SEPARATOR . Elevation::F_HEARTBEAT;
    file_put_contents($caminho, (string) (time() * 1000));
    touch($caminho, time() - $segundosAtras);
    clearstatcache(true, $caminho);
}

it('os prazos são constantes com nome e valor documentado', function () {
    expect(Elevation::PROOF_TIMEOUT_S)->toBe(30)
        ->and(Elevation::SHUTDOWN_WAIT_S)->toBe(5)
        ->and(Elevation::HEARTBEAT_STALE_S)->toBe(10)
        ->and(Elevation::ONESHOT_CONSENT_S)->toBe(60)
        ->and(Elevation::ONESHOT_START_S)->toBe(15);
});

it('o limite de tempo sobe ANTES de rodar o lançador, e não depois', function () {
    $fonte  = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Win/Elevation.php');
    $enable = substr($fonte, (int) strpos($fonte, 'public function enable()'));
    $limite = strpos($enable, 'set_time_limit(self::PROOF_TIMEOUT_S');
    $roda   = strpos($enable, '$runner->run(');

    expect($limite)->toBeInt()
        ->and($roda)->toBeInt()
        ->and($limite)->toBeLessThan($roda);
});

it('o lançador manda o -ArgumentList como uma string só, com os caminhos entre aspas', function () {
    $fonte = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Win/Elevation.php');

    expect($fonte)->toContain("'-File ' . self::quoted(")
        ->and($fonte)->toContain("'-Dir ' . self::quoted(")
        ->and($fonte)->toContain('-ArgumentList %s')
        ->and($fonte)->not->toContain("PsScriptBuilder::literal('-File')");
});

it('a limpeza do canal leva as sobras de um uso único', function () {
    $canal = [Elevation::F_ORDEM_DESLIGAR, Elevation::F_ORDEM_CANCELAR, Elevation::F_JOB, Elevation::F_HEARTBEAT, Elevation::F_MANIFESTO];

    foreach ([...$canal, 'win-oneshot-abc000000001.json', 'win-oneshot-abc000000001.estado', 'win-oneshot-launcher-abc000000001.ps1'] as $nome) {
        file_put_contents($this->files . DIRECTORY_SEPARATOR . $nome, 'x');
    }

    (new ReflectionMethod(Elevation::class, 'cleanupChannel'))->invoke(elevacao($this));

    expect(glob($this->files . DIRECTORY_SEPARATOR . '*'))->toBe([]);
});

it('bloqueia quando falta o <_> em src/Win', function (string $ausente) {
    unlink($this->win . DIRECTORY_SEPARATOR . $ausente);

    $estado = elevacao($this)->state();

    expect($estado)->toBeInstanceOf(ElevationState::class)
        ->and($estado->on)->toBeFalse()
        ->and($estado->canTry())->toBeFalse()
        ->and($estado->blocked)->toContain('Instalação incompleta')
        ->and($estado->blocked)->toContain($ausente);
})->with([Elevation::SCRIPT_WORKER, Elevation::SCRIPT_BOOTSTRAP]);

it('bloqueia quando a pasta src/Win não existe', function () {
    $estado = elevacao($this, $this->base . DIRECTORY_SEPARATOR . 'nao-existe')->state();

    expect($estado->canTry())->toBeFalse()
        ->and($estado->blocked)->toContain('Instalação incompleta');
});

it('a mensagem de bloqueio não fala mais de .env nem de winutil', function () {
    unlink($this->win . DIRECTORY_SEPARATOR . Elevation::SCRIPT_BOOTSTRAP);

    $bloqueio = elevacao($this)->state()->blocked;

    expect($bloqueio)->toBeString()
        ->and($bloqueio)->not->toContain('PHPORTO_WINUTIL_PATH')
        ->and($bloqueio)->not->toContain('winutil');
});

it('bloqueado NEM TENTA ligar, e devolve o motivo', function () {
    unlink($this->win . DIRECTORY_SEPARATOR . Elevation::SCRIPT_WORKER);

    $erro = elevacao($this)->enable();

    expect($erro)->toContain('Instalação incompleta')
        ->and(is_file($this->storage . DIRECTORY_SEPARATOR . Elevation::F_MARCADOR))->toBeFalse();
});

it('com os dois .ps1 no lugar, dá para tentar', function () {
    expect(elevacao($this)->state()->canTry())->toBeTrue();
});

it('a árvore de verdade do projeto passa na checagem', function () {
    $real = new Elevation(
        filesDir: (string) $this->files,
        storageDir: (string) $this->storage,
        winDir: dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Win',
    );

    expect($real->state()->canTry())->toBeTrue();
});

it('sem marcador está desligado, e sem drama', function () {
    $estado = elevacao($this)->state();

    expect($estado->on)->toBeFalse()
        ->and($estado->detail)->toBeNull()
        ->and($estado->canTry())->toBeTrue();
});

it('MARCADOR DE OUTRA EXECUÇÃO DO SERVIDOR lê como desligado', function () {
    gravarMarcador($this, phpPid: getmypid() + 1);
    baterHeartbeat($this);

    expect(elevacao($this)->state()->on)->toBeFalse();
});

it('não apaga marcador inválido: leitura não tem efeito colateral', function () {
    gravarMarcador($this, phpPid: getmypid() + 1);

    elevacao($this)->state();

    expect(is_file($this->storage . DIRECTORY_SEPARATOR . Elevation::F_MARCADOR))->toBeTrue();
});

it('com PID casando e heartbeat fresco, está ligado', function () {
    gravarMarcador($this, psPid: 9876);
    baterHeartbeat($this);

    $estado = elevacao($this)->state();

    expect($estado->on)->toBeTrue()
        ->and($estado->psPid)->toBe(9876)
        ->and($estado->provedAt)->toMatch('/^\d{4}-\d{2}-\d{2}T/')
        ->and($estado->detail)->toBeNull();
});

it('aceita heartbeat de idade dentro do limite', function () {
    gravarMarcador($this);
    baterHeartbeat($this, segundosAtras: Elevation::HEARTBEAT_STALE_S);

    expect(elevacao($this)->state()->on)->toBeTrue();
});

it('HEARTBEAT VELHO desliga e explica, em vez de mentir', function () {
    gravarMarcador($this);
    baterHeartbeat($this, segundosAtras: Elevation::HEARTBEAT_STALE_S + 5);

    $estado = elevacao($this)->state();

    expect($estado->on)->toBeFalse()
        ->and($estado->detail)->toContain('não responde há');
});

it('marcador válido sem heartbeat nenhum desliga e explica', function () {
    gravarMarcador($this);

    $estado = elevacao($this)->state();

    expect($estado->on)->toBeFalse()
        ->and($estado->detail)->toContain('sinal de vida');
});

it('JSON ILEGÍVEL VIRA DESLIGADO, e não erro', function (string $conteudo) {
    file_put_contents($this->storage . DIRECTORY_SEPARATOR . Elevation::F_MARCADOR, $conteudo);
    baterHeartbeat($this);

    expect(elevacao($this)->state()->on)->toBeFalse();
})->with([
    'nao e json',
    '',
    '   ',
    '[]',
    '{}',
    '{"php_pid": "texto", "ps_pid": 1, "nonce": "a", "provado_em": "x"}',
    '{"php_pid": 1, "ps_pid": 0, "nonce": "a", "provado_em": "x"}',
    '{"ps_pid": 1, "nonce": "a", "provado_em": "x"}',
]);

it('nonce vem do marcador, e é nulo sem marcador', function () {
    expect(elevacao($this)->nonce())->toBeNull();

    gravarMarcador($this, nonce: 'carimbo-desta-execucao');

    expect(elevacao($this)->nonce())->toBe('carimbo-desta-execucao');
});

it('order() escreve o arquivo que o worker lê no laço', function () {
    expect(elevacao($this)->order(Elevation::F_ORDEM_CANCELAR))->toBeTrue()
        ->and(is_file($this->files . DIRECTORY_SEPARATOR . Elevation::F_ORDEM_CANCELAR))->toBeTrue();
});

it('jobPath fica em files/, com o nome que o worker espera', function () {
    expect(elevacao($this)->jobPath())
        ->toBe($this->files . DIRECTORY_SEPARATOR . Elevation::F_JOB);
});

it('DESLIGAR apaga o marcador e deixa a ordem, sem tentar matar ninguém', function () {
    gravarMarcador($this);

    $frase = elevacao($this)->disable();

    expect(is_file($this->storage . DIRECTORY_SEPARATOR . Elevation::F_MARCADOR))->toBeFalse()
        ->and(is_file($this->files . DIRECTORY_SEPARATOR . Elevation::F_ORDEM_DESLIGAR))->toBeTrue()
        ->and($frase)->toContain('desligado');
});

it('desligar apaga o marcador ANTES de esperar, para a tela não mentir', function () {
    gravarMarcador($this);
    baterHeartbeat($this);

    $elev  = elevacao($this);
    $frase = $elev->disable();

    expect($elev->state()->on)->toBeFalse()
        ->and($frase)->toContain('próximo tique');
});

it('desligar sem nada ligado não estoura', function () {
    expect(elevacao($this)->disable())->toBeString();
});

it('o tempo ocioso da tela é o mesmo que o worker usa', function () {
    $worker = file_get_contents(dirname(__DIR__, 2) . '/src/Win/worker.ps1');

    expect($worker)->toBeString();
    expect(preg_match('/^\$IDLE_TIMEOUT_S\s*=\s*(\d+)\s*$/m', (string) $worker, $m))->toBe(1)
        ->and((int) $m[1])->toBe(Elevation::IDLE_TIMEOUT_S);
});

it('sem heartbeat, a explicação lembra que o worker sai sozinho', function () {
    gravarMarcador($this);

    expect(elevacao($this)->state()->detail)
        ->toContain(sprintf('%d min sem uso', intdiv(Elevation::IDLE_TIMEOUT_S, 60)));
});
