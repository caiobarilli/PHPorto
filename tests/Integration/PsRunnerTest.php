<?php

declare(strict_types=1);

use App\Domain\OutputCap;
use App\Win\PsResult;
use App\Win\PsRunner;
use App\Win\PsScriptBuilder;

/**
 * Testes de integração do PsRunner contra o powershell.exe de verdade.
 *
 * Pulados fora do Windows, ou sem powershell.exe no PATH: o motor existe para
 * falar com o PowerShell, e um teste que o simulasse não provaria nada sobre
 * encoding, código de saída ou timeout — que é tudo o que pode dar errado
 * aqui.
 *
 * Nenhum destes testes eleva nada. O que exige integridade Alta é o worker, e
 * o worker não passa por esta classe.
 */

beforeEach(function () {
    if (DIRECTORY_SEPARATOR === '/') {
        $this->markTestSkipped('PsRunner é do lado Windows; sem powershell.exe fora dele.');
    }

    $saida = [];
    $rc    = 0;
    @exec('powershell.exe -NoProfile -Command "exit 0" 2>&1', $saida, $rc);

    if ($rc !== 0) {
        $this->markTestSkipped('powershell.exe não respondeu nesta máquina.');
    }

    $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phporto_run_' . bin2hex(random_bytes(6));
    mkdir($this->dir, 0o775, true);
});

afterEach(function () {
    if (is_string($this->dir) && is_dir($this->dir)) {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }
});

/** Grava um script no diretório do teste e devolve o caminho. */
function psScript(string $dir, string $body): string
{
    $caminho = $dir . DIRECTORY_SEPARATOR . 'teste.ps1';
    PsScriptBuilder::write($caminho, $body);

    return $caminho;
}

it('executa e devolve a saída', function () {
    $runner = new PsRunner($this->dir);

    $r = $runner->run(psScript($this->dir, 'Write-Output "ola do powershell"'), 30);

    expect($r)->toBeInstanceOf(PsResult::class)
        ->and($r->output)->toContain('ola do powershell')
        ->and($r->exitCode)->toBe(0)
        ->and($r->timedOut)->toBeFalse()
        ->and($r->truncated)->toBeFalse()
        ->and($r->durationMs)->toBeGreaterThan(0);
});

it('captura Write-Host, que é por onde as ações escrevem', function () {
    // As ações do Windows escrevem tudo por Write-Host. Se isso não fosse capturado,
    // a tela mostraria saída vazia para uma ação que funcionou.
    $runner = new PsRunner($this->dir);

    $r = $runner->run(psScript($this->dir, 'Write-Host "[ OK ] via Write-Host"'), 30);

    expect($r->output)->toContain('[ OK ] via Write-Host');
});

it('preserva acentuação na volta', function () {
    $runner = new PsRunner($this->dir);

    $r = $runner->run(psScript($this->dir, <<<'PS'
[Console]::OutputEncoding = [System.Text.UTF8Encoding]::new($true)
Write-Output "configuração, execução, atenção"
PS), 30);

    expect($r->output)->toContain('configuração, execução, atenção')
        ->and(mb_check_encoding($r->output, 'UTF-8'))->toBeTrue();
});

it('propaga o código de saída', function () {
    $runner = new PsRunner($this->dir);

    expect($runner->run(psScript($this->dir, 'exit 42'), 30)->exitCode)->toBe(42);
});

it('mata no timeout e diz na saída que matou', function () {
    $runner = new PsRunner($this->dir);

    $r = $runner->run(psScript($this->dir, 'Start-Sleep -Seconds 30'), 2);

    expect($r->timedOut)->toBeTrue()
        ->and($r->output)->toContain('[phporto] TIMEOUT')
        ->and($r->durationMs)->toBeLessThan(15000);
});

it('normaliza CRLF da saída para LF', function () {
    $runner = new PsRunner($this->dir);

    $r = $runner->run(psScript($this->dir, "Write-Output 'a'\nWrite-Output 'b'"), 30);

    expect($r->output)->not->toContain("\r");
});

/*
|--------------------------------------------------------------------------
| Teto de saída
|--------------------------------------------------------------------------
|
| O teto é o OutputCap, o mesmo do Runner do WSL. Aqui se testa a leitura do
| lado Windows: corte, aviso e BOM.
|
*/

it('o teto é 1 MiB, em constante nomeada', function () {
    expect(OutputCap::MAX_OUTPUT_BYTES)->toBe(1048576);
});

it('readBounded corta no teto e avisa dentro da própria saída', function () {
    // O aviso vai na saída, e não só num campo: quem lê na tela precisa ver
    // ali que ela não está inteira.
    $arquivo = $this->dir . DIRECTORY_SEPARATOR . 'grande.txt';
    file_put_contents($arquivo, str_repeat('x', 5000));

    [$texto, $cortou] = PsRunner::readBounded($arquivo, 1000);

    expect($cortou)->toBeTrue()
        ->and($texto)->toContain('[phporto] SAÍDA CORTADA no teto de 1000 bytes')
        ->and($texto)->toContain('(o comando gerou 5000)')
        ->and(substr_count($texto, 'x'))->toBe(1000);
});

it('readBounded não corta o que cabe, e não avisa nada', function () {
    $arquivo = $this->dir . DIRECTORY_SEPARATOR . 'pequeno.txt';
    file_put_contents($arquivo, "linha\n");

    [$texto, $cortou] = PsRunner::readBounded($arquivo, 1000);

    expect($cortou)->toBeFalse()
        ->and($texto)->toBe("linha\n")
        ->and($texto)->not->toContain('CORTADA');
});

it('readBounded corta no teto REAL de 1 MiB', function () {
    $arquivo = $this->dir . DIRECTORY_SEPARATOR . 'muito-grande.txt';
    file_put_contents($arquivo, str_repeat('y', OutputCap::MAX_OUTPUT_BYTES + 4096));

    [$texto, $cortou] = PsRunner::readBounded($arquivo);

    // Compara o CORPO, e não conta ocorrências no texto todo: a palavra
    // "bytes" do próprio aviso tem um 'y', e contar no texto inteiro devolve
    // um a mais — foi o que este teste acusou na primeira execução.
    expect($cortou)->toBeTrue()
        ->and(substr($texto, 0, OutputCap::MAX_OUTPUT_BYTES))->toBe(str_repeat('y', OutputCap::MAX_OUTPUT_BYTES))
        ->and($texto)->toContain('SAÍDA CORTADA');
});

it('readBounded tira o BOM que o PowerShell grava', function () {
    // O Set-Content -Encoding UTF8 do 5.1 grava BOM. Sem tirar, os três bytes
    // aparecem colados na primeira palavra da saída na tela.
    $arquivo = $this->dir . DIRECTORY_SEPARATOR . 'com-bom.txt';
    file_put_contents($arquivo, PsScriptBuilder::BOM . 'PID=123');

    [$texto] = PsRunner::readBounded($arquivo);

    expect($texto)->toBe('PID=123');
});

it('readBounded devolve vazio sem estourar quando o arquivo não existe', function () {
    [$texto, $cortou] = PsRunner::readBounded($this->dir . DIRECTORY_SEPARATOR . 'nao-existe.txt');

    expect($texto)->toBe('')
        ->and($cortou)->toBeFalse();
});

it('recusa script que não existe em vez de chamar o powershell à toa', function () {
    (new PsRunner($this->dir))->run($this->dir . DIRECTORY_SEPARATOR . 'fantasma.ps1', 30);
})->throws(RuntimeException::class);
