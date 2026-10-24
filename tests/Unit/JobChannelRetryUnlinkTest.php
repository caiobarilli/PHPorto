<?php

declare(strict_types=1);

use App\Win\Elevation;
use App\Win\JobChannel;
use App\Win\WinAction;

beforeEach(function () {
    $this->files = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phporto_retry_' . bin2hex(random_bytes(6));
    mkdir($this->files, 0o775, true);
    $this->protegida = $this->files . DIRECTORY_SEPARATOR . Elevation::DIR_PROTEGIDA;
    mkdir($this->protegida);
    $this->pausas = [];
    $this->chamadas = [];
});

afterEach(function () {
    foreach ([$this->protegida, $this->files] as $pasta) {
        foreach (glob($pasta . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($pasta);
    }
});

function canalComFalhas(object $ctx, int $falhasPorArquivo): JobChannel
{
    $restantes = [];

    return new JobChannel(
        (string) $ctx->files,
        function (string $caminho) use ($ctx, $falhasPorArquivo, &$restantes): bool {
            $ctx->chamadas[] = basename($caminho);
            $restantes[$caminho] ??= $falhasPorArquivo;

            if ($restantes[$caminho] > 0) {
                $restantes[$caminho]--;

                return false;
            }

            return unlink($caminho);
        },
        function (int $us) use ($ctx): void {
            $ctx->pausas[] = $us;
        },
    );
}

function parNoDisco(object $ctx, string $id): void
{
    $dir = (string) $ctx->protegida;
    file_put_contents($dir . DIRECTORY_SEPARATOR . 'win-out-' . $id . '.txt', '{"hyperv":true,"vms":[]}');
    file_put_contents(
        $dir . DIRECTORY_SEPARATOR . 'win-done-' . $id . '.json',
        (string) json_encode(['id' => $id, 'exit' => 0, 'ms' => 1, 'nota' => '', 'acao' => 'hyperv'])
    );
}

it('apaga depois de falhas passageiras, sem aviso', function () {
    $canal = canalComFalhas($this, 3);
    $canal->send(WinAction::Hyperv, [], 'n');
    parNoDisco($this, '2a0000000000');

    $r = $canal->collect(5);

    expect($r->output)->not->toContain('Não foi possível apagar')
        ->and($canal->cleanupFailures())->toBe([])
        ->and($canal->importedIds())->toBe([])
        ->and(is_file($this->protegida . '/win-done-2a0000000000.json'))->toBeFalse()
        ->and(count($this->chamadas))->toBe(8)
        ->and($this->pausas)->toBe(array_fill(0, 6, 100000));
});

it('desiste depois de 5 tentativas e avisa curto, com importadas gravadas', function () {
    $canal = canalComFalhas($this, 99);
    $canal->send(WinAction::Hyperv, [], 'n');
    parNoDisco($this, '2b0000000000');

    $r = $canal->collect(5);

    expect(count($this->chamadas))->toBe(10)
        ->and(count($this->pausas))->toBe(8)
        ->and($r->output)->toContain('[phporto] Não foi possível apagar win-done-2b0000000000.json, win-out-2b0000000000.txt da pasta protegida; o worker apaga na próxima execução.')
        ->and($canal->cleanupFailures())->toBe(['win-done-2b0000000000.json', 'win-out-2b0000000000.txt'])
        ->and($canal->importedIds())->toHaveKey('2b0000000000');
});

it('não tenta de novo quando o arquivo já sumiu', function () {
    $canal = new JobChannel(
        (string) $this->files,
        function (string $caminho): bool {
            $this->chamadas[] = basename($caminho);
            @unlink($caminho);

            return false;
        },
        function (int $us): void {
            $this->pausas[] = $us;
        },
    );
    $canal->send(WinAction::Hyperv, [], 'n');
    parNoDisco($this, '2c0000000000');

    $r = $canal->collect(5);

    expect(count($this->chamadas))->toBe(2)
        ->and($this->pausas)->toBe([])
        ->and($r->output)->not->toContain('Não foi possível apagar')
        ->and($canal->cleanupFailures())->toBe([]);
});
