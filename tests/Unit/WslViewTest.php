<?php

declare(strict_types=1);

use App\Domain\Execution;
use App\Domain\ExecutionKind;
use App\Http\Respond;
use App\Http\WslView;

/**
 * Monta a /wsl com as execuções dadas e devolve o HTML.
 *
 * @param list<Execution> $rows
 */
function wslHtml(array $rows): string
{
    return Respond::render('wsl.php', new WslView(
        rows: $rows,
        result: $rows[0] ?? null,
        blocked: null,
        notice: null,
        csrfToken: 't',
        csrfField: '_csrf',
        distro: 'Debian',
        root: '/home/x',
        timeout: 120,
        tz: 'America/Sao_Paulo',
        coldStartSeconds: 5,
        maxOutputBytes: 1048576,
        maxCommandBytes: 65536,
        maxPathBytes: 4096,
    ));
}

it('a /wsl mostra a data em dd/mm/aaaa hh:mm:ss no fuso, e não o UTC do banco', function () {
    $html = wslHtml([new Execution('ls', '', 0, 10, ExecutionKind::Comando, false, '2026-09-09 02:54:17')]);

    // Uma no painel da última execução, uma na tabela.
    expect(substr_count($html, '08/09/2026 23:54:17'))->toBe(2)
        ->and($html)->not->toContain('>2026-09-09 02:54:17');
});

it('o JSON que o botão de copiar entrega continua com o UTC gravado', function () {
    $html = wslHtml([new Execution('ls', '', 0, 10, ExecutionKind::Comando, false, '2026-09-09 02:54:17')]);

    expect($html)->toContain('"created_at":"2026-09-09 02:54:17"');
});

it('o card de anexos tem o botão de inverter, que não envia o formulário', function () {
    expect(wslHtml([]))->toContain('<button type="button" class="btn btn-sm btn-ghost" id="btn-inverter">Inverter origem e destino</button>');
});
