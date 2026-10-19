<?php

declare(strict_types=1);

use App\Win\WinAction;

it('tweaks por preset guarda o PRESET, que o -Undo aceita de volta', function () {
    $mudanca = WinAction::Tweaks->stateChange(['Preset' => 'standard']);

    expect($mudanca)->not->toBeNull()
        ->and($mudanca->applied)->toBeTrue()
        ->and($mudanca->payload)->toBe(['Preset' => 'standard']);
});

it('tweaks com -Undo esquece a linha em vez de gravar "não aplicado"', function () {
    $mudanca = WinAction::Tweaks->stateChange(['Preset' => 'standard', 'Undo' => true]);

    expect($mudanca->applied)->toBeFalse();
});

it('optimize segue a mesma regra do tweaks', function () {
    expect(WinAction::Optimize->stateChange(['Preset' => 'ssh'])->applied)->toBeTrue()
        ->and(WinAction::Optimize->stateChange(['Preset' => 'ssh'])->payload)->toBe(['Preset' => 'ssh'])
        ->and(WinAction::Optimize->stateChange(['Kill' => 'notepad', 'Undo' => true])->applied)->toBeFalse();
});

it('optimize sem preset guarda payload vazio, sem inventar valor', function () {
    expect(WinAction::Optimize->stateChange(['Kill' => 'notepad'])->payload)->toBe([]);
});

it('gdid aplica com disable e reverte com enable, sem -Undo', function () {
    expect(WinAction::Gdid->stateChange(['SubAction' => 'disable'])->applied)->toBeTrue()
        ->and(WinAction::Gdid->stateChange(['SubAction' => 'enable'])->applied)->toBeFalse();
});

it('GDID STATUS NÃO AFIRMA NADA: devolve null para a linha ficar como está', function () {
    expect(WinAction::Gdid->stateChange(['SubAction' => 'status']))->toBeNull();
});

it('performance sem State conta como aplicado, porque o default declarado é on', function () {
    expect(WinAction::Performance->stateChange([])->applied)->toBeTrue();
});

it('performance com State off reverte, e a regra já está pronta para a R5', function () {
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
    foreach (WinAction::cases() as $acao) {
        expect(fn () => $acao->stateChange([]))->not->toThrow(Throwable::class);
    }
});

it('tweaks por itens guarda os ITENS, que o -Undo precisa de volta', function () {
    $mudanca = WinAction::Tweaks->stateChange(['Items' => 'WPFTweaksTelemetry,WPFTweaksServices']);

    expect($mudanca->applied)->toBeTrue()
        ->and($mudanca->payload)->toBe(['Items' => 'WPFTweaksTelemetry,WPFTweaksServices']);
});

it('aplicar tweaks SOMA ao conjunto guardado, sem repetir', function () {
    $mudanca = WinAction::Tweaks->stateChange(['Items' => 'B,C']);

    expect(WinAction::Tweaks->mergeState(['Items' => 'A,B'], $mudanca, []))->toBe(['Items' => 'A,B,C']);
});

it('reverter tweaks SUBTRAI do conjunto guardado', function () {
    $mudanca = WinAction::Tweaks->stateChange(['Items' => 'A', 'Undo' => true]);

    expect(WinAction::Tweaks->mergeState(['Items' => 'A,B,C'], $mudanca, []))->toBe(['Items' => 'B,C']);
});

it('reverter o que sobrava esquece a linha', function () {
    $mudanca = WinAction::Tweaks->stateChange(['Items' => 'A,B', 'Undo' => true]);

    expect(WinAction::Tweaks->mergeState(['Items' => 'A,B'], $mudanca, []))->toBeNull();
});

it('um Preset guardado conta como as chaves dele', function () {
    $presets = ['standard' => ['A', 'B']];
    $mudanca = WinAction::Tweaks->stateChange(['Items' => 'B', 'Undo' => true]);

    expect(WinAction::Tweaks->mergeState(['Preset' => 'standard'], $mudanca, $presets))->toBe(['Items' => 'A']);
});

it('aplicar sem nada guardado grava só o desta vez', function () {
    $mudanca = WinAction::Tweaks->stateChange(['Preset' => 'minimal']);

    expect(WinAction::Tweaks->mergeState(null, $mudanca, ['minimal' => ['X', 'Y']]))->toBe(['Items' => 'X,Y']);
});

it('nas outras ações a mudança substitui o guardado', function () {
    $aplica = WinAction::Optimize->stateChange(['Preset' => 'ssh']);
    $desfaz = WinAction::Optimize->stateChange(['Undo' => true]);

    expect(WinAction::Optimize->mergeState(['Preset' => 'kill-rdp'], $aplica, []))->toBe(['Preset' => 'ssh'])
        ->and(WinAction::Optimize->mergeState(['Preset' => 'ssh'], $desfaz, []))->toBeNull();
});

it('tweakKeysOf lê Items, Preset, e devolve vazio sem linha', function () {
    $presets = ['standard' => ['A', 'B']];

    expect(WinAction::tweakKeysOf(['Items' => 'A, B,,C'], $presets))->toBe(['A', 'B', 'C'])
        ->and(WinAction::tweakKeysOf(['Preset' => 'Standard'], $presets))->toBe(['A', 'B'])
        ->and(WinAction::tweakKeysOf(['Preset' => 'sumiu'], $presets))->toBe([])
        ->and(WinAction::tweakKeysOf(null, $presets))->toBe([]);
});

it('o botão dos tweaks só reverte quando TODAS as marcadas estão aplicadas', function () {
    expect(WinAction::tweaksRevert(['A', 'B'], ['A', 'B', 'C']))->toBeTrue()
        ->and(WinAction::tweaksRevert(['A', 'X'], ['A', 'B', 'C']))->toBeFalse()
        ->and(WinAction::tweaksRevert([], ['A']))->toBeFalse()
        ->and(WinAction::tweaksRevert(['A'], []))->toBeFalse();
});
