<?php

declare(strict_types=1);

use App\Win\Elevation;
use App\Win\WinManifest;

/**
 * O manifesto que o PHP tira ao ligar o PowerShell elevado.
 *
 * Quem confere é o worker, em PowerShell, e isso o Pester cobre. Aqui fica o
 * lado que só o PHP sabe: que o mapa cobre tudo o que o lado elevado carrega,
 * e que o hash que vai para a linha de comando é o dos bytes gravados.
 */

beforeEach(function () {
    $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phporto_manifesto_' . bin2hex(random_bytes(6));
    $this->winDir = dirname(__DIR__, 2) . '/src/Win';
});

afterEach(function () {
    if (is_dir($this->dir)) {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }
});

it('cobre todo arquivo de lib, actions, config e audit, mais o bootstrap', function () {
    // Errar para menos falha fechado (o bootstrap recusa o que não está no
    // mapa), mas a ação ficaria inutilizável. Este teste acusa antes.
    $esperado = ['bootstrap.ps1'];

    foreach (['lib', 'actions', 'config', 'audit'] as $pasta) {
        foreach (new DirectoryIterator($this->winDir . '/' . $pasta) as $f) {
            if ($f->isFile() && in_array($f->getExtension(), ['ps1', 'json'], true)) {
                $esperado[] = $pasta . '/' . $f->getFilename();
            }
        }
    }
    sort($esperado);

    expect(array_keys(WinManifest::of($this->winDir)))->toBe($esperado);
});

it('cada valor é o SHA-256 do arquivo, com a chave em barra normal', function () {
    foreach (WinManifest::of($this->winDir) as $relativo => $hash) {
        expect($relativo)->not->toContain('\\')
            ->and($hash)->toBe(hash_file('sha256', $this->winDir . '/' . $relativo));
    }
});

it('write() grava o mapa e devolve o SHA-256 dos bytes gravados', function () {
    $destino = $this->dir . DIRECTORY_SEPARATOR . Elevation::F_MANIFESTO;

    // A pasta ainda não existe: write() a cria, como o PsScriptBuilder.
    $hash = WinManifest::write($this->winDir, $destino);

    expect($hash)->toBe(hash_file('sha256', $destino))
        ->and(json_decode((string) file_get_contents($destino), true))->toBe(WinManifest::of($this->winDir));
});

it('pasta sem nada vira objeto vazio, e não lista', function () {
    // O worker recusa manifesto sem bootstrap; um "[]" nem chegaria a ser
    // lido como mapa.
    $destino = $this->dir . DIRECTORY_SEPARATOR . Elevation::F_MANIFESTO;
    WinManifest::write($this->dir, $destino);

    expect(file_get_contents($destino))->toBe('{}');
});

it('worker e PHP usam os mesmos nomes de manifesto, pasta e parâmetro', function () {
    // Lido como texto, pelo mesmo motivo da paridade da allowlist.
    $worker = (string) file_get_contents($this->winDir . '/worker.ps1');
    $elevation = (string) file_get_contents($this->winDir . '/Elevation.php');

    expect($worker)->toContain("Join-Path \$Dir '" . Elevation::F_MANIFESTO . "'")
        ->and($worker)->toContain("Join-Path \$Dir '" . Elevation::DIR_PROTEGIDA . "'")
        ->and($worker)->toContain("[Parameter(Mandatory, ParameterSetName = 'Laco')] [string]\$ManifestoSha256")
        ->and($elevation)->toContain("'-ManifestoSha256',");
});
