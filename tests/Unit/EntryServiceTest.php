<?php

declare(strict_types=1);

use App\Exceptions\DuplicateEntryException;
use App\Services\EntryService;
use Tests\Fakes\FakeProvider;

it('registra um valor válido e persiste no provider', function () {
    $provider = new FakeProvider();
    $service  = new EntryService($provider);

    $service->register('valor-qualquer');

    expect($provider->inserted)->toBe(['valor-qualquer']);
});

it('normaliza o valor (trim) antes de persistir', function () {
    $provider = new FakeProvider();
    $service  = new EntryService($provider);

    $service->register("  valor-qualquer\t");

    expect($provider->inserted)->toBe(['valor-qualquer']);
});

it('preserva a caixa do valor — normalize() só apara as bordas', function () {
    $provider = new FakeProvider();
    $service  = new EntryService($provider);

    $service->register('  Valor-Com-CAIXA  ');

    expect($provider->inserted)->toBe(['Valor-Com-CAIXA']);
});

it('rejeita valor vazio', function () {
    $service = new EntryService(new FakeProvider());

    $service->register('');
})->throws(InvalidArgumentException::class);

it('rejeita valor só com espaços (após o trim)', function () {
    $service = new EntryService(new FakeProvider());

    $service->register('   ');
})->throws(InvalidArgumentException::class);

it('não toca no provider quando o valor é inválido', function () {
    $provider = new FakeProvider();
    $service  = new EntryService($provider);

    try {
        $service->register('   ');
    } catch (InvalidArgumentException) {
        // esperado
    }

    expect($provider->existsCalls)->toBe(0)
        ->and($provider->inserted)->toBe([]);
});

it('lança DuplicateEntryException quando o valor já existe', function () {
    $service = new EntryService(new FakeProvider(['ja-existe']));

    $service->register('ja-existe');
})->throws(DuplicateEntryException::class);

it('não insere quando o valor é duplicado', function () {
    $provider = new FakeProvider(['ja-existe']);
    $service  = new EntryService($provider);

    try {
        $service->register('  ja-existe  '); // normaliza e bate no duplicado
    } catch (DuplicateEntryException) {
        // esperado
    }

    expect($provider->inserted)->toBe([]);
});
