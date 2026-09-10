<?php

declare(strict_types=1);

use App\Win\JobChannel;
use App\Win\PsRunner;

/**
 * O recolhimento de execução órfã.
 *
 * POR QUE ISTO EXISTE, e não é hipótese: a espera da tela é síncrona, e quem
 * grava a linha é a requisição que espera. Se ela morrer, o trabalho terminou
 * do outro lado e o registro não aconteceu. O `files/win-worker.log` guarda o
 * caso real — duas execuções de `tweaks` que mexeram no registro e nos
 * serviços do Windows e não existem no histórico.
 *
 * NADA AQUI ELEVA NADA: o worker é simulado escrevendo à mão o par de arquivos
 * que ele deixaria. É o mesmo método do JobChannelTest, e é o que permite
 * cobrir a fila sem um prompt de UAC.
 */

beforeEach(function () {
    $this->files = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phporto_orfa_' . bin2hex(random_bytes(6));
    mkdir($this->files, 0o775, true);
    $this->canal = new JobChannel($this->files);
});

afterEach(function () {
    if (is_string($this->files) && is_dir($this->files)) {
        foreach (glob($this->files . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->files);
    }
});

/**
 * Deixa no disco um par ÓRFÃO: saída e conclusão, com a ação, os parâmetros e
 * a hora de fim que o worker passou a escrever.
 *
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
    $dir = (string) $ctx->files;

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

/** Escreve uma conclusão crua, para os casos que o worker não produz. */
function deixarConclusaoCrua(object $ctx, string $id, string $conteudo): void
{
    file_put_contents((string) $ctx->files . DIRECTORY_SEPARATOR . 'win-done-' . $id . '.json', $conteudo);
}

// ---------------------------------------------------------------- a fila

it('pasta sem par nenhum não recolhe nada', function () {
    // É o caso NORMAL: job registrado apagou o par, então não sobrou fila.
    expect($this->canal->collectOrphans())->toBe([]);
});

it('par completo vira uma órfã, com ação, parâmetros e hora de FIM', function () {
    deixarOrfa($this, 'aaa111', "linha um\nlinha dois\n", 'tweaks', ['Preset' => 'advanced', 'Undo' => true]);

    $orfas = $this->canal->collectOrphans();

    expect($orfas)->toHaveCount(1);

    $orfa = $orfas[0];

    expect($orfa->id)->toBe('aaa111')
        ->and($orfa->acao)->toBe('tweaks')
        ->and($orfa->params)->toBe(['Preset' => 'advanced', 'Undo' => true])
        // A hora é a que o worker gravou, e não "agora".
        ->and($orfa->finishedAt)->toBe('2026-09-09 13:04:14')
        ->and($orfa->result->durationMs)->toBe(116000)
        ->and($orfa->result->exitCode)->toBe(0)
        ->and($orfa->result->output)->toContain('linha um');
});

it('a saída da órfã ABRE com a nota de que foi recuperada depois do fato', function () {
    deixarOrfa($this, 'bbb222', "saida original\n");

    $saida = $this->canal->collectOrphans()[0]->result->output;

    expect($saida)->toStartWith('[phporto] Execução recuperada depois do fato')
        ->and($saida)->toContain('hora real de término')
        ->and($saida)->toContain('saida original');
});

it('win-out SEM win-done não é recolhido: é trabalho em andamento', function () {
    // O worker cria a saída antes de largar o filho, e a conclusão é o único
    // sinal de fim. Recolher só a saída registraria como terminado algo que
    // está rodando com privilégio de Administrador.
    file_put_contents($this->files . DIRECTORY_SEPARATOR . 'win-out-ccc333.txt', 'meio do caminho');

    expect($this->canal->collectOrphans())->toBe([]);
});

it('win-done SEM win-out não é recolhido: o par tem de estar completo', function () {
    deixarConclusaoCrua($this, 'ddd444', (string) json_encode([
        'id' => 'ddd444', 'exit' => 0, 'ms' => 1, 'nota' => '', 'acao' => 'audit',
    ]));

    expect($this->canal->collectOrphans())->toBe([]);
});

it('órfã de OUTRA execução do servidor é recolhida — nonce não filtra aqui', function () {
    // O nonce é guarda de obsolescência para job de ENTRADA. Na saída, o órfão
    // que interessa é justamente o da execução anterior do servidor: é ele que
    // perdeu o registro. A conclusão nem carrega nonce, e é isso que este
    // teste trava — se alguém passar a exigi-lo, ele quebra.
    deixarOrfa($this, 'eee555', "de ontem\n");

    $bruto = json_decode(
        (string) file_get_contents($this->files . DIRECTORY_SEPARATOR . 'win-done-eee555.json'),
        true
    );

    expect($bruto)->toBeArray()
        ->and($bruto)->not->toHaveKey('nonce')
        ->and($this->canal->collectOrphans())->toHaveCount(1);
});

// ---------------------------------------------------------------- as notas

it('a interrupção do worker vira órfã com código NULO, e não é timeout', function () {
    // O caminho medido: o php -S saiu, o worker matou o filho e deixou sinal
    // de fim. Não houve código de saída — nulo é "não se sabe", e não zero.
    deixarOrfa($this, 'fff666', "parcial\n", 'tweaks', ['Preset' => 'advanced'], null, 'interrompido');

    $orfa = $this->canal->collectOrphans()[0];

    expect($orfa->result->exitCode)->toBeNull()
        ->and($orfa->result->timedOut)->toBeFalse();
});

it('órfã cancelada por timeout chega marcada como timedOut', function () {
    deixarOrfa($this, 'ggg777', "cortada\n", 'network', ['Interface' => 'Ethernet'], null, 'cancelado');

    expect($this->canal->collectOrphans()[0]->result->timedOut)->toBeTrue();
});

it('recusa da allowlist do worker chega sem ação, para o rótulo não inventar nome', function () {
    // O worker recusa ANTES de ter algo validado, então não há ação a gravar.
    deixarOrfa($this, 'ooo555', "[phporto] job recusado\n", '', [], 126, 'recusado');

    $orfa = $this->canal->collectOrphans()[0];

    expect($orfa->acao)->toBe('')
        ->and($orfa->params)->toBe([])
        ->and($orfa->result->exitCode)->toBe(126);
});

// ---------------------------------------------------------------- os limites

it('saída de órfã acima do teto é cortada, como em qualquer outra leitura', function () {
    $gigante = str_repeat('x', PsRunner::MAX_OUTPUT_BYTES + 4096);
    deixarOrfa($this, 'hhh888', $gigante);

    $orfa = $this->canal->collectOrphans()[0];

    expect($orfa->result->truncated)->toBeTrue()
        // O teto vale na LEITURA do arquivo. A nota de recuperação é escrita
        // por nós depois, como as outras linhas [phporto].
        ->and(strlen($orfa->result->output))->toBeLessThan(PsRunner::MAX_OUTPUT_BYTES + 2048);
});

it('conclusão sem a hora de fim ainda é recolhida, com finishedAt nulo', function () {
    // Conclusão escrita por um worker anterior a esta mudança. Perder a hora é
    // menos grave que perder a execução: o banco carimba o agora.
    deixarOrfa($this, 'iii999', "velha\n", 'audit', [], 0, '', null);

    $orfa = $this->canal->collectOrphans()[0];

    expect($orfa->finishedAt)->toBeNull()
        ->and($orfa->acao)->toBe('audit');
});

it('conclusão ilegível é ignorada em vez de derrubar a página', function () {
    file_put_contents($this->files . DIRECTORY_SEPARATOR . 'win-out-jjj000.txt', 'x');
    deixarConclusaoCrua($this, 'jjj000', 'isto não é json');

    expect($this->canal->collectOrphans())->toBe([]);
});

it('param aninhado é descartado: o rótulo só monta com escalar', function () {
    file_put_contents($this->files . DIRECTORY_SEPARATOR . 'win-out-kkk111.txt', "x\n");
    deixarConclusaoCrua($this, 'kkk111', (string) json_encode([
        'id'     => 'kkk111',
        'exit'   => 0,
        'ms'     => 1,
        'nota'   => '',
        'acao'   => 'dns',
        'params' => ['Provider' => 'Cloudflare', 'Bicho' => ['a' => 1]],
        'fim'    => '2026-09-09 10:00:00',
    ]));

    expect($this->canal->collectOrphans()[0]->params)->toBe(['Provider' => 'Cloudflare']);
});

// ---------------------------------------------------------------- o descarte

it('discardOrphan apaga os dois arquivos, e só os do id pedido', function () {
    deixarOrfa($this, 'lll222', "a\n");
    deixarOrfa($this, 'mmm333', "b\n");

    $this->canal->discardOrphan('lll222');

    expect(is_file($this->files . DIRECTORY_SEPARATOR . 'win-done-lll222.json'))->toBeFalse()
        ->and(is_file($this->files . DIRECTORY_SEPARATOR . 'win-out-lll222.txt'))->toBeFalse()
        ->and(is_file($this->files . DIRECTORY_SEPARATOR . 'win-done-mmm333.json'))->toBeTrue()
        ->and($this->canal->collectOrphans())->toHaveCount(1);
});

it('recolher NÃO apaga nada por si: quem apaga é quem gravou', function () {
    // A separação é o que impede uma falha de banco de virar perda definitiva
    // da execução — que é exatamente o problema que isto resolve.
    deixarOrfa($this, 'nnn444', "fica\n");

    $this->canal->collectOrphans();

    expect(is_file($this->files . DIRECTORY_SEPARATOR . 'win-done-nnn444.json'))->toBeTrue()
        ->and($this->canal->collectOrphans())->toHaveCount(1);
});

it('duas órfãs no disco viram duas', function () {
    deixarOrfa($this, 'ppp666', "uma\n", 'tweaks', ['Preset' => 'advanced']);
    deixarOrfa($this, 'qqq777', "outra\n", 'debloat', []);

    $acoes = array_map(
        static fn (App\Win\OrphanRun $o): string => $o->acao,
        $this->canal->collectOrphans()
    );

    sort($acoes);

    expect($acoes)->toBe(['debloat', 'tweaks']);
});
