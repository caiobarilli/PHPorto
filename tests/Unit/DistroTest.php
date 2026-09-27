<?php

declare(strict_types=1);

use App\Wsl\Distro;

/*
 * Os bytes são os medidos nesta máquina, com Debian e docker-desktop de pé.
 */

it('lê a listagem -q em UTF-8, como o WSL_UTF8=1 entrega', function () {
    $bruto = hex2bin('44656269616e0d0a646f636b65722d6465736b746f700d0a');

    expect(Distro::parse((string) $bruto))->toBe(['Debian', 'docker-desktop'])
        ->and(Distro::contains(Distro::parse((string) $bruto), 'debian'))->toBeTrue();
});

it('lê a mesma listagem em UTF-16LE, sem o WSL_UTF8', function () {
    $bruto = hex2bin('440065006200690061006e000d000a0064006f0063006b00650072002d006400650073006b0074006f0070000d000a00');

    expect(Distro::parse((string) $bruto))->toBe(['Debian', 'docker-desktop']);
});

it('a listagem LONGA não serve para achar a distro padrão, e por isso se usa -q', function () {
    $longa = "Distribuições do Subsistema do Windows para Linux:\r\nDebian (Padrão)\r\ndocker-desktop\r\n";

    expect(Distro::contains(Distro::parse($longa), 'Debian'))->toBeFalse();
});

it('listagem sem a distro, ou só com uma mensagem, diz que ela não está de pé', function () {
    expect(Distro::contains(Distro::parse("docker-desktop\r\n"), 'Debian'))->toBeFalse()
        ->and(Distro::contains(Distro::parse("Não há distribuições em execução.\r\n"), 'Debian'))->toBeFalse()
        ->and(Distro::contains([], 'Debian'))->toBeFalse();
});

it('nome parecido não casa: a comparação é da linha inteira', function () {
    expect(Distro::contains(['Debian-12'], 'Debian'))->toBeFalse();
});
