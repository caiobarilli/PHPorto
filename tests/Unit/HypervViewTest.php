<?php

declare(strict_types=1);

use App\Http\HypervView;
use App\Http\Respond;
use App\Win\HypervListing;

function hypervHtml(
    ?string $blocked = null,
    ?HypervListing $listing = null,
    bool $timedOut = false,
    ?string $notice = null,
    ?string $actionsBlocked = null,
    string $parte = HypervView::PARTE_COMPLETA,
): string {
    return Respond::render('hyperv.php', new HypervView(
        tz: 'America/Sao_Paulo',
        blocked: $blocked,
        listing: $listing,
        readAtUtc: '2026-09-28T12:00:00+00:00',
        timedOut: $timedOut,
        notice: $notice,
        csrfToken: 'tok-123',
        csrfField: '_csrf',
        actionsBlocked: $actionsBlocked,
        parte: $parte,
    ));
}

it('bloqueado: a faixa é a tela inteira, sem tabela, com a saída para a configuração', function () {
    $html = hypervHtml(blocked: 'O PowerShell elevado está desligado.');

    expect($html)->toContain('o PHPorto precisa do PowerShell elevado ligado')
        ->and($html)->toContain('O PowerShell elevado está desligado.')
        ->and($html)->toContain('<a class="btn btn-sm" href="/config#form-ps">Abrir configuração</a>')
        ->and($html)->not->toContain('<table');
});

it('leitura cancelada no teto: diz que demorou e oferece Atualizar, sem mandar reiniciar', function () {
    $html = hypervHtml(timedOut: true);

    expect($html)->toContain('A leitura demorou demais e foi cancelada.')
        ->and($html)->toContain('href="/hyperv?parte=completa" data-atualizar>Atualizar</a>')
        ->and($html)->not->toContain('reiniciar o computador')
        ->and($html)->not->toContain('<table');
});

it('recurso desligado no Windows: só AQUI a tela manda reiniciar', function () {
    $html = hypervHtml(listing: HypervListing::fromOutput('{"hyperv":false,"motivo":"recurso-desligado","vms":[]}'));

    expect($html)->toContain('O Hyper-V não está ligado no Windows.')
        ->and($html)->toContain('reiniciar o computador')
        ->and($html)->not->toContain('<table');
});

it('serviço vmms parado: aponta o serviço e não fala em recurso desligado', function () {
    $html = hypervHtml(listing: HypervListing::fromOutput('{"hyperv":false,"motivo":"servico-parado","vms":[]}'));

    expect($html)->toContain('O serviço de máquinas virtuais do Windows está parado.')
        ->and($html)->toContain('Gerenciamento de Máquina Virtual do Hyper-V')
        ->and($html)->not->toContain('O Hyper-V não está ligado no Windows.')
        ->and($html)->not->toContain('<table');
});

it('outro erro: frase amigável e a mensagem crua recolhida e escapada', function () {
    $html = hypervHtml(listing: HypervListing::fromOutput('{"hyperv":false,"motivo":"erro","erro":"WMI <falhou>","vms":[]}'));

    expect($html)->toContain('Não deu para ler as máquinas virtuais.')
        ->and($html)->toContain('<summary>detalhes técnicos</summary>')
        ->and($html)->toContain('WMI &lt;falhou&gt;')
        ->and($html)->not->toContain('<details open')
        ->and($html)->not->toContain('reiniciar o computador')
        ->and($html)->not->toContain('<table');
});

it('saída do worker antigo, sem motivo: NÃO manda reiniciar', function () {
    $html = hypervHtml(listing: HypervListing::fromOutput('{"hyperv":false,"erro":"x","vms":[]}'));

    expect($html)->toContain('Não deu para ler as máquinas virtuais.')
        ->and($html)->not->toContain('reiniciar o computador');
});

