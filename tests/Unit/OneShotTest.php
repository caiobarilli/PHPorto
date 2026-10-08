<?php

declare(strict_types=1);

use App\Win\Elevation;
use App\Win\OneShot;
use App\Win\PsResult;
use App\Win\UacPolicy;
use App\Win\WinAction;

/**
 * A ação sensível: pedido, lançador, espera do ACEITO e coleta por id.
 *
 * NADA AQUI ELEVA NADA. O lançador é uma closure que faz o papel do
 * powershell.exe: ela lê o que o PHP escreveu (o pedido e o stub em base64) e
 * responde como o Windows responderia — recusado, sem resposta, aceito. O
 * stub e o worker de verdade são exercitados no OneShot.Tests.ps1, e a
 * elevação em si fica na lista do que se confere à mão no Windows.
 */

beforeEach(function () {
    $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phporto_uma_' . bin2hex(random_bytes(6));

    $this->base      = $base;
    $this->files     = $base . DIRECTORY_SEPARATOR . 'files';
    $this->win       = $base . DIRECTORY_SEPARATOR . 'Win';
    $this->protegida = $this->files . DIRECTORY_SEPARATOR . Elevation::DIR_PROTEGIDA;

    mkdir($this->protegida, 0o775, true);
    mkdir($this->win, 0o775, true);
    mkdir($base . DIRECTORY_SEPARATOR . 'storage', 0o775, true);

    foreach ([Elevation::SCRIPT_WORKER, Elevation::SCRIPT_BOOTSTRAP] as $nome) {
        file_put_contents($this->win . DIRECTORY_SEPARATOR . $nome, "# stub de {$nome}\r\n");
    }

    // O que o lançador fingido viu, para o teste conferir depois.
    $this->visto = new ArrayObject();
});

afterEach(function () {
    $apagar = static function (string $dir) use (&$apagar): void {
        foreach (glob($dir . '/{,.}[!.,!..]*', GLOB_BRACE) ?: [] as $f) {
            is_dir($f) ? $apagar($f) : @unlink($f);
        }
        @rmdir($dir);
    };

    if (is_string($this->base) && is_dir($this->base)) {
        $apagar($this->base);
    }
});

/** A saída do reg.exe com o UAC no CPBA que se quiser. */
function regUac(int $cpba, int $lua = 1): string
{
    return "\r\nHKEY_LOCAL_MACHINE\\SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\Policies\\System\r\n"
        . sprintf("    ConsentPromptBehaviorAdmin    REG_DWORD    0x%x\r\n    EnableLUA    REG_DWORD    0x%x\r\n", $cpba, $lua);
}

/**
 * O OneShot do teste.
 *
 * @param Closure(string, int): PsResult $lancador faz o papel do powershell.exe
 */
function usoUnico(object $ctx, Closure $lancador, int $cpba = 5, ?string $files = null, int $startTimeout = 1): OneShot
{
    $files ??= (string) $ctx->files;

    $elevation = new Elevation(
        filesDir: $files,
        storageDir: (string) $ctx->base . DIRECTORY_SEPARATOR . 'storage',
        winDir: (string) $ctx->win,
    );

    return new OneShot(
        $files,
        (string) $ctx->win,
        $elevation,
        new UacPolicy(static fn (): string => regUac($cpba)),
        $lancador,
        $startTimeout,
    );
}

/** O id de um uso único, pelo nome do lançador. */
function idDoLancador(string $lancador): string
{
    preg_match('/win-oneshot-launcher-([0-9a-f]{12})\.ps1$/', $lancador, $m);

    return $m[1] ?? '';
}

/** O stub decodificado, de dentro do lançador. */
function stubDoLancador(string $lancador): string
{
    $texto = (string) file_get_contents($lancador);
    preg_match('/-EncodedCommand ([A-Za-z0-9+\/=]+)/', $texto, $m);

    return mb_convert_encoding((string) base64_decode($m[1] ?? '', true), 'UTF-8', 'UTF-16LE');
}

/**
 * Um lançador fingido que guarda o que viu e responde com o que se quiser.
 *
 * @param Closure(string, string): void|null $depois o que o "worker" faz,
 *        recebendo a pasta de trabalho e o id
 */
