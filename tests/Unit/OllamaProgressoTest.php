<?php

declare(strict_types=1);

use App\Http\OllamaView;

$mb = static fn (int $b): string => $b . ' B';

it('traduz os estados conhecidos do download', function (string $raw, string $pt) use ($mb) {
    expect(OllamaView::progressText(['estado' => $raw, 'bytes' => 0, 'total' => 0], $mb))->toBe($pt);
})->with([
    ['success', 'pronto'],
    ['pulling manifest', 'baixando o manifesto'],
    ['verifying sha256 digest', 'conferindo o SHA-256'],
    ['writing manifest', 'gravando'],
    ['pulling 6a0746a1ec1a', 'baixando 6a0746a1ec1a'],
    ['pulling sha256:6a0746a1ec1aa3b2c4d5e6f7', 'baixando 6a0746a1ec1a'],
]);

it('mantém o progresso ao baixar uma camada', function () use ($mb) {
    expect(OllamaView::progressText(['estado' => 'pulling 6a0746a1ec1a', 'bytes' => 4, 'total' => 100], $mb))
        ->toBe('baixando 6a0746a1ec1a — 4 B de 100 B (4%)');
});

it('estado desconhecido continua como veio', function () use ($mb) {
    expect(OllamaView::progressText(['estado' => 'algo novo', 'bytes' => 0, 'total' => 0], $mb))->toBe('algo novo');
});
