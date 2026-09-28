<?php

declare(strict_types=1);

use App\Http\Respond;
use App\Http\WinTab;
use App\Http\WinView;
use App\Win\ElevationState;

/**
 * Monta a /win com o estado aplicado e a aba dados e devolve o HTML.
 *
 * @param list<string> $marcados
 * @param list<string> $aplicados
 * @param list<string> $acoes
 */
function winHtml(array $marcados = [], array $aplicados = [], array $acoes = [], WinTab $aba = WinTab::Sistema): string
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
        tab: $aba,
    ));
}

/** A seção de uma ação, pelo id dela; vazio se ela não está na página. */
function winSecao(string $html, string $acao): string
{
    $ini = strpos($html, '<section id="acao-' . $acao . '">');

    if ($ini === false) {
        return '';
    }

    return substr($html, $ini, (int) strpos($html, '</section>', $ini) - $ini);
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
        expect(winHtml(['A'], ['A'], $acoes))->toContain('id="tw-undo"')
            ->and(winHtml(['A'], ['A'], $acoes, WinTab::Servicos))->toContain('id="opt-undo"');
    }
});

it('performance aplicado: o principal vira Reverter com State off', function () {
    $sem = winSecao(winHtml(), 'performance');
    $com = winSecao(winHtml(acoes: ['performance']), 'performance');

    expect($sem)->toMatch('~name="State" value="on"><button type="submit" class="btn btn-sm">Ativar~')
        ->and($sem)->not->toContain('Reverter</button>')
        ->and($com)->toMatch('~name="State" value="off"><button type="submit" class="btn btn-sm">Reverter</button>~')
        ->and($com)->toMatch('~name="State" value="on"><button type="submit" class="btn btn-sm btn-ghost">Ativar~');
});

it('optimize aplicado: o principal vira Reverter com -Undo', function () {
    $sem = winSecao(winHtml(aba: WinTab::Servicos), 'optimize');
    $com = winSecao(winHtml(acoes: ['optimize'], aba: WinTab::Servicos), 'optimize');

    expect($sem)->not->toContain('Reverter</button>')
        ->and($com)->toMatch('~value="optimize"><input type="hidden" name="Undo" value="1"><button type="submit" class="btn btn-sm">Reverter</button>~')
        ->and($com)->toContain('class="btn btn-sm btn-ghost">Executar');
});

it('gdid aplicado: o principal vira Reverter com enable', function () {
    $sem = winSecao(winHtml(aba: WinTab::Servicos), 'gdid');
    $com = winSecao(winHtml(acoes: ['gdid'], aba: WinTab::Servicos), 'gdid');

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

/** Os ids de seção de ação que aparecem no HTML, na ordem. */
function winAcoes(string $html): array
{
    preg_match_all('/<section id="acao-([a-z]+)">/', $html, $m);

    return $m[1];
}

it('a página tem quatro partes, nesta ordem: saída, principais, abas, histórico', function () {
    $html  = winHtml();
    $ordem = array_map(static fn (string $marca): int|false => strpos($html, $marca), [
        'Última execução do Windows', '<div class="principais">', '<nav class="abas"', 'Histórico do Windows',
    ]);

    expect($ordem)->not->toContain(false)
        ->and($ordem[0] < $ordem[1] && $ordem[1] < $ordem[2] && $ordem[2] < $ordem[3])->toBeTrue();
});

it('as quatro principais ficam na mesma linha: auditoria, memória, desempenho, processos', function () {
    $html  = winHtml();
    $linha = substr($html, (int) strpos($html, '<div class="principais">'), (int) strpos($html, '<nav class="abas"') - (int) strpos($html, '<div class="principais">'));

    expect(winAcoes($linha))->toBe(['audit', 'memory', 'performance', 'processes'])
        ->and(winSecao($html, 'audit'))->toContain('>Explorar</button>');
});

it('cada aba desenha só as ações dela, e o histórico fica em todas', function (WinTab $aba, array $esperado) {
    $html = winHtml(aba: $aba);

    expect(array_slice(winAcoes($html), 4))->toBe($esperado)
        ->and($html)->toContain('Histórico do Windows');
})->with([
    'sistema'     => [WinTab::Sistema, ['tweaks', 'debloat']],
    'rede'        => [WinTab::Rede, ['dns', 'network']],
    'aplicativos' => [WinTab::Aplicativos, ['install']],
    'serviços'    => [WinTab::Servicos, ['exporter', 'gpu', 'optimize', 'gdid']],
]);

it('as treze ações aparecem, cada uma numa aba só ou na linha das principais', function () {
    $todas = [];
    foreach (WinTab::cases() as $aba) {
        $todas = [...$todas, ...array_slice(winAcoes(winHtml(aba: $aba)), 4)];
    }
    $todas = [...$todas, 'audit', 'memory', 'performance', 'processes'];
    sort($todas);

    $enum = array_map(static fn (App\Win\WinAction $a): string => $a->value, App\Win\WinAction::cases());
    sort($enum);

    expect($todas)->toBe($enum);
});

it('o menu tem as quatro abas como links, e só a aberta é a atual', function () {
    $html = winHtml(aba: WinTab::Rede);

    expect(substr_count($html, 'href="/win?aba='))->toBe(4)
        ->and(substr_count($html, 'aria-current="page"'))->toBe(1)
        ->and($html)->toContain('<a href="/win?aba=rede" class="ativa" aria-current="page">Rede</a>')
        ->and($html)->toContain('<a href="/win?aba=sistema">Sistema</a>');
});

it('A NUMERAÇÃO [1] A [13] SUMIU da tela', function () {
    foreach (WinTab::cases() as $aba) {
        expect(winHtml(aba: $aba))->not->toMatch('/<h2>\[\d+\]/');
    }
});

it('subação aparece em português, com o valor da ação ao lado e no envio', function () {
    $html = winHtml(aba: WinTab::Servicos);

    expect($html)->toContain('<option value="install">instalar (install)</option>')
        ->and($html)->toContain('<option value="disable">desligar (disable)</option>')
        ->and($html)->not->toMatch('/<option value="([a-z]+)">\1<\/option>/');
});