function lancadorQue(object $ctx, string $saida, ?int $exit = 0, bool $timeout = false, ?Closure $depois = null): Closure
{
    return static function (string $lancador, int $prazo) use ($ctx, $saida, $exit, $timeout, $depois): PsResult {
        $id     = idDoLancador($lancador);
        $pedido = dirname($lancador) . DIRECTORY_SEPARATOR . 'win-oneshot-' . $id . '.json';

        $ctx->visto['id']     = $id;
        $ctx->visto['prazo']  = $prazo;
        $ctx->visto['texto']  = (string) file_get_contents($lancador);
        $ctx->visto['stub']   = stubDoLancador($lancador);
        $ctx->visto['pedido'] = is_file($pedido) ? (string) file_get_contents($pedido) : null;

        if ($depois !== null) {
            $depois(dirname($lancador), $id);
        }

        return new PsResult($saida, $exit, $timeout, 5);
    };
}

/** Finge o worker de uso único: ACEITO, e o resultado na pasta protegida. */
function workerAceita(string $saida = "feito\n", int $exit = 0): Closure
{
    return static function (string $files, string $id) use ($saida, $exit): void {
        file_put_contents($files . "/win-oneshot-{$id}.estado", "\u{FEFF}ACEITO=4242\r\n");

        $protegida = $files . DIRECTORY_SEPARATOR . Elevation::DIR_PROTEGIDA;
        file_put_contents($protegida . "/win-out-{$id}.txt", $saida);
        file_put_contents(
            $protegida . "/win-done-{$id}.json",
            (string) json_encode(['id' => $id, 'exit' => $exit, 'ms' => 50, 'nota' => ''])
        );
    };
}

/** O que sobrou de uso único em files/. */
function sobras(object $ctx): array
{
    return glob((string) $ctx->files . DIRECTORY_SEPARATOR . Elevation::ONESHOT_PREFIX . '*') ?: [];
}

// ---------------------------------------------------------------- caminho feliz

it('aceito, devolve a saída da ação pelo id e não deixa nada para trás', function () {
    $r = usoUnico($this, lancadorQue($this, "PID=4242\r\n", 0, false, workerAceita("instalado\n")))
        ->dispatch(WinAction::Rdp, ['SubAction' => 'on'], 600);

    expect($r->output)->toBe("instalado\n")
        ->and($r->exitCode)->toBe(0)
        ->and($this->visto['prazo'])->toBe(Elevation::ONESHOT_CONSENT_S)
        ->and(sobras($this))->toBe([])
        ->and(glob($this->protegida . '/*'))->toBe([]);
});

it('o pedido tem a forma que o worker confere', function () {
    usoUnico($this, lancadorQue($this, "PID=1\n", 0, false, workerAceita()))
        ->dispatch(WinAction::Sunshine, ['SubAction' => 'firewall-open'], 600);

    $p = json_decode((string) $this->visto['pedido'], true);

    expect($p)->toBeArray()
        ->and(array_keys($p))->toBe(['v', 'id', 'acao', 'params', 'php_pid', 'raiz_win', 'dir', 'criado_em', 'expira_em', 'prazo_s', 'manifesto'])
        ->and($p['v'])->toBe(1)
        ->and($p['id'])->toBe($this->visto['id'])
        ->and($p['acao'])->toBe('sunshine')
        ->and($p['params'])->toBe(['SubAction' => 'firewall-open'])
        ->and($p['php_pid'])->toBe(getmypid())
        ->and($p['raiz_win'])->toBe($this->win)
        ->and($p['dir'])->toBe($this->files)
        ->and($p['expira_em'] - $p['criado_em'])->toBe(Elevation::ONESHOT_CONSENT_S + Elevation::ONESHOT_START_S)
        ->and($p['prazo_s'])->toBe(600 + OneShot::PRAZO_FOLGA_S)
        ->and($p['manifesto'])->toHaveKey('bootstrap.ps1');
});

it('install sem params vai com params como objeto, e não lista', function () {
    usoUnico($this, lancadorQue($this, "PID=1\n", 0, false, workerAceita()))
        ->dispatch(WinAction::Install, [], 600);

    expect((string) $this->visto['pedido'])->toContain('"params":{}');
});

