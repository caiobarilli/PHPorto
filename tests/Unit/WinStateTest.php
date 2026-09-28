<?php

declare(strict_types=1);

use App\Domain\WinState;
use App\Domain\WinStateScope;

/**
 * O formato de ida e volta do payload, que os três drivers compartilham.
 *
 * MORA NO DOMÍNIO, e não em cada provider, para haver um lugar só quando ele
 * mudar — então é aqui que ele é testado, uma vez, em vez de três.
 */
it('payload vazio grava como objeto JSON, não como lista', function () {
    // '[]' e '{}' voltam diferentes do json_decode, e a ausência de parâmetro
    // tem de voltar como mapa.
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
    // Este caso derrubou a primeira versão do decodePayload: array_is_list([])
    // é TRUE, então o '{}' legítimo de uma ação sem parâmetro era descartado
    // como se fosse lista, e o gdid aplicado sumia da leitura.
    expect(WinState::decodePayload('{}'))->toBe([]);
});

it('recusa o que não é objeto JSON', function (mixed $entrada) {
    // Nulo é "descarte esta linha", e quem chama descarta: payload ilegível
    // significa que não se sabe o que a linha afirma.
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
    // A allowlist só produz string, inteiro e booleano. Repassar outro tipo
    // faria o chamador tratar o que ele não declara.
    $decodificado = WinState::decodePayload('{"Preset":"standard","Lixo":null,"Fundo":{"a":1},"Float":1.5}');

    expect($decodificado)->toBe(['Preset' => 'standard']);
});

it('a chave junta escopo e ação com dois-pontos', function () {
    // É o _id do Mongo. O separador é ':' porque nenhum dos dois lados o
    // aceita — escopo é enum fechado, ação é chave de allowlist.
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
    // Diferente do ExecutionKind::fromStorage(), que cai em 'comando': lá a
    // linha existe e precisa aparecer na tabela. Aqui, escopo desconhecido
    // significa que não se sabe o que a linha afirma, e tratá-la como
    // 'aplicado' faria a tela dizer que algo está aplicado sem base.
    expect(WinStateScope::fromStorage('inventado'))->toBeNull()
        ->and(WinStateScope::fromStorage(null))->toBeNull()
        ->and(WinStateScope::fromStorage(42))->toBeNull()
        ->and(WinStateScope::fromStorage('aplicado'))->toBe(WinStateScope::Applied)
        ->and(WinStateScope::fromStorage(WinStateScope::Selection))->toBe(WinStateScope::Selection);
});
