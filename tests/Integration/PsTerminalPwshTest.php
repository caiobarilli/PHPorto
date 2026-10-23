<?php

declare(strict_types=1);

use App\Win\PsTerminalProcess;

function terminalPowerShell(): ?string
{
    if (DIRECTORY_SEPARATOR === '\\') {
        return 'powershell.exe';
    }

    $env = getenv('PHPORTO_TEST_PWSH');
    if (is_string($env) && $env !== '' && is_executable($env)) {
        return $env;
    }

    foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $pasta) {
        if ($pasta !== '' && is_executable($pasta . '/pwsh')) {
            return $pasta . '/pwsh';
        }
    }

    return null;
}

beforeEach(function () {
    $this->exe = terminalPowerShell();

    if ($this->exe === null) {
        $this->markTestSkipped('sem powershell.exe nem pwsh nesta máquina.');
    }

    $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phporto_pwsh_' . bin2hex(random_bytes(5));
    mkdir($this->dir, 0o775, true);
    $this->term = new PsTerminalProcess($this->dir, executable: $this->exe, workDir: $this->dir);
});

afterEach(function () {
    if (isset($this->dir) && is_string($this->dir) && is_dir($this->dir)) {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }
});

it('o PowerShell de verdade recebe o comando intacto, com acento e aspas', function () {
    $r = $this->term->run("Write-Output 'ação \"ôlá\" \$naoExpande'");

    expect($r->exitCode)->toBe(0)
        ->and($r->output)->toBe("ação \"ôlá\" \$naoExpande\n");
});

it('erro de comando sai com 1 e com a mensagem na saída', function () {
    $r = $this->term->run('Get-Item -LiteralPath (Join-Path ([System.IO.Path]::GetTempPath()) phporto-nao-existe-x9)');

    expect($r->exitCode)->toBe(1)
        ->and($r->output)->toContain('phporto-nao-existe-x9');
});

it('o exit do comando vira o código de saída', function () {
    expect($this->term->run('exit 3')->exitCode)->toBe(3);
});

it('erro de sintaxe sai com 1, sem derrubar o invólucro', function () {
    $r = $this->term->run('Write-Output (');

    expect($r->exitCode)->toBe(1)
        ->and(trim($r->output))->not->toBe('');
});

it('Write-Host e avisos também chegam na saída', function () {
    $r = $this->term->run("Write-Host 'pelo host'; Write-Warning 'cuidado'");

    expect($r->exitCode)->toBe(0)
        ->and($r->output)->toContain('pelo host')
        ->and($r->output)->toContain('cuidado');
});

it('erro que não termina deixa as linhas seguintes rodarem, como no console', function () {
    $r = $this->term->run("Get-Item -LiteralPath 'phporto-nao-existe-x9'; Write-Output 'depois'");

    expect($r->exitCode)->toBe(1)
        ->and($r->output)->toContain('phporto-nao-existe-x9')
        ->and($r->output)->toContain("depois\n");
});

it('throw sai com 1 e a mensagem chega, mesmo pelo stderr', function () {
    $r = $this->term->run("throw 'bum-x9'");

    expect($r->exitCode)->toBe(1)
        ->and($r->output)->toContain('bum-x9')
        ->and($r->output)->not->toContain('CLIXML');
});