it('hospedeiro ligado: o Atualizar é um GET simples ao lado do "lido em"', function () {
    $html = hypervHtml(listing: HypervListing::fromOutput('{"hyperv":true,"vms":[]}'));

    expect($html)->toContain('<a href="/hyperv?parte=completa" id="hyperv-atualizar" data-atualizar>Atualizar</a>')
        ->and($html)->not->toContain('<form');
});

it('saída fora do formato: mostra o problema, sem tabela', function () {
    $html = hypervHtml(listing: HypervListing::fromOutput('recusado, sem json'));

    expect($html)->toContain('não voltou no formato esperado')
        ->and($html)->not->toContain('<table');
});

it('sem nenhuma VM: uma frase no lugar da tabela', function () {
    $html = hypervHtml(listing: HypervListing::fromOutput('{"hyperv":true,"vms":[]}'));

    expect($html)->toContain('Nenhuma máquina virtual neste computador.')
        ->and($html)->not->toContain('<table')
        ->and($html)->toContain('Hyper-V <span class="ok">ligado</span>')
        ->and($html)->not->toContain('de 0');
});

it('a linha-resumo diz "N de M ligadas" e a hora no fuso do Windows', function () {
    $json = '{"hyperv":true,"vms":['
        . '{"name":"Ubuntu","state":"Running","running":true,"memoryBytes":2147483648,"uptimeSeconds":3661,"ip":["192.168.1.5"]},'
        . '{"name":"Win11","state":"Off","running":false,"memoryBytes":0,"uptimeSeconds":0,"ip":[]}'
        . ']}';

    $html = hypervHtml(listing: HypervListing::fromOutput($json));

    expect($html)->toContain('<strong>1 de 2 ligadas</strong>')
        ->and($html)->toContain('lido às <span title="28/09/2026 09:00:00">09:00</span>');
});

it('uma VM só: "ligada" no singular', function () {
    $json = '{"hyperv":true,"vms":[{"name":"Solo","state":"Off","running":false,"memoryBytes":0,"uptimeSeconds":0,"ip":[]}]}';

    expect(hypervHtml(listing: HypervListing::fromOutput($json)))->toContain('<strong>0 de 1 ligada</strong>');
});

it('as colunas vêm em português claro: Nome, Situação, Memória em uso, Ligada há, Endereço IP, Ações', function () {
    $json = '{"hyperv":true,"vms":[{"name":"Ubuntu","state":"Running","running":true,"memoryBytes":1073741824,"uptimeSeconds":120,"ip":["10.0.0.1"]}]}';
    $html = hypervHtml(listing: HypervListing::fromOutput($json));

    $ordem = array_map(
        static fn (string $col): int|false => preg_match('#<th[^>]*>' . $col . '</th>#', $html, $m, PREG_OFFSET_CAPTURE) === 1 ? $m[0][1] : false,
        ['Nome', 'Situação', 'Memória em uso', 'Ligada há', 'Endereço IP', 'Ações']
    );

    expect($ordem)->not->toContain(false);

    for ($i = 1; $i < count($ordem); $i++) {
        expect($ordem[$i])->toBeGreaterThan($ordem[$i - 1]);
    }

    expect($html)->not->toContain('<th>uptime</th>');
});

it('situação com marcador: verde rodando, cinza parada, neutro no transitório', function () {
    $json = '{"hyperv":true,"vms":['
        . '{"name":"A","state":"Running","running":true,"memoryBytes":1,"uptimeSeconds":1,"ip":[]},'
        . '{"name":"B","state":"Saved","running":false,"memoryBytes":0,"uptimeSeconds":0,"ip":[]},'
        . '{"name":"C","state":"Starting","running":false,"memoryBytes":0,"uptimeSeconds":0,"ip":[]}'
        . ']}';

    $html = hypervHtml(listing: HypervListing::fromOutput($json));

    expect($html)->toContain('<span class="ok">&#9679;</span> rodando')
        ->and($html)->toContain('<span class="vm-parada">&#9679;</span> salva (parada, retoma de onde estava)')
        ->and($html)->toContain('<span>&#9679;</span> iniciando');
});

