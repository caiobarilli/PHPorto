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

/** O HTML do painel de uma aba, da abertura até o próximo painel ou o histórico. */
function winPainel(string $html, WinTab $aba): string
{
    $ini = (int) strpos($html, '<div class="painel" id="painel-' . $aba->value . '"');
    $fim = strpos($html, '<div class="painel"', $ini + 1);

    return substr($html, $ini, ($fim === false ? (int) strpos($html, 'Histórico do Windows') : $fim) - $ini);
}

it('as três principais ficam na mesma linha: auditoria, memória, processos', function () {
    $html  = winHtml();
    $linha = substr($html, (int) strpos($html, '<div class="principais">'), (int) strpos($html, '<nav class="abas"') - (int) strpos($html, '<div class="principais">'));

    expect(winAcoes($linha))->toBe(['audit', 'memory', 'processes'])
        ->and(winSecao($html, 'audit'))->toContain('>Explorar</button>');
});

it('cada painel tem só as ações da aba dele, e o desempenho mora em Serviços', function (WinTab $aba, array $esperado) {
    expect(winAcoes(winPainel(winHtml(), $aba)))->toBe($esperado);
})->with([
    'sistema'       => [WinTab::Sistema, ['tweaks', 'debloat']],
    'rede'          => [WinTab::Rede, ['dns', 'network']],
    'aplicativos'   => [WinTab::Aplicativos, ['install']],
    'serviços'      => [WinTab::Servicos, ['exporter', 'gpu', 'optimize', 'gdid', 'performance']],
    'acesso remoto' => [WinTab::AcessoRemoto, []],
]);

it('os cinco painéis vêm na página, e só o da aba aberta está visível', function (WinTab $aberta) {
    $html = winHtml(aba: $aberta);

    foreach (WinTab::cases() as $aba) {
        $abertura = '<div class="painel" id="painel-' . $aba->value . '" role="tabpanel"';
        expect($html)->toContain($abertura . ($aba === $aberta ? '>' : ' hidden>'));
    }
    expect($html)->toContain('Histórico do Windows');
})->with(WinTab::cases());

it('as treze ações aparecem uma vez só na página', function () {
    $todas = winAcoes(winHtml());
    sort($todas);

    $enum = array_map(static fn (App\Win\WinAction $a): string => $a->value, App\Win\WinAction::cases());
    sort($enum);

    expect($todas)->toBe($enum);
});

it('o menu tem as cinco abas como links, e só a aberta é a atual', function () {
    $html = winHtml(aba: WinTab::Rede);

    expect(substr_count($html, 'href="/win?aba='))->toBe(5)
        ->and(substr_count($html, 'aria-current="page"'))->toBe(1)
        ->and($html)->toContain('<a href="/win?aba=rede#abas" data-aba="rede" class="ativa" aria-current="page">Rede</a>')
        ->and($html)->toContain('<a href="/win?aba=sistema#abas" data-aba="sistema">Sistema</a>')
        ->and($html)->toContain('<a href="/win?aba=acesso-remoto#abas" data-aba="acesso-remoto">Acesso Remoto</a>');
});

it('a aba Acesso Remoto navega e traz as duas seções, ainda vazias', function () {
    $html = winHtml(aba: WinTab::AcessoRemoto);

    expect($html)->toContain('<a href="/win?aba=acesso-remoto#abas" data-aba="acesso-remoto" class="ativa" aria-current="page">Acesso Remoto</a>')
        ->and($html)->toContain('<div class="painel" id="painel-acesso-remoto" role="tabpanel">')
        ->and($html)->toContain('Área de Trabalho Remota (RDP)')
        ->and($html)->toContain('<h2>Sunshine</h2>');
});

it('sem JavaScript, o link da aba recarrega posicionado no menu, que tem o id da âncora', function () {
    $html = winHtml();

    expect($html)->toContain('<nav class="abas" id="abas"')
        ->and(preg_match_all('~href="/win\?aba=[a-z-]+#abas"~', $html))->toBe(5);
});

it('o JavaScript das abas é segunda camada e mantém a aba na URL com replaceState', function () {
    $html = winHtml();
    $js   = substr($html, (int) strpos($html, '// ---- Abas:'), 1500);

    expect($js)->toContain('SEGUNDA CAMADA, PARA CONFORTO')
        ->and($js)->toContain("history.replaceState(null, '', link.getAttribute('href'))")
        ->and($js)->toContain('ev.preventDefault()');
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
