<?php

declare(strict_types=1);

use App\Win\Elevation;
use App\Win\JobChannel;
use App\Win\WinAction;

beforeEach(function () {
    $this->files = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phporto_importadas_' . bin2hex(random_bytes(6));
    mkdir($this->files, 0o775, true);
    $this->protegida = $this->files . DIRECTORY_SEPARATOR . Elevation::DIR_PROTEGIDA;
    mkdir($this->protegida);
    $this->lista = $this->files . DIRECTORY_SEPARATOR . Elevation::F_IMPORTADAS;
    $this->canal = new JobChannel($this->files);
});

afterEach(function () {
    @chmod((string) $this->files, 0o775);
    @chmod((string) $this->protegida, 0o775);

    if (is_string($this->files) && is_dir($this->files)) {
        foreach ([$this->protegida, $this->files] as $pasta) {
            foreach (glob($pasta . '/*') ?: [] as $f) {
                is_dir($f) ? @rmdir($f) : @unlink($f);
            }
            @rmdir($pasta);
        }
    }
});

function orfaNaProtegida(object $ctx, string $id, string $acao = 'gdid'): void
{
    $dir = (string) $ctx->protegida;
    file_put_contents($dir . DIRECTORY_SEPARATOR . 'win-out-' . $id . '.txt', "saida\n");
    file_put_contents($dir . DIRECTORY_SEPARATOR . 'win-done-' . $id . '.json', (string) json_encode([
        'id' => $id, 'exit' => 0, 'ms' => 10, 'nota' => '', 'acao' => $acao,
        'params' => ['SubAction' => 'status'], 'fim' => '2026-10-23 07:00:00',
    ]));
}

function prenderProtegida(object $ctx, bool $prender): void
{
    chmod((string) $ctx->protegida, $prender ? 0o555 : 0o775);
    clearstatcache();
}

it('ÓRFÃ QUE NÃO SAI DA PASTA entra uma vez só: marcada, não volta na próxima visita', function () {
    orfaNaProtegida($this, 'a1b2c3d4e5f6');
    prenderProtegida($this, true);

    $orfas = $this->canal->collectOrphans();

    expect($orfas)->toHaveCount(1)
        ->and($this->canal->discardOrphan('a1b2c3d4e5f6'))->toBeTrue()
        ->and(is_file($this->protegida . '/win-done-a1b2c3d4e5f6.json'))->toBeTrue()
        ->and($this->canal->collectOrphans())->toBe([])
        ->and((new JobChannel($this->files))->collectOrphans())->toBe([]);
});

it('a marca mora em files/, fora da pasta protegida, e só tem o id', function () {
    orfaNaProtegida($this, 'b1b2c3d4e5f6');
    prenderProtegida($this, true);

    $this->canal->discardOrphan('b1b2c3d4e5f6');

    expect(is_file($this->lista))->toBeTrue()
        ->and(file_get_contents($this->lista))->toBe("b1b2c3d4e5f6\n")
        ->and(is_file($this->protegida . DIRECTORY_SEPARATOR . Elevation::F_IMPORTADAS))->toBeFalse()
        ->and($this->canal->importedIds())->toBe(['b1b2c3d4e5f6' => true]);
});

it('órfã apagada de verdade não deixa marca', function () {
    orfaNaProtegida($this, 'c1b2c3d4e5f6');

    expect($this->canal->discardOrphan('c1b2c3d4e5f6'))->toBeTrue()
        ->and(is_file($this->lista))->toBeFalse()
        ->and($this->canal->collectOrphans())->toBe([]);
});

it('a marca de uma órfã não esconde outra que ainda não entrou', function () {
    orfaNaProtegida($this, 'd1b2c3d4e5f6');
    orfaNaProtegida($this, 'd2b2c3d4e5f6', 'rdp');
    prenderProtegida($this, true);

    $this->canal->discardOrphan('d1b2c3d4e5f6');
    $orfas = $this->canal->collectOrphans();

    expect($orfas)->toHaveCount(1)
        ->and($orfas[0]->id)->toBe('d2b2c3d4e5f6');
});

it('sem conseguir gravar a marca, discardOrphan avisa e a órfã continua visível', function () {
    orfaNaProtegida($this, 'e1b2c3d4e5f6');
    prenderProtegida($this, true);
    chmod((string) $this->files, 0o555);
    clearstatcache();

    expect($this->canal->discardOrphan('e1b2c3d4e5f6'))->toBeFalse()
        ->and($this->canal->collectOrphans())->toHaveCount(1);
});

it('id forjado não vira marca', function () {
    expect($this->canal->discardOrphan('../../x'))->toBeFalse()
        ->and(is_file($this->lista))->toBeFalse();
});

it('a lista some com o id cujo par o worker já apagou', function () {
    orfaNaProtegida($this, 'f1b2c3d4e5f6');
    prenderProtegida($this, true);
    $this->canal->discardOrphan('f1b2c3d4e5f6');

    prenderProtegida($this, false);
    unlink($this->protegida . '/win-done-f1b2c3d4e5f6.json');
    unlink($this->protegida . '/win-out-f1b2c3d4e5f6.txt');
    orfaNaProtegida($this, 'f2b2c3d4e5f6');
    prenderProtegida($this, true);

    $this->canal->discardOrphan('f2b2c3d4e5f6');

    expect(file_get_contents($this->lista))->toBe("f2b2c3d4e5f6\n");
});

it('a lista tem teto: fica com as mais novas', function () {
    $velhas = [];

    for ($i = 0; $i < JobChannel::MAX_IMPORTADAS + 20; $i++) {
        $id = sprintf('%012x', $i);
        $velhas[] = $id;
        file_put_contents($this->protegida . '/win-done-' . $id . '.json', '{}');
    }

    file_put_contents($this->lista, implode("\n", $velhas) . "\n");
    orfaNaProtegida($this, 'ffffffffffff');
    prenderProtegida($this, true);

    $this->canal->discardOrphan('ffffffffffff');
    $ids = array_keys($this->canal->importedIds());

    expect($ids)->toHaveCount(JobChannel::MAX_IMPORTADAS)
        ->and(end($ids))->toBe('ffffffffffff')
        ->and($ids)->not->toContain('000000000000');
});

it('linha fora do formato na lista é ignorada', function () {
    file_put_contents($this->lista, "../../x\nABCDEF123456\n0123456789abc\n0123456789ab\r\n");

    expect($this->canal->importedIds())->toBe(['0123456789ab' => true]);
});

it('collect() que não consegue apagar o par marca o id: não volta como órfã', function () {
    $this->canal->send(WinAction::Hyperv, [], 'n');

    orfaNaProtegida($this, 'aa11bb22cc33', 'hyperv');
    prenderProtegida($this, true);

    $r = $this->canal->collect(5);

    expect($r->output)->toContain('Não foi possível apagar')
        ->and($this->canal->importedIds())->toBe(['aa11bb22cc33' => true])
        ->and((new JobChannel($this->files))->collectOrphans())->toBe([]);
});

it('collect() que apaga o par não deixa marca', function () {
    $this->canal->send(WinAction::Hyperv, [], 'n');

    orfaNaProtegida($this, 'bb11bb22cc33', 'hyperv');

    $this->canal->collect(5);

    expect(is_file($this->lista))->toBeFalse();
});
