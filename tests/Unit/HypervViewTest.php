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
function hypervHtml(?string $blocked = null, ?HypervListing $listing = null): string
{
    return Respond::render('hyperv.php', new HypervView(
        tz: 'America/Sao_Paulo',
        blocked: $blocked,
        listing: $listing,
        readAtUtc: '2026-09-28T12:00:00+00:00',
    ));
}

it('bloqueado: a faixa é a tela inteira, sem tabela', function () {
    $html = hypervHtml(blocked: 'O PowerShell elevado está desligado.');

    expect($html)->toContain('Não deu para listar as máquinas virtuais.')
        ->and($html)->toContain('O PowerShell elevado está desligado.')
        ->and($html)->not->toContain('<table>');
});

it('Hyper-V desligado no Windows: faixa de tela inteira, sem tabela', function () {
    $html = hypervHtml(listing: HypervListing::fromOutput('{"hyperv":false,"vms":[]}'));

    expect($html)->toContain('O Hyper-V não está ligado no Windows.')
        ->and($html)->toContain('reiniciar o computador')
        ->and($html)->not->toContain('<table>');
});

it('saída fora do formato: mostra o problema, sem tabela', function () {
    $html = hypervHtml(listing: HypervListing::fromOutput('recusado, sem json'));

    expect($html)->toContain('não voltou no formato esperado')
        ->and($html)->not->toContain('<table>');
});

it('sem nenhuma VM: uma frase no lugar da tabela', function () {
    $html = hypervHtml(listing: HypervListing::fromOutput('{"hyperv":true,"vms":[]}'));

    expect($html)->toContain('Nenhuma máquina virtual neste hospedeiro.')
        ->and($html)->not->toContain('<table>')
        ->and($html)->toContain('0 máquinas virtuais')
        ->and($html)->toContain('0 rodando');
});

it('hospedeiro ligado: a faixa conta as VMs e a hora no fuso do Windows', function () {
    $json = '{"hyperv":true,"vms":['
        . '{"name":"Ubuntu","state":"Running","running":true,"memoryBytes":2147483648,"uptimeSeconds":3661,"ip":["192.168.1.5"]},'
        . '{"name":"Win11","state":"Off","running":false,"memoryBytes":0,"uptimeSeconds":0,"ip":[]}'
        . ']}';

    $html = hypervHtml(listing: HypervListing::fromOutput($json));

    // 12:00 UTC vira 09:00 em America/Sao_Paulo, no formato dd/mm/aaaa hh:mm:ss.
    expect($html)->toContain('2 máquinas virtuais')
        ->and($html)->toContain('1 rodando')
        ->and($html)->toContain('lido em 28/09/2026 09:00:00');
});

it('as colunas vêm na ordem nome, estado, memória, uptime, IP', function () {
    // A tabela só existe com ao menos uma VM; sem nenhuma, é a frase.
    $json = '{"hyperv":true,"vms":[{"name":"Ubuntu","state":"Running","running":true,"memoryBytes":1073741824,"uptimeSeconds":120,"ip":["10.0.0.1"]}]}';
    $html = hypervHtml(listing: HypervListing::fromOutput($json));

    $ordem = array_map(
        static fn (string $col): int|false => strpos($html, '<th>' . $col . '</th>'),
        ['nome', 'estado', 'memória', 'uptime', 'IP']
    );

    // Todas presentes, e cada uma depois da anterior.
    expect($ordem)->not->toContain(false);

    for ($i = 1; $i < count($ordem); $i++) {
        expect($ordem[$i])->toBeGreaterThan($ordem[$i - 1]);
    }
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