it('o timeout do pedido para em 3600 s, para o worker não recusar o prazo', function () {
    usoUnico($this, lancadorQue($this, "PID=1\n", 0, false, workerAceita()))
        ->dispatch(WinAction::Gpu, ['SubAction' => 'install'], 99999);

    expect(json_decode((string) $this->visto['pedido'], true)['prazo_s'])
        ->toBe(OneShot::MAX_TIMEOUT_S + OneShot::PRAZO_FOLGA_S);
});

it('O STUB SÓ LEVA CAMINHOS E HASHES: o hash do worker e o dos bytes do pedido', function () {
    usoUnico($this, lancadorQue($this, "PID=1\n", 0, false, workerAceita()))
        ->dispatch(WinAction::Install, ['Apps' => "Foo\u{2019}; Remove-Item C:\\ -Recurse #"], 600);

    $stub   = (string) $this->visto['stub'];
    $pedido = $this->files . DIRECTORY_SEPARATOR . 'win-oneshot-' . $this->visto['id'] . '.json';

    expect($stub)->toBe(OneShot::stub(
        $this->win . DIRECTORY_SEPARATOR . Elevation::SCRIPT_WORKER,
        (string) hash_file('sha256', $this->win . DIRECTORY_SEPARATOR . Elevation::SCRIPT_WORKER),
        $pedido,
        hash('sha256', (string) $this->visto['pedido']),
    ))
        // O texto do usuário vai no pedido, conferido por hash, e nunca na
        // linha de comando.
        ->and($stub)->not->toContain('Foo')
        ->and($stub)->not->toContain('Remove-Item')
        ->and((string) $this->visto['pedido'])->toContain('Remove-Item');
});

it('o stub confere o hash, tira o BOM e chama por dot-source', function () {
    $stub = OneShot::stub('C:\w\worker.ps1', str_repeat('a', 64), 'C:\f\win-oneshot-abc.json', str_repeat('b', 64));

    expect($stub)->toContain("[IO.File]::ReadAllBytes('C:\\w\\worker.ps1')")
        ->and($stub)->toContain('-InputStream ([IO.MemoryStream]::new($b))')
        ->and($stub)->toContain("-ne '" . str_repeat('a', 64) . "'){exit 97}")
        ->and($stub)->toContain('$b[0] -eq 239')
        ->and($stub)->toContain('. ([scriptblock]::Create(')
        ->and($stub)->not->toContain('& ([scriptblock]')
        ->and($stub)->toContain("-Pedido 'C:\\f\\win-oneshot-abc.json' -PedidoSha256 '" . str_repeat('b', 64) . "'");
});

it('caminho com apóstrofo no stub vira literal, e não código', function () {
    $stub = OneShot::stub("C:\\it's\\worker.ps1", 'x', "C:\\a\u{2019}b\\p.json", 'y');

    expect($stub)->toContain("'C:\\it''s\\worker.ps1'")
        ->and($stub)->toContain("'C:\\a\u{2019}\u{2019}b\\p.json'");
});

it('o lançador pede RunAs com UMA string de argumentos e trata o 1223', function () {
    usoUnico($this, lancadorQue($this, "PID=1\n", 0, false, workerAceita()))
        ->dispatch(WinAction::Rdp, ['SubAction' => 'on'], 600);

    $texto = (string) $this->visto['texto'];

    expect($texto)->toContain("-Verb RunAs -WindowStyle Hidden -PassThru -ArgumentList '-NoProfile -NonInteractive -ExecutionPolicy Bypass -WindowStyle Hidden -EncodedCommand ")
        ->and($texto)->toContain('NativeErrorCode -eq 1223')
        ->and($texto)->toContain("'CANCELADO'; exit 2")
        ->and($texto)->toStartWith("\u{FEFF}");
});

// ---------------------------------------------------------------- nada executado

it('RECUSADO NO PROMPT: diz que nada rodou, apaga o pedido e não coleta', function () {
    // Um resultado de outro id na pasta: se o PHP coletasse, ele sumiria.
    file_put_contents($this->protegida . '/win-done-fff000000000.json', '{"id":"fff000000000","exit":0}');

    expect(fn () => usoUnico($this, lancadorQue($this, "CANCELADO\r\n", 2))
        ->dispatch(WinAction::Rdp, ['SubAction' => 'on'], 600))
        ->toThrow(RuntimeException::class, 'Você recusou o UAC; nada foi executado');

    expect($this->visto['pedido'])->not->toBeNull()
        ->and(sobras($this))->toBe([])
        ->and(is_file($this->protegida . '/win-done-fff000000000.json'))->toBeTrue();
});