it('IP: IPv4 primeiro, fe80:: escondido, o resto em "mais endereços"', function () {
    $json = '{"hyperv":true,"vms":[{"name":"U","state":"Running","running":true,"memoryBytes":1,"uptimeSeconds":1,'
        . '"ip":["fe80::1","2001:db8::5","192.168.1.5"]}]}';

    $html = hypervHtml(listing: HypervListing::fromOutput($json));

    expect($html)->toContain('<code class="vm-ip">192.168.1.5</code>')
        ->and($html)->toContain('data-copiar="192.168.1.5" hidden>copiar</button>')
        ->and($html)->toContain('<summary>mais endereços</summary>')
        ->and($html)->toContain('2001:db8::5')
        ->and($html)->not->toContain('fe80::1');
});

it('IP: só um IPv4 não abre "mais endereços"', function () {
    $json = '{"hyperv":true,"vms":[{"name":"U","state":"Running","running":true,"memoryBytes":1,"uptimeSeconds":1,"ip":["10.0.0.1","FE80::abcd"]}]}';

    $html = hypervHtml(listing: HypervListing::fromOutput($json));

    expect($html)->toContain('<code class="vm-ip">10.0.0.1</code>')
        ->and($html)->not->toContain('mais endereços')
        ->and($html)->not->toContain('FE80::abcd');
});

it('IP: só link-local conta como "não foi possível ler o IP"', function () {
    $json = '{"hyperv":true,"vms":[{"name":"U","state":"Running","running":true,"memoryBytes":1,"uptimeSeconds":1,"ip":["fe80::1"]}]}';

    expect(hypervHtml(listing: HypervListing::fromOutput($json)))->toContain('não foi possível ler o IP');
});

it('copiar não faz requisição: botão comum, sem form, que só o JS revela', function () {
    $json = '{"hyperv":true,"vms":[{"name":"U","state":"Running","running":true,"memoryBytes":1,"uptimeSeconds":1,"ip":["10.0.0.1"]}]}';

    $html = hypervHtml(listing: HypervListing::fromOutput($json));

    expect($html)->toContain('<button type="button"')
        ->and($html)->not->toContain('<form')
        ->and(substr_count($html, 'fetch('))->toBe(1)
        ->and($html)->toContain("fetch('/hyperv?parte=tabela'")
        ->and($html)->toContain('navigator.clipboard');
});

it('a VM rodando mostra estado, memória, uptime e IP em português', function () {
    $json = '{"hyperv":true,"vms":[{"name":"Ubuntu","state":"Running","running":true,"memoryBytes":2147483648,"uptimeSeconds":3661,"ip":["192.168.1.5"]}]}';

    $html = hypervHtml(listing: HypervListing::fromOutput($json));

    expect($html)->toContain('Ubuntu')
        ->and($html)->toContain('rodando')
        ->and($html)->toContain('2,0 GB')
        ->and($html)->toContain('1h 1min')
        ->and($html)->toContain('192.168.1.5');
});

it('a VM desligada não inventa memória, uptime nem IP', function () {
    $json = '{"hyperv":true,"vms":[{"name":"Win11","state":"Off","running":false,"memoryBytes":0,"uptimeSeconds":0,"ip":[]}]}';

    $html = hypervHtml(listing: HypervListing::fromOutput($json));

    expect($html)->toContain('desligada')
        ->and($html)->toContain('—');
});

it('a VM rodando sem IP legível DIZ que não deu, não deixa em branco', function () {
    $json = '{"hyperv":true,"vms":[{"name":"Ubuntu","state":"Running","running":true,"memoryBytes":1073741824,"uptimeSeconds":120,"ip":[]}]}';

    $html = hypervHtml(listing: HypervListing::fromOutput($json));

    expect($html)->toContain('não foi possível ler o IP');
});

const VM_ID = '6c1e2a4b-0d3f-4e5a-9b8c-7d6e5f4a3b2c';

