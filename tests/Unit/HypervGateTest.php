<?php

declare(strict_types=1);

use App\Win\HypervGate;

/**
 * O estado persistente do painel do Hyper-V.
 *
 * NADA AQUI CONFERE O RECURSO DE VERDADE. Conferir chama o powershell.exe e
 * depende de a máquina ter Hyper-V — não pode entrar numa suíte que roda
 * sozinha e em qualquer lugar. O que dá para verificar sem sair do disco é
 * justamente o que decide se a rota /hyperv abre: a leitura fail-safe do
 * arquivo de estado, e o ligar/desligar que grava e apaga.
 *
 * O caminho de ligar de verdade, com a conferência do CIM, foi exercitado à
 * mão nesta máquina (InstallState=1) e está anotado no relatório da fatia.
 */

beforeEach(function () {
    $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phporto_hv_' . bin2hex(random_bytes(6));
    mkdir($base . DIRECTORY_SEPARATOR . 'files', 0o775, true);
    mkdir($base . DIRECTORY_SEPARATOR . 'storage', 0o775, true);

    $this->base    = $base;
    $this->files   = $base . DIRECTORY_SEPARATOR . 'files';
    $this->storage = $base . DIRECTORY_SEPARATOR . 'storage';
});

afterEach(function () {
    if (!is_string($this->base) || !is_dir($this->base)) {
        return;
    }

    foreach (['files', 'storage'] as $sub) {
        foreach (glob($this->base . DIRECTORY_SEPARATOR . $sub . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->base . DIRECTORY_SEPARATOR . $sub);
    }

    @rmdir($this->base);
});

function gate(object $ctx): HypervGate
{
    return new HypervGate(
        storageDir: (string) $ctx->storage,
        filesDir: (string) $ctx->files,
    );
}

/** Grava o arquivo de estado com o conteúdo cru que se quiser. */
function gravarEstado(object $ctx, string $conteudo): void
{
    file_put_contents((string) $ctx->storage . DIRECTORY_SEPARATOR . HypervGate::F_STATE, $conteudo);
}

// ---------------------------------------------------------------- desabilitado

it('sem arquivo está desabilitado, e sem drama', function () {
    expect(gate($this)->enabled())->toBeFalse()
        ->and(gate($this)->enabledAt())->toBeNull();
});

it('ARQUIVO ILEGÍVEL OU SEM enabled verdadeiro lê como desabilitado', function (string $conteudo) {
    // Mesma regra do Flags e da Elevation: a falha cai para o lado seguro por
    // construção. Aqui o lado seguro é NÃO abrir a rota /hyperv.
    gravarEstado($this, $conteudo);

    expect(gate($this)->enabled())->toBeFalse();
})->with([
    'nao e json',
    '',
    '   ',
    '[]',
    '{}',
    '{"enabled": false, "enabled_at": "x"}',
    '{"enabled": 1, "enabled_at": "x"}',
    '{"enabled": "true", "enabled_at": "x"}',
    '{"enabled_at": "x"}',
]);

// ------------------------------------------------------------------- habilitado

it('com enabled verdadeiro está habilitado, e devolve desde quando', function () {
    gravarEstado($this, (string) json_encode(['enabled' => true, 'enabled_at' => '2026-09-28T12:00:00+00:00']));

    expect(gate($this)->enabled())->toBeTrue()
        ->and(gate($this)->enabledAt())->toBe('2026-09-28T12:00:00+00:00');
});

it('enabled verdadeiro sem data ainda está habilitado, com data vazia', function () {
    gravarEstado($this, (string) json_encode(['enabled' => true]));

    expect(gate($this)->enabled())->toBeTrue()
        ->and(gate($this)->enabledAt())->toBe('');
});

// --------------------------------------------------------------------- desligar

it('DESLIGAR apaga o arquivo, e volta ao estado desabilitado', function () {
    gravarEstado($this, (string) json_encode(['enabled' => true, 'enabled_at' => 'x']));

    expect(gate($this)->disable())->toBeTrue()
        ->and(is_file((string) $this->storage . DIRECTORY_SEPARATOR . HypervGate::F_STATE))->toBeFalse()
        ->and(gate($this)->enabled())->toBeFalse();
});

it('desligar sem nada ligado não estoura, e diz que sim', function () {
    expect(gate($this)->disable())->toBeTrue();
});

// ------------------------------------------------------------------- caminho

it('o statePath fica em storage/, com o nome do arquivo próprio', function () {
    expect(gate($this)->statePath())
        ->toBe((string) $this->storage . DIRECTORY_SEPARATOR . HypervGate::F_STATE)
        ->and(HypervGate::F_STATE)->toBe('hyperv.json');
});
