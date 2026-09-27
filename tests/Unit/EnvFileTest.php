<?php

declare(strict_types=1);

use App\Config\EnvFile;

it('lê o valor da chave, vazio quando presente sem valor, null quando ausente', function () {
    $env = "A=1\nPHPORTO_AUTH_TOKEN=abc\nB=\"x\"\nVAZIA=\n";

    expect(EnvFile::value($env, 'PHPORTO_AUTH_TOKEN'))->toBe('abc')
        ->and(EnvFile::value($env, 'B'))->toBe('x')
        ->and(EnvFile::value($env, 'VAZIA'))->toBe('')
        ->and(EnvFile::value($env, 'NAO'))->toBeNull();
});

it('não confunde chave com prefixo nem linha comentada', function () {
    $env = "# PHPORTO_AUTH_TOKEN=comentado\nPHPORTO_AUTH_TOKEN_X=outra\n";

    expect(EnvFile::value($env, 'PHPORTO_AUTH_TOKEN'))->toBeNull();
});

it('troca a linha da chave e deixa o resto byte a byte, em CRLF', function () {
    $env = "DB=sqlite\r\nMYSQL_PASSWORD=segredo\r\nPHPORTO_AUTH_TOKEN=velho\r\nTZ=UTC\r\n";

    expect(EnvFile::with($env, 'PHPORTO_AUTH_TOKEN', 'novo'))
        ->toBe("DB=sqlite\r\nMYSQL_PASSWORD=segredo\r\nPHPORTO_AUTH_TOKEN=novo\r\nTZ=UTC\r\n");
});

it('acrescenta a chave ausente no fim, no fim de linha do arquivo', function () {
    expect(EnvFile::with("A=1\r\nB=2", 'K', 'v'))->toBe("A=1\r\nB=2\r\nK=v\r\n")
        ->and(EnvFile::with("A=1\n", 'K', 'v'))->toBe("A=1\nK=v\n")
        ->and(EnvFile::with('', 'K', 'v'))->toBe("K=v\n");
});

it('troca só a primeira ocorrência, e não a linha comentada', function () {
    expect(EnvFile::with("# K=x\nK=1\nK=2\n", 'K', 'v'))->toBe("# K=x\nK=v\nK=2\n");
});
