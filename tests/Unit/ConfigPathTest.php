<?php

declare(strict_types=1);

use App\Config\Config;

it('caminho absoluto do Windows vale nas duas barras, e volta como veio', function (string $caminho) {
    expect(Config::resolvePath($caminho, 'C:/raiz'))->toBe($caminho);
})->with([
    'barra invertida' => ['C:\Users\x\db.sqlite'],
    'barra normal'    => ['C:/Users/x/db.sqlite'],
    'drive minúsculo' => ['d:\dados\db.sqlite'],
    'unix'            => ['/var/lib/db.sqlite'],
    'UNC'             => ['\\servidor\pasta\db.sqlite'],
]);

it('caminho relativo é ancorado na raiz do projeto', function () {
    expect(Config::resolvePath('storage/database.sqlite', 'C:/raiz'))->toBe('C:/raiz/storage/database.sqlite')
        ->and(Config::resolvePath('storage\database.sqlite', 'C:/raiz'))->toBe('C:/raiz/storage\database.sqlite');
});
