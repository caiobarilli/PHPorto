<?php

declare(strict_types=1);

use App\Http\Respond;

/**
 * A data na tela.
 *
 * O QUE SE GRAVA CONTINUA SENDO UTC: o schema não muda, e a conversão é de
 * exibição, feita o mais tarde possível. Guardar no fuso local pareceria mais
 * simples e quebraria a comparação entre registros gravados antes e depois de
 * uma mudança de horário.
 *
 * O auxiliar mora na Respond porque o /wsl vai reusar: hoje ele imprime o UTC
 * cru do banco, e essa correção já está enfileirada.
 */

it('formata em dd/mm/aaaa hh:mm:ss', function () {
    expect(Respond::dateTime('2026-09-08T23:29:43+00:00', 'UTC'))->toBe('08/09/2026 23:29:43');
});

it('CONVERTE do UTC para o fuso pedido', function () {
    // São Paulo em setembro: UTC-3. As 23:29 de 08/09 em UTC são 20:29 aqui.
    expect(Respond::dateTime('2026-09-08T23:29:43+00:00', 'America/Sao_Paulo'))
        ->toBe('08/09/2026 20:29:43');
});

it('atravessa a virada do dia sem errar a data', function () {
    // 01:30 UTC do dia 9 é 22:30 do dia 8 em São Paulo.
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
    // O log é prova: inventar data seria pior que mostrar o valor estranho.
    expect(Respond::dateTime('isto nao e data', 'UTC'))->toBe('isto nao e data');
});

it('fuso inválido não derruba a página', function () {
    // Config::timezone() já barra fuso inválido, mas quem chama pode ser
    // outro caminho amanhã, e uma página que estoura por causa de um rótulo
    // de fuso seria péssima troca.
    expect(Respond::dateTime('2026-09-08T23:29:43+00:00', 'Fuso/Inexistente'))
        ->toBe('2026-09-08T23:29:43+00:00');
});
