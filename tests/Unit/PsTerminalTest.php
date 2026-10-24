<?php

declare(strict_types=1);

use App\Domain\ExecutionKind;
use App\Win\PsResult;
use App\Win\PsTerminal;
use App\Win\PsTerminalCommand;
use App\Win\PsTerminalProcess;
use App\Win\WinAction;

final class TerminalFalso implements PsTerminal
{
    /** @var list<string> */
    public array $recebidos = [];

    public function __construct(private readonly PsResult $resposta)
    {
    }

    public function run(string $command): PsResult
    {
        $this->recebidos[] = $command;

        return $this->resposta;
    }
}

function terminalScript(string $comando): string
{
    $argv = PsTerminalCommand::arguments($comando);

    return mb_convert_encoding((string) base64_decode($argv[4], true), 'UTF-8', 'UTF-16LE');
}

function terminalDir(): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phporto_term_' . bin2hex(random_bytes(5));
    mkdir($dir, 0o775, true);

    return $dir;
}

function terminalLimpa(string $dir): void
{
    foreach (glob($dir . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($dir);
}

it('RECUSA comando vazio, só espaço ou que não é texto', function (mixed $bruto) {
    expect(fn () => PsTerminalCommand::validate($bruto))->toThrow(InvalidArgumentException::class, 'Digite um comando');
})->with([[''], ['   '], ["\t\n"], [null], [['Get-Date']], [42]]);

it('RECUSA byte nulo e bytes que não são UTF-8', function (string $bruto, string $motivo) {
    expect(fn () => PsTerminalCommand::validate($bruto))->toThrow(InvalidArgumentException::class, $motivo);
})->with([
    'nulo'      => ["Get-Date\0; Remove-Item x", 'byte nulo'],
    'não UTF-8' => ["Write-Output \xff\xfe", 'UTF-8'],
]);

it('o teto é de 4096 bytes, contados em bytes e não em caracteres', function () {
    $noTeto = str_repeat('a', PsTerminalCommand::MAX_COMMAND_BYTES);

    expect(PsTerminalCommand::validate($noTeto))->toBe($noTeto)
        ->and(fn () => PsTerminalCommand::validate($noTeto . 'a'))->toThrow(InvalidArgumentException::class, 'o teto é 4096')
        ->and(fn () => PsTerminalCommand::validate(str_repeat('é', 2049)))->toThrow(InvalidArgumentException::class, '4098 bytes');
});

it('apara as pontas e preserva o miolo do comando', function () {
    expect(PsTerminalCommand::validate("  Get-ChildItem  'C:\\Pasta com espaço'  \n"))
        ->toBe("Get-ChildItem  'C:\\Pasta com espaço'");
});

it('a linha de comando é a lista fixa, com o script em -EncodedCommand e sem shell no meio', function () {
    $argv = PsTerminalCommand::arguments('Get-Date');

    expect($argv)->toHaveCount(5)
        ->and(array_slice($argv, 0, 4))->toBe(['powershell.exe', '-NoProfile', '-NonInteractive', '-EncodedCommand'])
        ->and($argv[4])->toMatch('/^[A-Za-z0-9+\/]+=*$/D')
        ->and(PsTerminalCommand::arguments('Get-Date', 'pwsh')[0])->toBe('pwsh');
});

it('o -EncodedCommand é base64 de UTF-16LE, como o PowerShell espera', function () {
    $argv  = PsTerminalCommand::arguments('Get-Date');
    $bytes = (string) base64_decode($argv[4], true);

    expect(strlen($bytes) % 2)->toBe(0)
        ->and(substr($bytes, 0, 2))->toBe("\$\0")
        ->and(mb_convert_encoding($bytes, 'UTF-8', 'UTF-16LE'))->toBe(PsTerminalCommand::script('Get-Date'));
});

it('O COMANDO ENTRA COMO DADO: base64 de UTF-8 dentro do script, nunca o texto cru', function (string $comando) {
    $script = terminalScript($comando);

    expect($script)->not->toContain($comando)
        ->and($script)->toContain("FromBase64String('" . base64_encode($comando) . "')")
        ->and($script)->toContain('[System.Text.Encoding]::UTF8.GetString(');
})->with([
    'aspas e cifrão' => ["Write-Output \"\$env:USERNAME\"; 'a''b'"],
    'acento'         => ['Write-Output "ação ôlá"'],
    'quebra'         => ["Get-Date\nGet-Location"],
    'crase'          => ['Write-Output `$x'],
]);

it('o script junta todos os fluxos na saída, força UTF-8 e devolve um código de saída', function () {
    $script = PsTerminalCommand::script('Get-Date');

    expect($script)->toContain('*>&1 | Out-String -Stream -Width ' . PsTerminalCommand::WIDTH)
        ->and($script)->toContain('[Console]::OutputEncoding = $phportoUtf8')
        ->and($script)->toContain('New-Object System.Text.UTF8Encoding $false')
        ->and($script)->toContain('exit $global:LASTEXITCODE')
        ->and($script)->toContain('exit 1')
        ->and($script)->toContain('exit 0')
        ->and($script)->not->toMatch('/[\x{2018}\x{2019}\x{201C}\x{201D}\x{2014}]/u');
});

it('nem o maior comando estoura a linha de comando do Windows', function () {
    $argv = PsTerminalCommand::arguments(str_repeat('é', intdiv(PsTerminalCommand::MAX_COMMAND_BYTES, 2)));

    expect(strlen(implode(' ', $argv)))->toBeLessThan(32767);
});

it('o histórico grava o comando com o prefixo PS>', function () {
    expect(PsTerminalCommand::record('Get-Date'))->toBe('PS> Get-Date');
});

it('o stderr em CLIXML vira só o texto dos erros, e o progresso some', function () {
    $clixml = "#< CLIXML\r\n<Objs Version=\"1.1.0.1\" xmlns=\"http://schemas.microsoft.com/powershell/2004/04\">"
        . '<Obj S="progress" RefId="0"><TN RefId="0"><T>System.Management.Automation.PSCustomObject</T></TN><MS><I64 N="SourceId">1</I64></MS></Obj>'
        . '<S S="Error">deu &lt;errado&gt;_x000D__x000A_</S><S S="Error">segunda linha_x000D__x000A_</S></Objs>';

    expect(PsTerminalCommand::cleanStderr($clixml))->toBe("deu <errado>\nsegunda linha\n")
        ->and(PsTerminalCommand::cleanStderr("#< CLIXML\r\n<Objs><Obj S=\"progress\"></Obj></Objs>"))->toBe('')
        ->and(PsTerminalCommand::cleanStderr("erro comum\n"))->toBe("erro comum\n");
});

it('a execução vira registro do Windows, com o prefixo, a saída e o código', function () {
    $falso = new TerminalFalso(new PsResult("olá\n", 3, false, 120));

    $execucao = PsTerminalCommand::execute($falso, "  Write-Output 'olá'  ");

    expect($falso->recebidos)->toBe(["Write-Output 'olá'"])
        ->and($execucao->command)->toBe("PS> Write-Output 'olá'")
        ->and($execucao->output)->toBe("olá\n")
        ->and($execucao->exitCode)->toBe(3)
        ->and($execucao->durationMs)->toBe(120)
        ->and($execucao->timedOut)->toBeFalse()
        ->and($execucao->kind)->toBe(ExecutionKind::Windows);
});

it('o tempo esgotado chega ao registro', function () {
    $execucao = PsTerminalCommand::execute(new TerminalFalso(new PsResult("[phporto] TIMEOUT\n", null, true, 60000)), 'Start-Sleep 999');

    expect($execucao->timedOut)->toBeTrue()
        ->and($execucao->exitCode)->toBeNull();
});

it('comando inválido NÃO chega ao PowerShell', function () {
    $falso = new TerminalFalso(new PsResult('', 0, false, 0));

    expect(fn () => PsTerminalCommand::execute($falso, ''))->toThrow(InvalidArgumentException::class);
    expect($falso->recebidos)->toBe([]);
});

it('O TERMINAL NÃO É AÇÃO DA LISTA FECHADA: fora do WinAction e da allowlist do processo elevado', function () {
    $worker = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Win/worker.ps1');
    preg_match('/\$ALLOWLIST\s*=\s*@\{(.*?)\n\}/s', $worker, $bloco);

    expect(WinAction::tryFrom(PsTerminalCommand::ACTION))->toBeNull()
        ->and(WinAction::tryFrom('ps-exec'))->toBeNull()
        ->and($bloco[1] ?? '')->not->toContain("'" . PsTerminalCommand::ACTION . "'")
        ->and($bloco[1] ?? '')->not->toContain("'ps-exec'")
        ->and($worker)->not->toContain('EncodedCommand');
});

it('O TERMINAL NÃO PASSA PELO PROCESSO ELEVADO: nem o runner nem o handler tocam no canal do worker', function () {
    $raiz    = dirname(__DIR__, 2);
    $fontes  = (string) file_get_contents($raiz . '/src/Win/PsTerminalProcess.php')
        . (string) file_get_contents($raiz . '/src/Win/PsTerminalCommand.php');
    $pages   = (string) file_get_contents($raiz . '/src/Http/Pages.php');
    $ini     = (int) strpos($pages, 'private function runTerminal(');
    $handler = substr($pages, $ini, (int) strpos($pages, "\n    }\n", $ini) - $ini);

    expect($handler)->toContain('PsTerminalCommand::execute($this->terminal')
        ->and($handler)->not->toMatch('/elevation|oneShot|JobChannel|uac|worker/i')
        ->and($fontes)->not->toMatch('/JobChannel|Elevation|OneShot|worker\.ps1|-Verb|RunAs/');
});

it('o handler confere o CSRF antes de despachar para o terminal', function () {
    $pages  = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Http/Pages.php');
    $ini    = (int) strpos($pages, 'private function handleWinPost(');
    $corpo  = substr($pages, $ini, (int) strpos($pages, 'private function ', $ini + 10) - $ini);
    $csrf   = strpos($corpo, 'Csrf::consume()');
    $term   = strpos($corpo, 'PsTerminalCommand::ACTION');
    $acao   = strpos($corpo, 'WinAction::tryFrom(');

    expect($csrf)->not->toBeFalse()
        ->and($term)->not->toBeFalse()
        ->and($csrf < $term && $term < $acao)->toBeTrue();
});

it('o runner real captura saída, código e stderr, sem shell no meio', function () {
    $dir = terminalDir();

    try {
        $r = (new PsTerminalProcess($dir, workDir: $dir))->runArgv([
            PHP_BINARY, '-r', 'echo "olá; \$x | rm -rf\n"; fwrite(STDERR, "aviso\n"); exit(7);',
        ]);

        expect($r->output)->toBe("olá; \$x | rm -rf\naviso\n")
            ->and($r->exitCode)->toBe(7)
            ->and($r->timedOut)->toBeFalse()
            ->and($r->truncated)->toBeFalse()
            ->and(glob($dir . '/ps-terminal-*') ?: [])->toBe([]);
    } finally {
        terminalLimpa($dir);
    }
});

it('O TEMPO ESGOTADO MATA O PROCESSO e avisa na saída', function () {
    $dir = terminalDir();

    try {
        $t0 = microtime(true);
        $r  = (new PsTerminalProcess($dir, timeoutSeconds: 1, workDir: $dir))->runArgv([PHP_BINARY, '-r', 'echo "antes\n"; sleep(20);']);

        expect(microtime(true) - $t0)->toBeLessThan(6.0)
            ->and($r->timedOut)->toBeTrue()
            ->and($r->exitCode)->toBeNull()
            ->and($r->output)->toContain("antes\n")
            ->and($r->output)->toContain('[phporto] TIMEOUT: 1s estourados; a árvore de processos foi encerrada.');
    } finally {
        terminalLimpa($dir);
    }
});

it('A SAÍDA É CORTADA NO TETO, com o aviso do corte', function () {
    $dir = terminalDir();

    try {
        $r = (new PsTerminalProcess($dir, maxOutputBytes: 1000, workDir: $dir))->runArgv([PHP_BINARY, '-r', 'echo str_repeat("a", 5000);']);

        expect($r->truncated)->toBeTrue()
            ->and($r->exitCode)->toBe(0)
            ->and($r->output)->toStartWith(str_repeat('a', 1000) . "\n")
            ->and($r->output)->toContain('[phporto] SAÍDA CORTADA no teto de 1000 bytes (o comando gerou 5000).');
    } finally {
        terminalLimpa($dir);
    }
});

it('o teto padrão do terminal é de 256 KiB e 60 s', function () {
    expect(PsTerminalCommand::MAX_OUTPUT_BYTES)->toBe(262144)
        ->and(PsTerminalCommand::TIMEOUT_SECONDS)->toBe(60);
});

it('corte no meio de um caractere não vira lixo: o resto continua UTF-8', function () {
    $dir = terminalDir();

    try {
        $r = (new PsTerminalProcess($dir, maxOutputBytes: 5, workDir: $dir))->runArgv([PHP_BINARY, '-r', 'echo "ééé";']);

        expect(mb_check_encoding($r->output, 'UTF-8'))->toBeTrue()
            ->and($r->output)->toStartWith('éé');
    } finally {
        terminalLimpa($dir);
    }
});

it('executável que não existe vira mensagem, não exceção', function () {
    $dir = terminalDir();

    try {
        $r = (new PsTerminalProcess($dir, executable: $dir . '/nao-existe', workDir: $dir))->run('Get-Date');

        expect($r->exitCode)->toBeNull()
            ->and($r->output)->toContain("[phporto] falha ao iniciar")
            ->and(glob($dir . "/ps-terminal-*") ?: [])->toBe([]);
    } finally {
        terminalLimpa($dir);
    }
});

it('O COMANDO E O CÓDIGO DE SAÍDA ENTRAM NO HISTÓRICO DO /win', function () {
    $provider = new \Tests\Fakes\FakeProvider();
    $service  = new \App\Services\ExecutionLogService($provider);

    $service->record(PsTerminalCommand::execute(new TerminalFalso(new PsResult("erro\n", 1, false, 40)), 'Get-Item nada'));

    expect($provider->inserted)->toHaveCount(1)
        ->and($provider->inserted[0]->command)->toBe('PS> Get-Item nada')
        ->and($provider->inserted[0]->exitCode)->toBe(1)
        ->and($provider->inserted[0]->kind)->toBe(ExecutionKind::Windows)
        ->and(in_array($provider->inserted[0]->kind, [ExecutionKind::Windows], true))->toBeTrue()
        ->and(in_array($provider->inserted[0]->kind, ExecutionKind::WSL, true))->toBeFalse();
});