function vmJson(string $state, string $id = VM_ID, string $name = 'Ubuntu'): HypervListing
{
    $running = $state === 'Running' ? 'true' : 'false';
    $idJson  = $id === '' ? '' : '"id":"' . $id . '",';

    return HypervListing::fromOutput('{"hyperv":true,"vms":[{' . $idJson . '"name":"' . $name . '","state":"' . $state . '","running":' . $running
        . ',"memoryBytes":1,"uptimeSeconds":1,"ip":[]}]}');
}

/** @return array<string, bool> */
function vmBotoes(string $html): array
{
    preg_match_all('#<button type="submit" class="btn btn-ghost btn-icone vm-(start|stop|restart)"[^>]*>#', $html, $m, PREG_SET_ORDER);

    $botoes = [];
    foreach ($m as [$tag, $sub]) {
        $botoes[$sub] = !str_contains($tag, ' disabled');
    }

    return $botoes;
}

it('o estado decide o botão: <state>', function (string $state, array $esperado) {
    expect(vmBotoes(hypervHtml(listing: vmJson($state))))->toBe($esperado);
})->with([
    'desligada'  => ['Off', ['start' => true, 'stop' => false, 'restart' => false]],
    'rodando'    => ['Running', ['start' => false, 'stop' => true, 'restart' => true]],
    'salva'      => ['Saved', ['start' => true, 'stop' => false, 'restart' => false]],
    'salva rápida' => ['FastSaved', ['start' => true, 'stop' => false, 'restart' => false]],
    'hibernada'  => ['Hibernated', ['start' => true, 'stop' => false, 'restart' => false]],
    'pausada'    => ['Paused', ['start' => true, 'stop' => false, 'restart' => false]],
    'iniciando'  => ['Starting', ['start' => false, 'stop' => false, 'restart' => false]],
    'desligando' => ['Stopping', ['start' => false, 'stop' => false, 'restart' => false]],
    'salvando'   => ['Saving', ['start' => false, 'stop' => false, 'restart' => false]],
    'desconhecido' => ['Other', ['start' => false, 'stop' => false, 'restart' => false]],
]);

it('VM sem id não ganha botão nem formulário', function () {
    $html = hypervHtml(listing: vmJson('Running', ''));

    expect(vmBotoes($html))->toBe([])
        ->and($html)->not->toContain('<form')
        ->and($html)->toContain('<th class="c-vm-acoes">Ações</th>');
});

it('cada botão é um POST da /hyperv com o token, a ação, a subação e o id', function (string $sub) {
    $html = hypervHtml(listing: vmJson('Running'));

    $padrao = '#<form method="post" action="/hyperv" data-sem-reload class="vm-form"[^>]*>'
        . '<input type="hidden" name="_csrf" value="tok-123">'
        . '<input type="hidden" name="acao" value="hyperv-vm">'
        . '<input type="hidden" name="SubAction" value="' . $sub . '">'
        . '<input type="hidden" name="VmId" value="' . VM_ID . '">#';

    expect(preg_match($padrao, $html))->toBe(1);
})->with(['start', 'stop', 'restart']);

it('desligar e reiniciar confirmam no <dialog>; ligar não', function () {
    $html = hypervHtml(listing: vmJson('Running'));

    expect($html)->toContain('class="vm-form" data-confirmar="stop" data-vm="Ubuntu">')
        ->and($html)->toContain('class="vm-form" data-confirmar="restart" data-vm="Ubuntu">')
        ->and($html)->not->toContain('data-confirmar="start"')
        ->and($html)->toContain('<dialog id="modal-vm">')
        ->and($html)->toContain('id="btn-confirmar-vm"')
        ->and($html)->toContain('data-fechar>Cancelar</button>')
        ->and($html)->toContain('form.requestSubmit()')
        ->and(substr_count($html, 'fetch('))->toBe(1)
        ->and($html)->toContain("fetch('/hyperv?parte=tabela'");
});

