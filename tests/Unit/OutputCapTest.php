<?php

declare(strict_types=1);

use App\Domain\OutputCap;
use App\Win\PsRunner;
use App\Wsl\Runner;

beforeEach(function () {
    $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phporto-cap-' . bin2hex(random_bytes(4));
    mkdir($this->dir);
});

afterEach(function () {
    foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
        unlink($f);
    }
    rmdir($this->dir);
});

it('o teto é 1 MiB', function () {
    expect(OutputCap::MAX_OUTPUT_BYTES)->toBe(1048576);
});

it('read corta no teto e devolve o tamanho original', function () {
    $arquivo = $this->dir . DIRECTORY_SEPARATOR . 'grande.txt';
    file_put_contents($arquivo, str_repeat('x', 5000));

    [$bytes, $cortou, $tamanho] = OutputCap::read($arquivo, 1000);

    expect($bytes)->toBe(str_repeat('x', 1000))
        ->and($cortou)->toBeTrue()
        ->and($tamanho)->toBe(5000);
});

it('read não corta o que cabe exatamente no teto', function () {
    $arquivo = $this->dir . DIRECTORY_SEPARATOR . 'justo.txt';
    file_put_contents($arquivo, str_repeat('x', 1000));

    [$bytes, $cortou] = OutputCap::read($arquivo, 1000);

    expect(strlen($bytes))->toBe(1000)
        ->and($cortou)->toBeFalse();
});

it('read devolve vazio e sem corte para arquivo ausente', function () {
    expect(OutputCap::read($this->dir . DIRECTORY_SEPARATOR . 'nao-existe.txt'))
        ->toBe(['', false, 0]);
});

it('read com teto zero devolve vazio e marca corte', function () {
    $arquivo = $this->dir . DIRECTORY_SEPARATOR . 'qualquer.txt';
    file_put_contents($arquivo, 'abc');

    expect(OutputCap::read($arquivo, 0))->toBe(['', true, 0]);
});

it('withNotice acrescenta o aviso numa linha própria', function () {
    expect(OutputCap::withNotice('abc', 3, 10))
        ->toBe("abc\n[phporto] SAÍDA CORTADA no teto de 3 bytes (o comando gerou 10).\n");
});

it('Runner do WSL corta no teto real de 1 MiB e avisa na saída', function () {
    $arquivo = $this->dir . DIRECTORY_SEPARATOR . 'wsl-grande.txt';
    file_put_contents($arquivo, str_repeat('y', OutputCap::MAX_OUTPUT_BYTES + 4096));

    [$texto, $cortou] = Runner::readBounded($arquivo);

    expect($cortou)->toBeTrue()
        ->and(substr($texto, 0, OutputCap::MAX_OUTPUT_BYTES))->toBe(str_repeat('y', OutputCap::MAX_OUTPUT_BYTES))
        ->and($texto)->toEndWith(sprintf(
            "[phporto] SAÍDA CORTADA no teto de %d bytes (o comando gerou %d).\n",
            OutputCap::MAX_OUTPUT_BYTES,
            OutputCap::MAX_OUTPUT_BYTES + 4096
        ));
});

it('Runner do WSL não avisa nada quando a saída cabe', function () {
    $arquivo = $this->dir . DIRECTORY_SEPARATOR . 'wsl-pequeno.txt';
    file_put_contents($arquivo, "linha\n");

    expect(Runner::readBounded($arquivo))->toBe(["linha\n", false]);
});

it('Runner do WSL continua convertendo a saída UTF-16 do wsl.exe', function () {
    $arquivo = $this->dir . DIRECTORY_SEPARATOR . 'wsl-utf16.txt';
    file_put_contents($arquivo, (string) iconv('UTF-8', 'UTF-16LE', 'distro não encontrada'));

    [$texto] = Runner::readBounded($arquivo);

    expect($texto)->toBe('distro não encontrada');
});

it('os dois motores escrevem o mesmo aviso de corte', function () {
    $arquivo = $this->dir . DIRECTORY_SEPARATOR . 'igual.txt';
    file_put_contents($arquivo, str_repeat('z', 3000));

    [$wsl] = Runner::readBounded($arquivo, 1000);
    [$win] = PsRunner::readBounded($arquivo, 1000);

    expect($wsl)->toBe($win);
});
