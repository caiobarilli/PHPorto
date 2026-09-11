<?php

declare(strict_types=1);

use App\Win\WinAction;

/**
 * A regra de transição: o que uma execução bem-sucedida significa para o
 * estado aplicado.
 *
 * A TABELA DAS QUATRO REVERSÍVEIS está aqui e no docblock do stateChange(), e
 * o que ela guarda vem de medição nas próprias ações — o -Undo do tweaks exige
 * -Preset, o do optimize não exige nada, o gdid reverte por subação e o
 * performance por -State.
 */
it('tweaks aplicado guarda o PRESET, porque o -Undo exige ele de volta', function () {
    // Medido no Invoke-Tweaks: o -Undo lê a lista daquele preset no
    // preset.json e reverte item por item. Reverter sem saber o preset é
    // impossível, e essa informação não existe em nenhum outro lugar — é a
    // razão mais forte para este estado existir.
    $mudanca = WinAction::Tweaks->stateChange(['Preset' => 'standard']);

    expect($mudanca)->not->toBeNull()
        ->and($mudanca->applied)->toBeTrue()
        ->and($mudanca->payload)->toBe(['Preset' => 'standard']);
});

it('tweaks com -Undo esquece a linha em vez de gravar "não aplicado"', function () {
    // Ausência e "não aplicado" têm de significar a mesma coisa, senão passam
    // a existir dois jeitos de dizer o mesmo.
    $mudanca = WinAction::Tweaks->stateChange(['Preset' => 'standard', 'Undo' => true]);

    expect($mudanca->applied)->toBeFalse();
});

it('optimize segue a mesma regra do tweaks', function () {
    // O -Undo do Invoke-Optimize NÃO precisa de parâmetro: ele lê o próprio
    // C:\WinUtil\optimize-state.json e recusa sem ele. O preset fica no
    // payload só para a tela poder dizer o que será revertido.
    expect(WinAction::Optimize->stateChange(['Preset' => 'ssh'])->applied)->toBeTrue()
        ->and(WinAction::Optimize->stateChange(['Preset' => 'ssh'])->payload)->toBe(['Preset' => 'ssh'])
        ->and(WinAction::Optimize->stateChange(['Kill' => 'notepad', 'Undo' => true])->applied)->toBeFalse();
});

it('optimize sem preset guarda payload vazio, sem inventar valor', function () {
    // A ação aceita só -Kill, sem preset. Gravar um preset que ninguém
    // escolheu seria pôr no estado o que não aconteceu.
    expect(WinAction::Optimize->stateChange(['Kill' => 'notepad'])->payload)->toBe([]);
});

it('gdid aplica com disable e reverte com enable, sem -Undo', function () {
    // Não há -Undo: são duas subações. O estado real também vive em
    // C:\WinUtil\gdid-state.json, escrito pela própria ação.
    expect(WinAction::Gdid->stateChange(['SubAction' => 'disable'])->applied)->toBeTrue()
        ->and(WinAction::Gdid->stateChange(['SubAction' => 'enable'])->applied)->toBeFalse();
});

it('GDID STATUS NÃO AFIRMA NADA: devolve null para a linha ficar como está', function () {
    // Null não é "não aplicado". Quem recebe null não mexe no que está
    // guardado — consultar o estado não pode apagá-lo.
    expect(WinAction::Gdid->stateChange(['SubAction' => 'status']))->toBeNull();
});

it('performance sem State conta como aplicado, porque o default declarado é on', function () {
    // O Invoke-Performance declara -State [ValidateSet('on','off')] com
    // default 'on'. Hoje State não está nas allowlists, então só este caminho
    // chega aqui.
    expect(WinAction::Performance->stateChange([])->applied)->toBeTrue();
});

it('performance com State off reverte, e a regra já está pronta para a R5', function () {
    // Escrita antes de State entrar nas allowlists, de propósito: deixar o
    // caminho de reverter pela metade é o que faz a fatia seguinte descobrir
    // tarde que falta um lado.
    expect(WinAction::Performance->stateChange(['State' => 'off'])->applied)->toBeFalse()
        ->and(WinAction::Performance->stateChange(['State' => 'on'])->applied)->toBeTrue();
});

it('AS NOVE AÇÕES NÃO REVERSÍVEIS não afirmam nada sobre estado', function (WinAction $acao) {
    expect($acao->stateChange([]))->toBeNull();
})->with([
    'audit'     => [WinAction::Audit],
    'debloat'   => [WinAction::Debloat],
    'dns'       => [WinAction::Dns],
    'install'   => [WinAction::Install],
    'memory'    => [WinAction::Memory],
    'network'   => [WinAction::Network],
    'exporter'  => [WinAction::Exporter],
    'processes' => [WinAction::Processes],
    'gpu'       => [WinAction::Gpu],
]);

it('toda ação do enum passa pelo stateChange sem estourar', function () {
    // O match do stateChange() tem `default`, então ação nova não quebra a
    // chamada — mas também não ganha estado sem alguém decidir. Este teste
    // existe para a próxima ação não precisar tocar aqui, e para nenhuma
    // combinação de parâmetro vazio lançar.
    foreach (WinAction::cases() as $acao) {
        expect(fn () => $acao->stateChange([]))->not->toThrow(Throwable::class);
    }
});
