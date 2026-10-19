<?php

declare(strict_types=1);

use App\Domain\OutputCap;
use App\Win\Elevation;
use App\Win\JobChannel;

beforeEach(function () {
    $this->files = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phporto_orfa_' . bin2hex(random_bytes(6));
    mkdir($this->files, 0o775, true);
    $this->protegida = $this->files . DIRECTORY_SEPARATOR . Elevation::DIR_PROTEGIDA;
    mkdir($this->protegida);
    $this->canal = new JobChannel($this->files);
});

afterEach(function () {
    if (is_string($this->files) && is_dir($this->files)) {
        foreach ([$this->protegida, $this->files] as $pasta) {
            foreach (glob($pasta . '/*') ?: [] as $f) {
                is_dir($f) ? @rmdir($f) : @unlink($f);
            }
            @rmdir($pasta);
        }
    }
});

/**
 * @param array<string, string|int|bool> $params
 */
function deixarOrfa(
    object $ctx,
    string $id,
    string $saida,
    string $acao = 'gdid',
    array $params = ['SubAction' => 'status'],
    ?int $exit = 0,
    string $nota = '',
    ?string $fim = '2026-09-09 13:04:14',
    int $ms = 116000,
): void {
    $dir = (string) $ctx->protegida;

    file_put_contents($dir . DIRECTORY_SEPARATOR . 'win-out-' . $id . '.txt', $saida);

    $dados = [
        'id'     => $id,
        'exit'   => $exit,
        'ms'     => $ms,
        'nota'   => $nota,
        'acao'   => $acao,
        'params' => $params === [] ? new stdClass() : $params,
    ];

    if ($fim !== null) {
        $dados['fim'] = $fim;
    }

    file_put_contents($dir . DIRECTORY_SEPARATOR . 'win-done-' . $id . '.json', (string) json_encode($dados));
}

function deixarConclusaoCrua(object $ctx, string $id, string $conteudo): void
{
    file_put_contents((string) $ctx->protegida . DIRECTORY_SEPARATOR . 'win-done-' . $id . '.json', $conteudo);
}

it('pasta sem par nenhum não recolhe nada', function () {
    expect($this->canal->collectOrphans())->toBe([]);
});

it('par completo vira uma órfã, com ação, parâmetros e hora de FIM', function () {
    deixarOrfa($this, 'aaa111000000', "linha um\nlinha dois\n", 'tweaks', ['Preset' => 'advanced', 'Undo' => true]);

    $orfas = $this->canal->collectOrphans();

    expect($orfas)->toHaveCount(1);

    $orfa = $orfas[0];

    expect($orfa->id)->toBe('aaa111000000')
        ->and($orfa->acao)->toBe('tweaks')
        ->and($orfa->params)->toBe(['Preset' => 'advanced', 'Undo' => true])
        ->and($orfa->finishedAt)->toBe('2026-09-09 13:04:14')
        ->and($orfa->result->durationMs)->toBe(116000)
        ->and($orfa->result->exitCode)->toBe(0)
        ->and($orfa->result->output)->toContain('linha um');
});

it('a saída da órfã ABRE com a nota de que foi recuperada depois do fato', function () {
    deixarOrfa($this, 'bbb222000000', "saida original\n");

    $saida = $this->canal->collectOrphans()[0]->result->output;

    expect($saida)->toStartWith('[phporto] Execução recuperada depois do fato')
        ->and($saida)->toContain('hora real de término')
        ->and($saida)->toContain('saida original');
});

it('win-out SEM win-done não é recolhido: é trabalho em andamento', function () {
    file_put_contents($this->protegida . DIRECTORY_SEPARATOR . 'win-out-ccc333.txt', 'meio do caminho');

    expect($this->canal->collectOrphans())->toBe([]);
});

it('win-done SEM win-out não é recolhido: o par tem de estar completo', function () {
    deixarConclusaoCrua($this, 'ddd444000000', (string) json_encode([
        'id' => 'ddd444000000', 'exit' => 0, 'ms' => 1, 'nota' => '', 'acao' => 'audit',
    ]));

    expect($this->canal->collectOrphans())->toBe([]);
});

it('órfã de OUTRA execução do servidor é recolhida — nonce não filtra aqui', function () {
    deixarOrfa($this, 'eee555000000', "de ontem\n");

    $bruto = json_decode(
        (string) file_get_contents($this->protegida . DIRECTORY_SEPARATOR . 'win-done-eee555000000.json'),
        true
    );

    expect($bruto)->toBeArray()
        ->and($bruto)->not->toHaveKey('nonce')
        ->and($this->canal->collectOrphans())->toHaveCount(1);
});