it('o modal fica fora do miolo que o sem-reload troca', function () {
    $html = hypervHtml(listing: vmJson('Running'));

    $wrap = strpos($html, '<div class="wrap">');
    $modal = strpos($html, '<dialog id="modal-vm">');
    $tabela = strpos($html, '</table>');

    expect($modal)->toBeGreaterThan($tabela)
        ->and(substr_count(substr($html, (int) $wrap, (int) $modal - (int) $wrap), '<div') - substr_count(substr($html, (int) $wrap, (int) $modal - (int) $wrap), '</div>'))->toBe(0);
});

it('o nome da VM vai escapado no data-vm e no aria-label', function () {
    $html = hypervHtml(listing: vmJson('Running', VM_ID, 'a\"<b>'));

    expect($html)->toContain('data-vm="a&quot;&lt;b&gt;"')
        ->and($html)->toContain('aria-label="Desligar a&quot;&lt;b&gt;"')
        ->and($html)->not->toContain('<b>');
});

it('os botões têm nome em português e o ícone em SVG com currentColor', function () {
    $html = hypervHtml(listing: vmJson('Running'));

    expect($html)->toContain('aria-label="Ligar Ubuntu" title="Ligar"')
        ->and($html)->toContain('aria-label="Desligar Ubuntu" title="Desligar"')
        ->and($html)->toContain('aria-label="Reiniciar Ubuntu" title="Reiniciar"')
        ->and(substr_count($html, 'currentColor'))->toBeGreaterThanOrEqual(3)
        ->and(substr_count($html, ' data-uac'))->toBe(3)
        ->and($html)->toContain('<span class="vm-pendente" data-pendente hidden></span>');
});

it('pausada: o ligar diz Retomar', function () {
    expect(hypervHtml(listing: vmJson('Paused')))->toContain('aria-label="Retomar Ubuntu" title="Retomar"');
});

it('as cores: verde liga, vermelho desliga, âmbar reinicia, e as três são token', function () {
    $layout = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Views/layout.php');

    expect($layout)->toContain('--amber: #b45309;')
        ->and($layout)->toContain('--ok:        #15803d;')
        ->and($layout)->toContain('--bad:       #b91c1c;')
        ->and($layout)->toContain('.vm-start { color: var(--ok); }')
        ->and($layout)->toContain('.vm-stop { color: var(--bad); }')
        ->and($layout)->toContain('.vm-restart { color: var(--amber); }');
});

it('o sem-reload não troca o ícone pelo relógio: o tempo vai para o [data-pendente]', function () {
    $layout = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Views/layout.php');

    expect($layout)->toContain("botao.classList.contains('btn-icone')")
        ->and($layout)->toContain("querySelector('[data-pendente]')");
});

it('checkout sem os .ps1: todos os botões desabilitados, e a faixa diz por quê', function () {
    $html = hypervHtml(listing: vmJson('Off'), actionsBlocked: 'Falta src/Win/worker.ps1.');

    expect(vmBotoes($html))->toBe(['start' => false, 'stop' => false, 'restart' => false])
        ->and($html)->toContain('Ligar, desligar e reiniciar estão indisponíveis.')
        ->and($html)->toContain('Falta src/Win/worker.ps1.');
});

it('o aviso da última ação aparece com data-aviso, para o sem-reload repetir', function () {
    expect(hypervHtml(listing: vmJson('Off'), notice: 'Ubuntu: VM ligando.'))
        ->toContain('<div class="alert alert-note" data-aviso>Ubuntu: VM ligando.</div>');
});

it('sem reload: a casca abre na hora com o aviso de leitura, sem tabela e sem ler nada', function () {
    $html = hypervHtml(parte: HypervView::PARTE_CASCA);

    expect($html)->toContain('<div id="hyperv-leitura" data-ler="/hyperv?parte=tabela" aria-live="polite">')
        ->and($html)->toContain('Lendo as máquinas virtuais…')
        ->and($html)->not->toContain('<table')
        ->and($html)->not->toContain('id="hyperv-faixa"')
        ->and($html)->toContain('<dialog id="modal-vm">');
});

