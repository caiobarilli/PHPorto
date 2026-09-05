<?php

declare(strict_types=1);

use App\Exceptions\DuplicateEntryException;

it('é uma RuntimeException', function () {
    expect(new DuplicateEntryException('x'))->toBeInstanceOf(RuntimeException::class);
});

it('preserva a mensagem', function () {
    $e = new DuplicateEntryException('Este registro já existe.');

    expect($e->getMessage())->toBe('Este registro já existe.');
});

it('encadeia a exceção anterior (previous)', function () {
    $previous = new RuntimeException('driver error');
    $e        = new DuplicateEntryException('dup', 0, $previous);

    expect($e->getPrevious())->toBe($previous);
});
