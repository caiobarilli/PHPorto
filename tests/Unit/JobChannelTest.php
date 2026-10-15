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
    @chmod((string) $this->protegida, 0o775);

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
function fingirWorker(object $ctx, string $id, string $saida, ?int $exit = 0, string $nota = '', string $acao = ''): void
{
    $dir = (string) $ctx->protegida;
    file_put_contents($dir . DIRECTORY_SEPARATOR . 'win-out-' . $id . '.txt', $saida);
    file_put_contents(
        $dir . DIRECTORY_SEPARATOR . 'win-done-' . $id . '.json',
        (string) json_encode(['id' => $id, 'exit' => $exit, 'ms' => 123, 'nota' => $nota, 'acao' => $acao])
    );
}

function travarProtegida(object $ctx, bool $travar): void
{
    chmod((string) $ctx->protegida, $travar ? 0o555 : 0o775);
    clearstatcache();
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

it('send() NÃO VARRE A PASTA PROTEGIDA: par de outra execução fica para o recolhimento', function () {
    // Varrer tudo antes de mandar apagaria a órfã da /win que ainda não virou
    // linha no banco. A conclusão velha não volta como resposta porque o
    // collect() ignora os ids que já existiam no send().
    fingirWorker($this, '0a0000000000', 'saida velha', 0, '', 'tweaks');

    $this->canal->send(WinAction::Memory, [], 'n');

    expect(is_file($this->protegida . '/win-done-0a0000000000.json'))->toBeTrue()
        ->and(is_file($this->protegida . '/win-out-0a0000000000.txt'))->toBeTrue();
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

// ---------------------------------------------------------------- por id

it('collect() com id espera só aquela conclusão, e ignora a de outro id', function () {
    fingirWorker($this, 'aaa000000001', "a de outro\n");
    fingirWorker($this, 'bbb000000002', "a minha\n", 7);

    $r = $this->canal->collect(10, 'bbb000000002');

    expect($r->output)->toBe("a minha\n")
        ->and($r->exitCode)->toBe(7);
});

it('collect() com id não aceita conclusão de outro id nem no prazo de graça', function () {
    fingirWorker($this, 'aaa000000003', "a de outro\n");

    $r = $this->canal->collect(1, 'bbb000000004', Elevation::F_ORDEM_CANCELAR . '-bbb000000004');

    expect($r->timedOut)->toBeTrue()
        ->and($r->output)->not->toContain('a de outro');
});

it('NO TIMEOUT COM ID, a ordem de cancelar é a daquele id, e não a do worker longo', function () {
    $r = $this->canal->collect(1, 'ccc000000005', Elevation::F_ORDEM_CANCELAR . '-ccc000000005');

    expect($r->timedOut)->toBeTrue()
        ->and(is_file($this->files . DIRECTORY_SEPARATOR . Elevation::F_ORDEM_CANCELAR . '-ccc000000005'))->toBeTrue()
        ->and(is_file($this->files . DIRECTORY_SEPARATOR . Elevation::F_ORDEM_CANCELAR))->toBeFalse();
});

// ---------------------------------------------------------------- sem id, amarrado ao send()

it('A /hyperv LÊ A PRÓPRIA CONCLUSÃO mesmo com par de processes que não sai da pasta', function () {
    // Caso medido no disco: a limpeza não apagou, e o primeiro win-done do
    // glob era de processes. A leitura do hyperv voltava "formato inesperado"
    // com o JSON certo do hyperv na mesma pasta.
    fingirWorker($this, '07cc00000000', "PID Nome\n", 0, '', 'processes');
    travarProtegida($this, true);

    $this->canal->send(WinAction::Hyperv, [], 'n');

    travarProtegida($this, false);
    fingirWorker($this, 'aea600000000', '{"hyperv":true,"vms":[]}', 0, '', 'hyperv');
    travarProtegida($this, true);

    $r = $this->canal->collect(5);

    expect($r->output)->toStartWith('{"hyperv":true,"vms":[]}')
        ->and($r->output)->not->toContain('PID Nome')
        ->and($r->exitCode)->toBe(0)
        ->and($r->timedOut)->toBeFalse();
});

it('collect() sem id ignora conclusão nova de outra ação e espera a pedida', function () {
    $this->canal->send(WinAction::Hyperv, [], 'n');

    fingirWorker($this, '0b0000000000', "outra\n", 0, '', 'processes');
    fingirWorker($this, '0c0000000000', '{"hyperv":true,"vms":[]}', 0, '', 'hyperv');

    expect($this->canal->collect(5)->output)->toBe('{"hyperv":true,"vms":[]}');
});

it('collect() sem id ignora conclusão da mesma ação que já existia no send()', function () {
    fingirWorker($this, '0d0000000000', 'velha', 0, '', 'hyperv');

    $this->canal->send(WinAction::Hyperv, [], 'n');

    fingirWorker($this, '0e0000000000', 'nova', 0, '', 'hyperv');

    expect($this->canal->collect(5)->output)->toBe('nova');
});

it('collect() sem id ainda aceita a recusa do worker, que sai sem ação', function () {
    $this->canal->send(WinAction::Hyperv, [], 'n');

    fingirWorker($this, '0f0000000000', "[phporto] job recusado pela allowlist do worker: x\n", 126, 'recusado');

    $r = $this->canal->collect(5);

    expect($r->exitCode)->toBe(126)
        ->and($r->output)->toContain('A allowlist do PowerShell elevado recusou');
});

it('collect() apaga só o par daquela leitura', function () {
    fingirWorker($this, '1a0000000000', 'orfa da win', 0, '', 'tweaks');

    $this->canal->send(WinAction::Hyperv, [], 'n');

    fingirWorker($this, '1b0000000000', '{"hyperv":true,"vms":[]}', 0, '', 'hyperv');

    $this->canal->collect(5);

    expect(is_file($this->protegida . '/win-done-1a0000000000.json'))->toBeTrue()
        ->and(is_file($this->protegida . '/win-out-1a0000000000.txt'))->toBeTrue()
        ->and(is_file($this->protegida . '/win-done-1b0000000000.json'))->toBeFalse()
        ->and(is_file($this->protegida . '/win-out-1b0000000000.txt'))->toBeFalse();
});

it('QUANDO O PAR NÃO SAI DA PASTA, a saída diz, e o teste vê', function () {
    $this->canal->send(WinAction::Hyperv, [], 'n');

    fingirWorker($this, '1c0000000000', '{"hyperv":true,"vms":[]}', 0, '', 'hyperv');
    travarProtegida($this, true);

    $r = $this->canal->collect(5);

    expect($r->output)->toContain('Não foi possível apagar win-done-1c0000000000.json, win-out-1c0000000000.txt')
        ->and($this->canal->cleanupFailures())->toBe(['win-done-1c0000000000.json', 'win-out-1c0000000000.txt'])
        ->and(\App\Win\HypervListing::fromOutput($r->output)->problem)->toBeNull();
});

it('collect() recusa id fora do formato antes de montar caminho', function () {
    $this->canal->collect(1, '../../x');
})->throws(RuntimeException::class, 'fora do formato');

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
