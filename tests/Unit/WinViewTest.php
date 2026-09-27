<?php

declare(strict_types=1);

use App\Http\Respond;
use App\Http\WinView;
use App\Win\ElevationState;

/**
 * Monta a /win com o estado aplicado dado e devolve o HTML.
 *
 * @param list<string> $marcados
 * @param list<string> $aplicados
 * @param list<string> $acoes
 */
function winHtml(array $marcados = [], array $aplicados = [], array $acoes = []): string
{
    $tweaks = [];
    foreach (['A', 'B', 'C'] as $k) {
        $tweaks[] = ['key' => $k, 'content' => 'tweak ' . $k, 'description' => '', 'category' => 'Essential', 'caution' => false, 'explorer' => false];
    }

    return Respond::render('win.php', new WinView(
        rows: [],
        result: null,
        blocked: null,
        notice: null,
        csrfToken: 't',
        csrfField: '_csrf',
        win: new ElevationState(on: true, psPid: 1),
        timeout: 600,
        tz: 'America/Sao_Paulo',
        maxOutputBytes: 1048576,
        maxParamBytes: 4096,
        debloatPackages: [],
        debloatProblem: null,
        debloatChecked: [],
        tweaks: $tweaks,
        tweakPresets: [],
        tweaksChecked: $marcados,
        tweaksMatch: '',
        tweaksProblem: null,
        tweaksApplied: $aplicados,
        appliedActions: $acoes,
        dnsProviders: [],
        dnsChosen: [],
        dnsProblem: null,
    ));
}

/** O trecho do HTML entre dois marcadores de seção. */
function winSecao(string $html, string $de, string $ate): string
{
    $ini = strpos($html, $de);
    $fim = strpos($html, $ate, (int) $ini);

    return substr($html, (int) $ini, (int) $fim - (int) $ini);
}

it('sem estado, os tweaks dizem Aplicar e o -Undo automático nasce desligado', function () {
    $html = winHtml(['A']);

    expect($html)->toContain('id="tw-botao">Aplicar</button>')
        ->and($html)->toContain('id="tw-auto-undo" disabled>')
        ->and($html)->not->toContain('class="aplicado"')
        ->and($html)->not->toContain('Reverter exige marcar');
});

it('com todas as marcadas aplicadas, os tweaks dizem Reverter e mandam -Undo', function () {
    $html = winHtml(['A', 'B'], ['A', 'B', 'C'], ['tweaks']);

    expect($html)->toContain('id="tw-botao">Reverter</button>')
        ->and($html)->toContain('id="tw-auto-undo">')
        ->and(substr_count($html, '<small class="aplicado">aplicado</small>'))->toBe(3);
});

it('SELEÇÃO MISTA mantém Aplicar e a seção diz por quê', function () {
    $html = winHtml(['A', 'C'], ['A'], ['tweaks']);

    expect($html)->toContain('id="tw-botao">Aplicar</button>')
        ->and($html)->toContain('id="tw-auto-undo" disabled>')
        ->and($html)->toContain('Reverter exige marcar só o que já está aplicado')
        ->and(substr_count($html, '<small class="aplicado">aplicado</small>'))->toBe(1);
});

it('o interruptor manual de -Undo continua nos tweaks e no optimize, com ou sem estado', function () {
    foreach ([[], ['tweaks', 'optimize']] as $acoes) {
        $html = winHtml(['A'], ['A'], $acoes);

        expect($html)->toContain('id="tw-undo"')
            ->and($html)->toContain('id="opt-undo"');
    }
});

it('performance aplicado: o principal vira Reverter com State off', function () {
    $sem = winSecao(winHtml(), '[5] Performance', '[6] Install');
    $com = winSecao(winHtml(acoes: ['performance']), '[5] Performance', '[6] Install');

    expect($sem)->toMatch('~name="State" value="on"><button type="submit" class="btn btn-sm">Ativar~')
        ->and($sem)->not->toContain('Reverter</button>')
        ->and($com)->toMatch('~name="State" value="off"><button type="submit" class="btn btn-sm">Reverter</button>~')
        ->and($com)->toMatch('~name="State" value="on"><button type="submit" class="btn btn-sm btn-ghost">Ativar~');
});

it('optimize aplicado: o principal vira Reverter com -Undo', function () {
    $sem = winSecao(winHtml(), '[11] Optimize', '[12] GPU');
    $com = winSecao(winHtml(acoes: ['optimize']), '[11] Optimize', '[12] GPU');

    expect($sem)->not->toContain('Reverter</button>')
        ->and($com)->toMatch('~value="optimize"><input type="hidden" name="Undo" value="1"><button type="submit" class="btn btn-sm">Reverter</button>~')
        ->and($com)->toContain('class="btn btn-sm btn-ghost">Executar');
});

it('gdid aplicado: o principal vira Reverter com enable', function () {
    $sem = winSecao(winHtml(), '[13] GDID', 'Histórico do Windows');
    $com = winSecao(winHtml(acoes: ['gdid']), '[13] GDID', 'Histórico do Windows');

    expect($sem)->not->toContain('Reverter</button>')
        ->and($com)->toMatch('~name="SubAction" value="enable"><button type="submit" class="btn btn-sm">Reverter</button>~')
        ->and($com)->toContain('class="btn btn-sm btn-ghost">Executar');
});

it('o que os botões de Reverter mandam passa pela validação e reverte o estado', function () {
    $casos = [
        [App\Win\WinAction::Optimize, ['Undo' => '1']],
        [App\Win\WinAction::Gdid, ['SubAction' => 'enable']],
        [App\Win\WinAction::Performance, ['State' => 'off']],
        [App\Win\WinAction::Tweaks, ['Items' => App\Win\WinConfig::tweakKeys()[0], 'Undo' => '1']],
    ];

    foreach ($casos as [$acao, $campos]) {
        expect($acao->stateChange($acao->validate($campos))?->applied)->toBeFalse();
    }
});