it('sem resposta ao prompt no prazo: diz que nada rodou e apaga o pedido', function () {
    expect(fn () => usoUnico($this, lancadorQue($this, '', null, true))
        ->dispatch(WinAction::Rdp, ['SubAction' => 'on'], 600))
        ->toThrow(RuntimeException::class, 'Ninguém respondeu ao UAC em 60 s');

    expect(sobras($this))->toBe([]);
});

it('o Windows não abriu o processo: devolve o erro do PowerShell', function () {
    expect(fn () => usoUnico($this, lancadorQue($this, "ERRO=O sistema não pode encontrar o arquivo\r\n", 1))
        ->dispatch(WinAction::Rdp, ['SubAction' => 'on'], 600))
        ->toThrow(RuntimeException::class, 'O PowerShell disse: O sistema não pode encontrar o arquivo');

    expect(sobras($this))->toBe([]);
});

it('o worker recusou o pedido: a frase dele chega à tela', function () {
    $recusa = static function (string $files, string $id): void {
        file_put_contents($files . "/win-oneshot-{$id}.estado", "ERRO=pedido expirado\r\n");
    };

    expect(fn () => usoUnico($this, lancadorQue($this, "PID=1\n", 0, false, $recusa))
        ->dispatch(WinAction::Rdp, ['SubAction' => 'on'], 600))
        ->toThrow(RuntimeException::class, 'O PowerShell elevado recusou: pedido expirado');

    expect(sobras($this))->toBe([]);
});

it('abriu e não deu sinal: pergunta se o worker.ps1 mudou, e apaga o pedido', function () {
    expect(fn () => usoUnico($this, lancadorQue($this, "PID=1\n"))
        ->dispatch(WinAction::Rdp, ['SubAction' => 'on'], 600))
        ->toThrow(RuntimeException::class, 'o worker.ps1 mudou depois do clique?');

    expect(sobras($this))->toBe([]);
});

it('CAMINHO LONGO DEMAIS é recusado antes de abrir o prompt', function () {
    $longo = $this->files;
    for ($i = 0; $i < 4; $i++) {
        $longo .= DIRECTORY_SEPARATOR . str_repeat('p', 200);
    }
    mkdir($longo . DIRECTORY_SEPARATOR . Elevation::DIR_PROTEGIDA, 0o775, true);

    $chamado = false;
    $lancador = static function () use (&$chamado): PsResult {
        $chamado = true;

        return new PsResult('', 0, false, 0);
    };

    expect(fn () => usoUnico($this, $lancador, files: $longo)->dispatch(WinAction::Rdp, ['SubAction' => 'on'], 600))
        ->toThrow(RuntimeException::class, 'longo demais para o UAC');

    expect($chamado)->toBeFalse()
        ->and(glob($longo . DIRECTORY_SEPARATOR . Elevation::ONESHOT_PREFIX . '*') ?: [])->toBe([]);
});

it('ação comum não passa pelo uso único', function () {
    usoUnico($this, lancadorQue($this, "PID=1\n"))->dispatch(WinAction::Rdp, ['SubAction' => 'off'], 600);
})->throws(RuntimeException::class, 'Só ação sensível');

// ---------------------------------------------------------------- bloqueio

it('com o UAC no padrão e o checkout inteiro, nada bloqueia', function () {
    expect(usoUnico($this, lancadorQue($this, ''))->blockingReason())->toBeNull();
});

it('UAC silencioso bloqueia, com a correção', function () {
    expect(usoUnico($this, lancadorQue($this, ''), cpba: 0)->blockingReason())
        ->toContain('ConsentPromptBehaviorAdmin = 0')
        ->toContain(UacPolicy::FIX_CPBA);
});

it('checkout incompleto bloqueia antes de olhar o UAC', function () {
    unlink($this->win . DIRECTORY_SEPARATOR . Elevation::SCRIPT_WORKER);

    expect(usoUnico($this, lancadorQue($this, ''), cpba: 0)->blockingReason())
        ->toContain(Elevation::SCRIPT_WORKER)
        ->not->toContain('ConsentPromptBehaviorAdmin');
});
