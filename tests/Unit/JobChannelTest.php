<?php

declare(strict_types=1);

use App\Win\Elevation;
use App\Win\JobChannel;
use App\Win\PsResult;
use App\Win\WinAction;

/**
 * O canal de trabalho com o worker elevado.
 *
 * NADA AQUI ELEVA NADA: o worker é simulado escrevendo à mão os arquivos que
 * ele escreveria. É o que permite cobrir a forma do arquivo de trabalho e o
 * caminho de timeout sem depender de um prompt de UAC.
 */

beforeEach(function () {
    $this->files = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phporto_job_' . bin2hex(random_bytes(6));
    mkdir($this->files, 0o775, true);
    // O worker grava os resultados aqui; o job e as ordens ficam em files/.
    $this->protegida = $this->files . DIRECTORY_SEPARATOR . Elevation::DIR_PROTEGIDA;
    mkdir($this->protegida);
    $this->canal = new JobChannel($this->files);
});

afterEach(function () {
    // Duas pastas agora: files/ e a protegida dentro dela.
    if (is_string($this->files) && is_dir($this->files)) {
        foreach ([$this->protegida, $this->files] as $pasta) {
            foreach (glob($pasta . '/*') ?: [] as $f) {
                is_dir($f) ? @rmdir($f) : @unlink($f);
            }
            @rmdir($pasta);
        }
    }
});

/** Lê o arquivo de trabalho como texto cru. */
function jobBruto(object $ctx): string
{
    return (string) file_get_contents((string) $ctx->files . DIRECTORY_SEPARATOR . Elevation::F_JOB);
}

/** Finge o worker: escreve a saída e a conclusão de um id. */
function fingirWorker(object $ctx, string $id, string $saida, ?int $exit = 0, string $nota = ''): void
{
    $dir = (string) $ctx->protegida;
    file_put_contents($dir . DIRECTORY_SEPARATOR . 'win-out-' . $id . '.txt', $saida);
    file_put_contents(
        $dir . DIRECTORY_SEPARATOR . 'win-done-' . $id . '.json',
        (string) json_encode(['id' => $id, 'exit' => $exit, 'ms' => 123, 'nota' => $nota])
    );
}

// ---------------------------------------------------------------- send()

it('send() escreve o arquivo de trabalho com nonce, ação e parâmetros', function () {
    $this->canal->send(WinAction::Dns, ['Provider' => 'Cloudflare'], 'nonce-abc');

    $dados = json_decode(jobBruto($this), true);

    expect($dados)->toBeArray()
        ->and($dados['nonce'])->toBe('nonce-abc')
        ->and($dados['acao'])->toBe('dns')
        ->and($dados['params'])->toBe(['Provider' => 'Cloudflare']);
});

it('AÇÃO SEM PARÂMETRO VAI COMO OBJETO, e nunca como lista', function () {
    // Este teste trava um defeito MEDIDO. Um array PHP vazio virava "[]" no
    // JSON, e PSObject.Properties de um array vazio no PowerShell expõe Count
    // e Length — que chegavam à allowlist do worker como parâmetros
    // inventados e faziam a ação 'audit' ser RECUSADA por
    // "parametro fora da allowlist: 'Count'".
    $this->canal->send(WinAction::Audit, [], 'n');

    expect(jobBruto($this))->toContain('"params":{}')
        ->and(jobBruto($this))->not->toContain('"params":[]');
});

it('send() apaga a conclusão de uma ação anterior antes de mandar', function () {
    // Sem isso, a ação seguinte devolveria na hora o resultado da anterior — e
    // a pessoa leria a saída errada acreditando nela.
    fingirWorker($this, '0a0000000000', 'saida velha');

    $this->canal->send(WinAction::Memory, [], 'n');

    expect(glob($this->protegida . '/win-done-*.json'))->toBe([])
        ->and(glob($this->protegida . '/win-out-*.txt'))->toBe([]);
});

it('send() recusa quando a pasta de trabalho não existe', function () {
    (new JobChannel($this->files . DIRECTORY_SEPARATOR . 'nao-existe'))
        ->send(WinAction::Memory, [], 'n');
})->throws(RuntimeException::class);

// ---------------------------------------------------------------- collect()

