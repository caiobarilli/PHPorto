<?php

declare(strict_types=1);

use App\Http\Respond;

it('formata em dd/mm/aaaa hh:mm:ss', function () {
    expect(Respond::dateTime('2026-09-08T23:29:43+00:00', 'UTC'))->toBe('08/09/2026 23:29:43');
});

it('CONVERTE do UTC para o fuso pedido', function () {
    expect(Respond::dateTime('2026-09-08T23:29:43+00:00', 'America/Sao_Paulo'))
        ->toBe('08/09/2026 20:29:43');
});

it('atravessa a virada do dia sem errar a data', function () {
    expect(Respond::dateTime('2026-09-09T01:30:00+00:00', 'America/Sao_Paulo'))
        ->toBe('08/09/2026 22:30:00');
});

it('trata como UTC o carimbo sem fuso, que é como o sqlite devolve', function () {
    expect(Respond::dateTime('2026-09-08 23:29:43', 'UTC'))->toBe('08/09/2026 23:29:43');
});

it('aceita o formato ISO que os três providers devolvem', function () {
    expect(Respond::dateTime('2026-01-02T03:04:05+00:00', 'UTC'))->toBe('02/01/2026 03:04:05');
});

it('data ausente vira travessão, e não string vazia', function (?string $valor) {
    expect(Respond::dateTime($valor, 'UTC'))->toBe('—');
})->with([null, '', '   ']);

it('VALOR IMPOSSÍVEL DE INTERPRETAR VOLTA COMO VEIO', function () {
    expect(Respond::dateTime('isto nao e data', 'UTC'))->toBe('isto nao e data');
});

it('fuso inválido não derruba a página', function () {
    expect(Respond::dateTime('2026-09-08T23:29:43+00:00', 'Fuso/Inexistente'))
        ->toBe('2026-09-08T23:29:43+00:00');
});