it('a interrupção do worker vira órfã com código NULO, e não é timeout', function () {
    deixarOrfa($this, 'fff666000000', "parcial\n", 'tweaks', ['Preset' => 'advanced'], null, 'interrompido');

    $orfa = $this->canal->collectOrphans()[0];

    expect($orfa->result->exitCode)->toBeNull()
        ->and($orfa->result->timedOut)->toBeFalse();
});

it('órfã cancelada por timeout chega marcada como timedOut', function () {
    deixarOrfa($this, '111777000000', "cortada\n", 'network', ['Interface' => 'Ethernet'], null, 'cancelado');

    expect($this->canal->collectOrphans()[0]->result->timedOut)->toBeTrue();
});

it('recusa da allowlist do worker chega sem ação, para o rótulo não inventar nome', function () {
    deixarOrfa($this, '999555000000', "[phporto] job recusado\n", '', [], 126, 'recusado');

    $orfa = $this->canal->collectOrphans()[0];

    expect($orfa->acao)->toBe('')
        ->and($orfa->params)->toBe([])
        ->and($orfa->result->exitCode)->toBe(126);
});

it('saída de órfã acima do teto é cortada, como em qualquer outra leitura', function () {
    $gigante = str_repeat('x', OutputCap::MAX_OUTPUT_BYTES + 4096);
    deixarOrfa($this, '222888000000', $gigante);

    $orfa = $this->canal->collectOrphans()[0];

    expect($orfa->result->truncated)->toBeTrue()
        ->and(strlen($orfa->result->output))->toBeLessThan(OutputCap::MAX_OUTPUT_BYTES + 2048);
});

it('conclusão sem a hora de fim ainda é recolhida, com finishedAt nulo', function () {
    deixarOrfa($this, '333999000000', "velha\n", 'audit', [], 0, '', null);

    $orfa = $this->canal->collectOrphans()[0];

    expect($orfa->finishedAt)->toBeNull()
        ->and($orfa->acao)->toBe('audit');
});

it('conclusão ilegível é ignorada em vez de derrubar a página', function () {
    file_put_contents($this->protegida . DIRECTORY_SEPARATOR . 'win-out-444000000000.txt', 'x');
    deixarConclusaoCrua($this, '444000000000', 'isto não é json');

    expect($this->canal->collectOrphans())->toBe([]);
});

it('param aninhado é descartado: o rótulo só monta com escalar', function () {
    file_put_contents($this->protegida . DIRECTORY_SEPARATOR . 'win-out-555111000000.txt', "x\n");
    deixarConclusaoCrua($this, '555111000000', (string) json_encode([
        'id'     => '555111000000',
        'exit'   => 0,
        'ms'     => 1,
        'nota'   => '',
        'acao'   => 'dns',
        'params' => ['Provider' => 'Cloudflare', 'Bicho' => ['a' => 1]],
        'fim'    => '2026-09-09 10:00:00',
    ]));

    expect($this->canal->collectOrphans()[0]->params)->toBe(['Provider' => 'Cloudflare']);
});

it('lista de itens do worker volta como texto separado por vírgula', function () {
    file_put_contents($this->protegida . DIRECTORY_SEPARATOR . 'win-out-666111000000.txt', "x\n");
    deixarConclusaoCrua($this, '666111000000', (string) json_encode([
        'id'     => '666111000000',
        'exit'   => 0,
        'ms'     => 1,
        'nota'   => '',
        'acao'   => 'tweaks',
        'params' => ['Items' => ['WPFTweaksTelemetry', 'WPFTweaksServices'], 'Undo' => true, 'Misto' => ['a', 1]],
        'fim'    => '2026-09-09 10:00:00',
    ]));

    expect($this->canal->collectOrphans()[0]->params)
        ->toBe(['Items' => 'WPFTweaksTelemetry,WPFTweaksServices', 'Undo' => true]);
});

