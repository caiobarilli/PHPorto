<?php

declare(strict_types=1);

use App\Exceptions\StorageException;

it('é uma RuntimeException', function () {
    expect(new StorageException('x'))->toBeInstanceOf(RuntimeException::class);
});

it('preserva a mensagem', function () {
    expect((new StorageException('Falha ao gravar.'))->getMessage())->toBe('Falha ao gravar.');
});

it('encadeia a exceção anterior (previous)', function () {
    $previous = new RuntimeException('driver error');

    expect((new StorageException('falha', 0, $previous))->getPrevious())->toBe($previous);
});
