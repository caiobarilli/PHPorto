<?php

declare(strict_types=1);

use App\Http\WinTab;

it('a aba da URL é lida, e a desconhecida cai em Sistema sem erro', function (mixed $valor, WinTab $esperado) {
    expect(WinTab::fromQuery($valor))->toBe($esperado);
})->with([
    'rede'        => ['rede', WinTab::Rede],
    'serviços'    => ['servicos', WinTab::Servicos],
    'ausente'     => [null, WinTab::Sistema],
    'desconhecida' => ['nada', WinTab::Sistema],
    'maiúscula'   => ['REDE', WinTab::Sistema],
    'lista'       => [['rede'], WinTab::Sistema],
]);

it('a ordem do menu é Sistema, Rede, Aplicativos, Serviços, com a URL de cada uma', function () {
    expect(array_map(static fn (WinTab $t): string => $t->label(), WinTab::cases()))
        ->toBe(['Sistema', 'Rede', 'Aplicativos', 'Serviços'])
        ->and(WinTab::Aplicativos->url())->toBe('/win?aba=aplicativos');
});

it('todo 303 do POST da /win volta para a aba de onde veio', function () {
    // Lido como texto: o POST da /win sai por exit, e exercitá-lo por HTTP
    // recolheria as órfãs do files/ desta máquina.
    $fonte = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Http/Pages.php');

    expect($fonte)->not->toContain("Respond::redirect('/win')")
        ->and(substr_count($fonte, 'Respond::redirect($aba->url())'))->toBe(8);
});
