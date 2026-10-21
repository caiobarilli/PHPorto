<?php

declare(strict_types=1);

use App\Http\Api;

it('aceita application/json exato', function (string $tipo): void {
    expect(Api::isJson($tipo))->toBeTrue();
})->with([
    'application/json',
    'application/json; charset=utf-8',
    'Application/JSON',
    ' application/json ;charset=UTF-8',
]);

it('recusa o que só contém application/json', function (mixed $tipo): void {
    expect(Api::isJson($tipo))->toBeFalse();
})->with([
    'text/plain; x=application/json',
    'text/plain;application/json',
    'application/json-patch+json',
    'application/jsonx',
    'multipart/form-data; boundary=application/json',
    '',
    null,
    [['application/json']],
]);