it('sem reload: sem JS, a casca oferece o link que lê a página inteira', function () {
    expect(hypervHtml(parte: HypervView::PARTE_CASCA))
        ->toContain('<noscript><p><a class="btn btn-sm" href="/hyperv?parte=completa">Ler as máquinas virtuais</a></p></noscript>');
});

it('sem reload: a parte tabela é só o bloco da leitura, sem layout, sem script e sem modal', function () {
    $html = hypervHtml(listing: vmJson('Running'), parte: HypervView::PARTE_TABELA);

    expect($html)->toStartWith('<div id="hyperv-leitura">')
        ->and(rtrim($html))->toEndWith('</div>')
        ->and($html)->toContain('<table id="hyperv-tabela">')
        ->and($html)->toContain('rodando')
        ->and($html)->toContain('name="VmId"')
        ->and($html)->toContain('value="tok-123"')
        ->and($html)->not->toContain('<div class="wrap">')
        ->and($html)->not->toContain('<script')
        ->and($html)->not->toContain('<dialog')
        ->and($html)->not->toContain('<html')
        ->and($html)->not->toContain('data-ler=');
});

it('sem reload: a parte tabela traz as faixas de bloqueio e de teto do mesmo template', function () {
    expect(hypervHtml(blocked: 'desligado', parte: HypervView::PARTE_TABELA))->toContain('id="hyperv-faixa"')
        ->and(hypervHtml(timedOut: true, parte: HypervView::PARTE_TABELA))->toContain('A leitura demorou demais e foi cancelada.');
});

it('sem reload: a completa é a de antes, com a leitura dentro do miolo', function () {
    $html = hypervHtml(listing: vmJson('Running'));

    expect($html)->toContain('<div class="wrap">')
        ->and($html)->toContain('<div id="hyperv-leitura">')
        ->and($html)->toContain('<table id="hyperv-tabela">')
        ->and($html)->not->toContain('data-ler=');
});

it('sem reload: a leitura só sai por clique ou ao abrir a casca, nunca por sondagem', function () {
    $html = hypervHtml(parte: HypervView::PARTE_CASCA);

    expect($html)->not->toContain('setInterval')
        ->and($html)->not->toContain('setTimeout(ler')
        ->and($html)->toContain("closest('[data-atualizar]')")
        ->and($html)->toContain("credentials: 'same-origin'")
        ->and($html)->toContain("mode: 'same-origin'")
        ->and($html)->not->toContain('innerHTML')
        ->and(substr_count($html, '.submit()'))->toBe(1);
});

it('a query parte escolhe casca, tabela ou completa', function (mixed $valor, string $esperado) {
    expect(App\Http\Pages::hypervParte($valor))->toBe($esperado);
})->with([
    'ausente'    => [null, HypervView::PARTE_CASCA],
    'vazia'      => ['', HypervView::PARTE_CASCA],
    'tabela'     => ['tabela', HypervView::PARTE_TABELA],
    'completa'   => ['completa', HypervView::PARTE_COMPLETA],
    'outra'      => ['x', HypervView::PARTE_COMPLETA],
    'lista'      => [['tabela'], HypervView::PARTE_COMPLETA],
]);

it('a parte tabela sai pelo Respond::partial, com no-store, e passa pelo portão como o resto', function () {
    $pages   = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Http/Pages.php');
    $respond = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Http/Respond.php');
    $parcial = substr($respond, (int) strpos($respond, 'public static function partial'), 400);

    expect($pages)->toContain("Respond::partial(Respond::render('hyperv.php', \$view));")
        ->and($parcial)->toContain("header('Cache-Control: no-store');")
        ->and($parcial)->toContain("header('X-Content-Type-Options: nosniff');")
        ->and($parcial)->not->toContain('layout.php')
        ->and((string) file_get_contents(dirname(__DIR__, 2) . '/public/index.php'))->toContain("'/hyperv' => \$pages->hyperv(\$method),");
});
