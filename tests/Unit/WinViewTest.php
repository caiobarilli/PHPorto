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
 * @param list<string> $pendentes
 */
function winHtml(
    array $marcados = [],
    array $aplicados = [],
    array $acoes = [],
    WinTab $aba = WinTab::Sistema,
    array $pendentes = [],
    ?string $blocked = null,
    ?string $sensitiveBlocked = null,
): string {
    $tweaks = [];
    foreach (['A', 'B', 'C'] as $k) {
        $tweaks[] = ['key' => $k, 'content' => 'tweak ' . $k, 'description' => '', 'category' => 'Essential', 'caution' => false, 'explorer' => false];
    }

    return Respond::render('win.php', new WinView(
        rows: [],
        result: null,
        blocked: $blocked,
        notice: null,
        csrfToken: 't',
        csrfField: '_csrf',
        win: $blocked === null ? new ElevationState(on: true, psPid: 1) : new ElevationState(on: false),
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
        pendingRebootActions: $pendentes,
        dnsProviders: [],
        dnsChosen: [],
        dnsProblem: null,
        tab: $aba,
        sensitiveBlocked: $sensitiveBlocked,
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
    'acesso remoto' => [WinTab::AcessoRemoto, ['rdp', 'sunshine']],
]);

it('os cinco painéis vêm na página, e só o da aba aberta está visível', function (WinTab $aberta) {
    $html = winHtml(aba: $aberta);

    foreach (WinTab::cases() as $aba) {
        $abertura = '<div class="painel" id="painel-' . $aba->value . '" role="tabpanel"';
        expect($html)->toContain($abertura . ($aba === $aberta ? '>' : ' hidden>'));
    }
    expect($html)->toContain('Histórico do Windows');
})->with(WinTab::cases());

it('as quinze ações do menu aparecem uma vez só na página, e o hyperv não', function () {
    // O hyperv é a ação fora do menu: ela atende a rota /hyperv e não tem
    // seção na /win. Então a página traz as quinze do menu, nunca a décima
    // sexta — e este teste guarda essa fronteira.
    $todas = winAcoes(winHtml());
    sort($todas);

    $menu = array_filter(
        App\Win\WinAction::cases(),
        static fn (App\Win\WinAction $a): bool => $a !== App\Win\WinAction::Hyperv,
    );
    $enum = array_map(static fn (App\Win\WinAction $a): string => $a->value, $menu);
    sort($enum);

    expect($todas)->toBe($enum)
        ->and($todas)->not->toContain('hyperv');
});

it('o menu tem as cinco abas como links, e só a aberta é a atual', function () {
    $html = winHtml(aba: WinTab::Rede);

    expect(substr_count($html, 'href="/win?aba='))->toBe(5)
        ->and(substr_count($html, 'aria-current="page"'))->toBe(1)
        ->and($html)->toContain('<a href="/win?aba=rede#abas" data-aba="rede" class="ativa" aria-current="page">Rede</a>')
        ->and($html)->toContain('<a href="/win?aba=sistema#abas" data-aba="sistema">Sistema</a>')
        ->and($html)->toContain('<a href="/win?aba=acesso-remoto#abas" data-aba="acesso-remoto">Acesso Remoto</a>');
});

it('a aba Acesso Remoto navega e traz as duas seções', function () {
    $html = winHtml(aba: WinTab::AcessoRemoto);

    expect($html)->toContain('<a href="/win?aba=acesso-remoto#abas" data-aba="acesso-remoto" class="ativa" aria-current="page">Acesso Remoto</a>')
        ->and($html)->toContain('<div class="painel" id="painel-acesso-remoto" role="tabpanel">')
        ->and($html)->toContain('Área de Trabalho Remota (RDP)')
        ->and($html)->toContain('<h2>Sunshine</h2>');
});

it('rdp sem estado: oferece Ligar, Ativar o vídeo H.264/UDP, e Ver o estado', function () {
    $s = winSecao(winHtml(aba: WinTab::AcessoRemoto), 'rdp');

    expect($s)->toContain('value="status"')
        ->and($s)->toMatch('~name="SubAction" value="on"><button type="submit" class="btn btn-sm" data-uac>Ligar acesso remoto~')
        ->and($s)->toMatch('~name="SubAction" value="h264-on"><button type="submit" class="btn btn-sm btn-ghost">Ativar vídeo H.264/UDP~')
        ->and($s)->not->toContain('alert alert-note');
});

it('rdp ligado: o principal do acesso vira Desligar', function () {
    $s = winSecao(winHtml(acoes: ['rdp'], aba: WinTab::AcessoRemoto), 'rdp');

    expect($s)->toMatch('~name="SubAction" value="off"><button type="submit" class="btn btn-sm">Desligar acesso remoto~')
        ->and($s)->toMatch('~name="SubAction" value="on"><button type="submit" class="btn btn-sm btn-ghost" data-uac>Ligar acesso remoto~');
});

it('rdp com H.264/UDP pendente: mostra o aviso de reinício e o botão de reverter', function () {
    $s = winSecao(winHtml(aba: WinTab::AcessoRemoto, pendentes: ['rdp']), 'rdp');

    expect($s)->toContain('alert alert-note')
        ->and($s)->toContain('só passa a valer depois de reiniciar o')
        ->and($s)->toMatch('~name="SubAction" value="h264-off"><button type="submit" class="btn btn-sm">Reverter vídeo H.264/UDP~')
        ->and($s)->not->toContain('value="h264-on"');
});

it('o que o botão de reverter H.264/UDP manda passa pela validação e reverte o pendente', function () {
    $acao   = App\Win\WinAction::Rdp;
    $mud    = $acao->stateChange($acao->validate(['SubAction' => 'h264-off']));

    expect($mud?->applied)->toBeFalse()
        ->and($mud?->scope)->toBe(App\Domain\WinStateScope::PendingReboot);
});

it('sunshine oferece instalar, iniciar/parar, firewall e o link da Web UI', function () {
    $s = winSecao(winHtml(aba: WinTab::AcessoRemoto), 'sunshine');

    expect($s)->toContain('value="install"')
        ->and($s)->toContain('value="firewall-open"')
        ->and($s)->toContain('value="firewall-close"')
        ->and($s)->toMatch('~name="SubAction" value="start"><button type="submit" class="btn btn-sm">Iniciar serviço~')
        ->and($s)->toContain('https://localhost:47990')
        ->and($s)->not->toContain('O PHPorto não faz esse passo');
});

/** O formulário de credenciais e pareamento do sunshine. */
function sunForm(string $secao): string
{
    $ini = (int) strpos($secao, '<form method="post" data-sem-reload id="sun-parear"');

    return substr($secao, $ini, (int) strpos($secao, '</form>', $ini) - $ini);
}

it('sunshine tem o formulário de pareamento: POST, senha em type=password, sem eco de valor', function () {
    $f = sunForm(winSecao(winHtml(aba: WinTab::AcessoRemoto), 'sunshine'));

    expect($f)->toContain('method="post"')
        ->and($f)->toContain('name="_csrf" value="t"')
        ->and($f)->toContain('<input type="hidden" name="acao" value="sunshine">')
        ->and($f)->toMatch('~<input type="password" id="sun-senha" name="Password" autocomplete="new-password"~')
        ->and($f)->toMatch('~name="Pin" inputmode="numeric" pattern="\[0-9\]\{4\}" maxlength="4"\s+autocomplete="off" data-segredo~')
        ->and($f)->toContain('name="User" autocomplete="username"')
        ->and($f)->toContain('placeholder="notebook"')
        ->and($f)->toContain('<input type="checkbox" name="SetCreds" value="1">')
        // Nenhum campo de texto do formulário nasce com valor.
        ->and(preg_match('~name="(User|Password|Pin|DeviceName)"[^>]*\svalue=~', $f))->toBe(0)
        ->and($f)->not->toContain('method="get"');
});

it('os dois botões do sunshine dizem que abrem o UAC e mandam a subação certa', function () {
    $f = sunForm(winSecao(winHtml(aba: WinTab::AcessoRemoto), 'sunshine'));

    expect($f)->toContain('<button type="submit" class="btn btn-sm" name="SubAction" value="pair" data-uac>Parear com o PIN · abre o UAC</button>')
        ->and($f)->toContain('<button type="submit" class="btn btn-sm btn-ghost" name="SubAction" value="set-creds" data-uac>Só gravar credenciais · abre o UAC</button>');
});

it('com o caminho sensível travado, o formulário do sunshine nasce desabilitado', function () {
    $f = sunForm(winSecao(winHtml(aba: WinTab::AcessoRemoto, sensitiveBlocked: 'UAC silencioso'), 'sunshine'));

    expect(substr_count($f, ' disabled'))->toBe(7);
});

it('o sem-reload limpa senha e PIN depois da resposta', function () {
    // O PIN é type=text (numérico); quem o limpa é o data-segredo.
    expect((string) file_get_contents(dirname(__DIR__, 2) . '/src/Views/layout.php'))->toContain("document.querySelectorAll('input[type=password], [data-segredo]').forEach(function (i) { i.value = ''; });");
});

it('sunshine com o serviço no ar: o principal vira Parar', function () {
    $s = winSecao(winHtml(acoes: ['sunshine'], aba: WinTab::AcessoRemoto), 'sunshine');

    expect($s)->toMatch('~name="SubAction" value="stop"><button type="submit" class="btn btn-sm">Parar serviço~')
        ->and($s)->toMatch('~name="SubAction" value="start"><button type="submit" class="btn btn-sm btn-ghost">Iniciar serviço~');
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

    expect($html)->toContain('<option value="install" data-uac>instalar (install) · abre o UAC</option>')
        ->and($html)->toContain('<option value="status">ver o estado (status)</option>')
        ->and($html)->toContain('<option value="disable">desligar (disable)</option>')
        ->and($html)->not->toMatch('/<option value="([a-z]+)">\1<\/option>/');
});

// ---------------------------------------------------------------- sensíveis

it('a ação sensível diz que abre o UAC, e a comum não', function () {
    $html = winHtml(aba: WinTab::AcessoRemoto);

    expect($html)->toContain('Ligar acesso remoto · abre o UAC')
        ->and($html)->toContain('Abrir a porta no firewall · abre o UAC')
        ->and($html)->toContain('>Instalar · abre o UAC</button>')
        ->and($html)->not->toContain('Desligar acesso remoto · abre o UAC')
        ->and($html)->not->toContain('Fechar a porta · abre o UAC');
});

it('COM O WORKER LONGO DESLIGADO, a sensível segue clicável e a comum trava', function () {
    $html = winHtml(aba: WinTab::AcessoRemoto, blocked: 'O PowerShell elevado está desligado.');

    expect($html)->toContain('não dependem dele')
        ->and($html)->toMatch('/<button type="submit" class="btn btn-sm[^"]*" data-uac>Ligar acesso remoto · abre o UAC/')
        ->and($html)->toMatch('/<button type="submit" class="btn btn-sm[^"]*" disabled>Desligar acesso remoto</')
        ->and(winSecao($html, 'install'))->not->toContain(' disabled')
        // No select misto, cada opção trava pelo caminho dela.
        ->and($html)->toContain('<option value="install" data-uac>instalar (install) · abre o UAC</option>')
        ->and($html)->toContain('<option value="status" disabled>ver o estado (status)</option>');
});

it('com o checkout incompleto, a sensível também trava', function () {
    $motivo = 'Falta o worker.ps1 em src/Win.';
    $html   = winHtml(aba: WinTab::AcessoRemoto, blocked: $motivo, sensitiveBlocked: $motivo);

    expect($html)->not->toContain('não dependem dele')
        ->and($html)->toMatch('/" data-uac disabled>Ligar acesso remoto · abre o UAC/')
        ->and(winSecao($html, 'install'))->toContain(' disabled')
        ->and($html)->toContain('<select id="exp-sub" name="SubAction" disabled>');
});

// ---- Sem reload (fase 1) ----------------------------------------------------

it('sem reload: todo formulário POST da /win é marcado, e o aviso também', function () {
    $html = winHtml();

    preg_match_all('/<form method="post"[^>]*>/', $html, $m);

    expect($m[0])->not->toBeEmpty();
    foreach ($m[0] as $form) {
        expect($form)->toContain('data-sem-reload');
    }
});

it('sem reload: os aplicados vêm num atributo do form-tweaks, escapado, e não cozidos no script', function () {
    $html = winHtml(aplicados: ['A', 'x"<b>']);

    expect($html)->toContain('id="form-tweaks" data-aplicados="[&quot;A&quot;,&quot;x\&quot;&lt;b&gt;&quot;]"')
        ->and($html)->not->toContain('APLICADOS');
});

it('sem reload: só a ação sensível leva data-uac, e só o aviso da ação leva data-aviso', function () {
    $html = winHtml(aba: WinTab::AcessoRemoto);

    expect($html)->toContain('data-uac>Ligar acesso remoto · abre o UAC')
        ->and($html)->toContain('data-uac>Instalar · abre o UAC')
        ->and($html)->toMatch('/class="btn btn-sm[^"]*">Desligar acesso remoto</')
        ->and(substr_count($html, 'data-aviso'))->toBe(0);
});

it('sem reload: a confirmação do optimize usa requestSubmit, com submit() só de recaída', function () {
    $html = winHtml();

    expect($html)->toContain('if (formOpt.requestSubmit) { formOpt.requestSubmit(); } else { formOpt.submit(); }')
        ->and(substr_count($html, '.submit()'))->toBe(1);
});