it('collect() devolve a saída e o código de saída que o worker escreveu', function () {
    fingirWorker($this, 'abc123000000', "[ OK ] pronto\n", 0);

    $r = $this->canal->collect(10);

    expect($r)->toBeInstanceOf(PsResult::class)
        ->and($r->output)->toBe("[ OK ] pronto\n")
        ->and($r->exitCode)->toBe(0)
        ->and($r->timedOut)->toBeFalse()
        ->and($r->truncated)->toBeFalse()
        ->and($r->durationMs)->toBeGreaterThanOrEqual(0);
});

it('collect() preserva código de saída diferente de zero', function () {
    fingirWorker($this, 'abc124000000', 'falhou', 42);

    expect($this->canal->collect(10)->exitCode)->toBe(42);
});

it('collect() preserva código de saída nulo como nulo', function () {
    fingirWorker($this, 'abc125000000', 'morreu', null);

    expect($this->canal->collect(10)->exitCode)->toBeNull();
});

it('collect() tira o BOM que o PowerShell grava na saída', function () {
    fingirWorker($this, 'abc126000000', "\xEF\xBB\xBF[ INFO ] com BOM\n");

    expect($this->canal->collect(10)->output)->toBe("[ INFO ] com BOM\n");
});

it('collect() limpa os arquivos depois de ler', function () {
    fingirWorker($this, 'abc127000000', 'saida');

    $this->canal->collect(10);

    expect(glob($this->protegida . '/win-done-*.json'))->toBe([])
        ->and(glob($this->protegida . '/win-out-*.txt'))->toBe([]);
});

it('collect() explica na saída quando o worker recusou pela allowlist', function () {
    fingirWorker($this, 'abc128000000', "[phporto] job recusado: acao fora da allowlist\n", 126, 'recusado');

    $r = $this->canal->collect(10);

    expect($r->output)->toContain('acao fora da allowlist')
        ->and($r->output)->toContain('A allowlist do PowerShell elevado recusou')
        ->and($r->exitCode)->toBe(126)
        // Recusa não é timeout: nada foi cancelado.
        ->and($r->timedOut)->toBeFalse();
});

it('collect() marca como timeout quando o worker diz que cancelou', function () {
    fingirWorker($this, 'abc129000000', "saida parcial\n", null, 'cancelado');

    $r = $this->canal->collect(10);

    expect($r->timedOut)->toBeTrue()
        ->and($r->output)->toContain('saida parcial')
        ->and($r->output)->toContain('cancelada pelo PowerShell elevado');
});

// ---------------------------------------------------------------- timeout

it('NO TIMEOUT DEIXA A ORDEM DE CANCELAR, e não tenta matar ninguém', function () {
    // O processo do outro lado está em integridade Alta, e taskkill do PHP
    // contra ele devolve "Acesso negado" (medido, rc=128). Quem mata o filho
    // é o worker, a pedido deste arquivo.
    $r = $this->canal->collect(1);

    expect($r->timedOut)->toBeTrue()
        ->and(is_file($this->files . DIRECTORY_SEPARATOR . Elevation::F_ORDEM_CANCELAR))->toBeTrue()
        ->and($r->output)->toContain('TIMEOUT')
        ->and($r->output)->toContain('não confirmou')
        ->and($r->exitCode)->toBeNull();
});

it('no timeout aproveita a conclusão do cancelamento se ela chegar', function () {
    // O worker obedece no laço de 500 ms — medido em 975 ms. Aqui a conclusão
    // já está no disco, então o caminho de graça a encontra na primeira volta.
    fingirWorker($this, 'abc130000000', "cortada no meio\n", null, 'cancelado');

    $r = $this->canal->collect(10);

    expect($r->timedOut)->toBeTrue()
        ->and($r->output)->toContain('cortada no meio');
});

/*
 * Não há teste de dispatch(): ele é send() seguido de collect(), com as duas
 * metades cobertas acima. Um teste dele pagaria outros quatro segundos de
 * caminho de timeout para exercitar três linhas de encadeamento.
 */

// ---------------------------------------------------------------- teto

it('collect() aplica o teto de saída do PsRunner', function () {
    $grande = str_repeat('z', \App\Domain\OutputCap::MAX_OUTPUT_BYTES + 100);
    fingirWorker($this, 'abc131000000', $grande);

    $r = $this->canal->collect(10);

    expect($r->truncated)->toBeTrue()
        ->and($r->output)->toContain('SAÍDA CORTADA')
        ->and(substr($r->output, 0, \App\Domain\OutputCap::MAX_OUTPUT_BYTES))
        ->toBe(str_repeat('z', \App\Domain\OutputCap::MAX_OUTPUT_BYTES));
});
