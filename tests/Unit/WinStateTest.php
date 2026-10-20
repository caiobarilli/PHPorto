<?php

declare(strict_types=1);

use App\Domain\WinState;
use App\Domain\WinStateScope;

it('payload vazio grava como objeto JSON, não como lista', function () {
    expect((new WinState(WinStateScope::Applied, 'gdid'))->encodedPayload())->toBe('{}');
});

it('payload com valores atravessa ida e volta igual', function () {
    $original = ['Preset' => 'standard', 'Duration' => 30, 'Undo' => true];

    $json = (new WinState(WinStateScope::Applied, 'tweaks', $original))->encodedPayload();

    expect(WinState::decodePayload($json))->toBe($original);
});

it('não escapa barra nem acento, para o valor gravado ser legível no banco', function () {
    $json = (new WinState(WinStateScope::Selection, 'install', ['Apps' => 'Git.Git/ação']))->encodedPayload();

    expect($json)->toBe('{"Apps":"Git.Git/ação"}');
});

it('DECODIFICA OBJETO VAZIO COMO MAPA VAZIO, e não como linha estragada', function () {
    expect(WinState::decodePayload('{}'))->toBe([]);
});

it('recusa o que não é objeto JSON', function (mixed $entrada) {
    expect(WinState::decodePayload($entrada))->toBeNull();
})->with([
    'lista'          => ['["standard"]'],
    'escalar'        => ['standard'],
    'string vazia'   => [''],
    'só espaço'      => ['   '],
    'nulo'           => [null],
    'número'         => [42],
    'JSON quebrado'  => ['{"Preset":'],
    'null literal'   => ['null'],
]);

it('descarta a chave de tipo que a allowlist não produz, e mantém o resto', function () {
    $decodificado = WinState::decodePayload('{"Preset":"standard","Lixo":null,"Fundo":{"a":1},"Float":1.5}');

    expect($decodificado)->toBe(['Preset' => 'standard']);
});

it('a chave junta escopo e ação com dois-pontos', function () {
    expect((new WinState(WinStateScope::Applied, 'tweaks'))->key())->toBe('aplicado:tweaks')
        ->and((new WinState(WinStateScope::Selection, 'tweaks'))->key())->toBe('selecao:tweaks');
});

it('o enum tem os três escopos, com o valor que vai para o banco', function () {
    expect(array_map(static fn (WinStateScope $s): string => $s->value, WinStateScope::cases()))
        ->toBe(['aplicado', 'selecao', 'pendente-de-reinicio']);
});

it('a leitura distingue os três, e o pendente-de-reinicio não vira aplicado nem sumida', function () {
    expect(WinStateScope::fromStorage('aplicado'))->toBe(WinStateScope::Applied)
        ->and(WinStateScope::fromStorage('selecao'))->toBe(WinStateScope::Selection)
        ->and(WinStateScope::fromStorage('pendente-de-reinicio'))->toBe(WinStateScope::PendingReboot)
        ->and(WinStateScope::fromStorage('inventado'))->toBeNull();
});

it('escopo desconhecido vindo do banco é NULO, e não um caso padrão', function () {
    expect(WinStateScope::fromStorage('inventado'))->toBeNull()
        ->and(WinStateScope::fromStorage(null))->toBeNull()
        ->and(WinStateScope::fromStorage(42))->toBeNull()
        ->and(WinStateScope::fromStorage('aplicado'))->toBe(WinStateScope::Applied)
        ->and(WinStateScope::fromStorage(WinStateScope::Selection))->toBe(WinStateScope::Selection);
});
