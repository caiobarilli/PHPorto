<?php

declare(strict_types=1);

use App\Wsl\InputLimit;

it('os tetos são 64 KB para o comando e 4 KB por caminho', function () {
    expect(InputLimit::MAX_COMMAND_BYTES)->toBe(65536)
        ->and(InputLimit::MAX_PATH_BYTES)->toBe(4096);
});

it('aceita comando exatamente no teto', function () {
    InputLimit::command(str_repeat('a', InputLimit::MAX_COMMAND_BYTES));

    expect(true)->toBeTrue();
});

it('recusa comando um byte acima do teto, dizendo o tamanho', function () {
    InputLimit::command(str_repeat('a', InputLimit::MAX_COMMAND_BYTES + 1));
})->throws(InvalidArgumentException::class, 'O comando passou do teto de 65536 bytes (tem 65537).');

it('conta bytes e não caracteres no comando', function () {
    // "é" são dois bytes em UTF-8: 32769 deles passam de 64 KB com metade dos caracteres.
    InputLimit::command(str_repeat('é', 32769));
})->throws(InvalidArgumentException::class);

it('aceita caminho exatamente no teto', function () {
    InputLimit::path('Origem', str_repeat('p', InputLimit::MAX_PATH_BYTES));

    expect(true)->toBeTrue();
});

it('recusa caminho acima do teto, nomeando o campo', function () {
    InputLimit::path('Destino', str_repeat('p', InputLimit::MAX_PATH_BYTES + 1));
})->throws(InvalidArgumentException::class, 'Destino passou do teto de 4096 bytes (tem 4097).');
