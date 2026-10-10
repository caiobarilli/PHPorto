<?php

declare(strict_types=1);

use App\Http\HypervView;
use App\Http\Respond;
use App\Win\HypervListing;

/**
 * A tela /hyperv montada: a faixa do hospedeiro e a tabela.
 *
 * A regra que estes testes guardam é a da faixa: bloqueio, saída fora do
 * formato e Hyper-V desligado no Windows são faixa de tela inteira, sem tabela.
 * Só o hospedeiro ligado traz a lista — ou uma frase quando não há VM.
 */
function hypervHtml(?string $blocked = null, ?HypervListing $listing = null, bool $timedOut = false): string
{
    return Respond::render('hyperv.php', new HypervView(
        tz: 'America/Sao_Paulo',
        blocked: $blocked,
        listing: $listing,
        readAtUtc: '2026-09-28T12:00:00+00:00',
        timedOut: $timedOut,
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
        ->and($html)->toContain('href="/hyperv">Atualizar</a>')
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

    expect($html)->toContain('<a href="/hyperv" id="hyperv-atualizar">Atualizar</a>')
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

    // 12:00 UTC vira 09:00 em America/Sao_Paulo; a data inteira fica no title.
    expect($html)->toContain('<strong>1 de 2 ligadas</strong>')
        ->and($html)->toContain('lido às <span title="28/09/2026 09:00:00">09:00</span>');
});

it('uma VM só: "ligada" no singular', function () {
    $json = '{"hyperv":true,"vms":[{"name":"Solo","state":"Off","running":false,"memoryBytes":0,"uptimeSeconds":0,"ip":[]}]}';

    expect(hypervHtml(listing: HypervListing::fromOutput($json)))->toContain('<strong>0 de 1 ligada</strong>');
});

it('as colunas vêm em português claro: Nome, Situação, Memória em uso, Ligada há, Endereço IP', function () {
    // A tabela só existe com ao menos uma VM; sem nenhuma, é a frase.
    $json = '{"hyperv":true,"vms":[{"name":"Ubuntu","state":"Running","running":true,"memoryBytes":1073741824,"uptimeSeconds":120,"ip":["10.0.0.1"]}]}';
    $html = hypervHtml(listing: HypervListing::fromOutput($json));

    $ordem = array_map(
        static fn (string $col): int|false => strpos($html, '<th>' . $col . '</th>'),
        ['Nome', 'Situação', 'Memória em uso', 'Ligada há', 'Endereço IP']
    );

    // Todas presentes, e cada uma depois da anterior.
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
        ->and($html)->not->toContain('fetch(')
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