it('discardOrphan apaga os dois arquivos, e só os do id pedido', function () {
    deixarOrfa($this, '666222000000', "a\n");
    deixarOrfa($this, '777333000000', "b\n");

    $this->canal->discardOrphan('666222000000');

    expect(is_file($this->protegida . DIRECTORY_SEPARATOR . 'win-done-666222000000.json'))->toBeFalse()
        ->and(is_file($this->protegida . DIRECTORY_SEPARATOR . 'win-out-666222000000.txt'))->toBeFalse()
        ->and(is_file($this->protegida . DIRECTORY_SEPARATOR . 'win-done-777333000000.json'))->toBeTrue()
        ->and($this->canal->collectOrphans())->toHaveCount(1);
});

it('recolher NÃO apaga nada por si: quem apaga é quem gravou', function () {
    deixarOrfa($this, '888444000000', "fica\n");

    $this->canal->collectOrphans();

    expect(is_file($this->protegida . DIRECTORY_SEPARATOR . 'win-done-888444000000.json'))->toBeTrue()
        ->and($this->canal->collectOrphans())->toHaveCount(1);
});

it('duas órfãs no disco viram duas', function () {
    deixarOrfa($this, 'aaa666000000', "uma\n", 'tweaks', ['Preset' => 'advanced']);
    deixarOrfa($this, 'bbb777000000', "outra\n", 'debloat', []);

    $acoes = array_map(
        static fn (App\Win\OrphanRun $o): string => $o->acao,
        $this->canal->collectOrphans()
    );

    sort($acoes);

    expect($acoes)->toBe(['debloat', 'tweaks']);
});

it('o id só vale no formato que o worker gera', function (string $id, bool $valido) {
    expect(JobChannel::validId($id))->toBe($valido);
})->with([
    'doze hex minúsculos' => ['0123456789ab', true],
    'maiúscula'           => ['0123456789AB', false],
    'onze'                => ['0123456789a', false],
    'treze'               => ['0123456789abc', false],
    'quebra de linha'     => ["0123456789ab\n", false],
    'vazio'               => ['', false],
    'subindo pasta'       => ['../../../x', false],
]);

it('conclusão com id fora do formato é ignorada, sem montar caminho', function () {
    deixarConclusaoCrua($this, 'forjado', (string) json_encode([
        'id' => '../../../segredo', 'exit' => 0, 'ms' => 1, 'nota' => '', 'acao' => 'audit', 'params' => new stdClass(),
    ]));

    expect($this->canal->collectOrphans())->toBe([]);
});

it('conclusão cujo nome não é o do id é ignorada', function () {
    deixarOrfa($this, 'bbb222000000', "de outro\n");
    unlink($this->protegida . DIRECTORY_SEPARATOR . 'win-done-bbb222000000.json');
    deixarConclusaoCrua($this, 'aaa111000000', (string) json_encode([
        'id' => 'bbb222000000', 'exit' => 0, 'ms' => 1, 'nota' => '', 'acao' => 'audit', 'params' => new stdClass(),
    ]));

    expect($this->canal->collectOrphans())->toBe([]);
});

it('discardOrphan com id forjado não apaga nada fora da pasta protegida', function () {
    $fora = $this->files . DIRECTORY_SEPARATOR . 'phporto_alvo_' . bin2hex(random_bytes(6));
    mkdir($this->protegida . DIRECTORY_SEPARATOR . 'win-done-..');
    mkdir($this->protegida . DIRECTORY_SEPARATOR . 'win-out-..');
    file_put_contents($fora . '.txt', 'nao apagar');
    file_put_contents($fora . '.json', 'nao apagar');

    try {
        $this->canal->discardOrphan('../../../' . basename($fora));

        expect(is_file($fora . '.txt'))->toBeTrue()
            ->and(is_file($fora . '.json'))->toBeTrue();
    } finally {
        @unlink($fora . '.txt');
        @unlink($fora . '.json');
        @rmdir($this->protegida . DIRECTORY_SEPARATOR . 'win-done-..');
        @rmdir($this->protegida . DIRECTORY_SEPARATOR . 'win-out-..');
    }
});

it('conclusão forjada em files/, fora da pasta protegida, nem é olhada', function () {
    $dados = ['id' => 'ccc333000000', 'exit' => 0, 'ms' => 1, 'nota' => '', 'acao' => 'audit', 'params' => new stdClass()];
    file_put_contents($this->files . DIRECTORY_SEPARATOR . 'win-done-ccc333000000.json', (string) json_encode($dados));
    file_put_contents($this->files . DIRECTORY_SEPARATOR . 'win-out-ccc333000000.txt', "forjada\n");

    expect($this->canal->collectOrphans())->toBe([]);
});
