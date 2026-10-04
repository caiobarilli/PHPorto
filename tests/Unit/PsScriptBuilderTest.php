<?php

declare(strict_types=1);

use App\Win\PsScriptBuilder;

/**
 * O encoding dos .ps1, que é a regra INVERSA da do cmd.sh.
 *
 *   cmd.sh (bash)   LF, SEM BOM
 *   .ps1  (PS 5.1)  CRLF, COM BOM
 *
 * Estes testes existem para derrubar quem alinhar uma regra com a outra em
 * nome de consistência: o bash engasga com \r e com BOM, e o PowerShell 5.1
 * sem BOM lê o arquivo como ANSI e estraga a acentuação.
 */

beforeEach(function () {
    $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phporto_ps_' . bin2hex(random_bytes(6));
});

afterEach(function () {
    if (is_string($this->dir) && is_dir($this->dir)) {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }
});

it('põe BOM na frente', function () {
    expect(PsScriptBuilder::normalize('Get-Process'))->toStartWith(PsScriptBuilder::BOM);
});

it('converte LF em CRLF', function () {
    $saida = PsScriptBuilder::normalize("linha 1\nlinha 2\n");

    expect(substr_count($saida, "\r\n"))->toBe(2)
        ->and(substr_count($saida, "\n"))->toBe(2);
});

it('NÃO transforma CRLF que já existe em CR CR LF', function () {
    // O erro clássico de trocar "\n" por "\r\n" às cegas.
    $saida = PsScriptBuilder::normalize("linha 1\r\nlinha 2\r\n");

    expect($saida)->not->toContain("\r\r")
        ->and(substr_count($saida, "\r\n"))->toBe(2);
});

it('normaliza CR solto (Mac clássico) também', function () {
    $saida = PsScriptBuilder::normalize("linha 1\rlinha 2");

    expect(substr_count($saida, "\r\n"))->toBe(2)
        ->and($saida)->not->toContain("\r\r");
});

it('garante quebra de linha no fim', function () {
    expect(PsScriptBuilder::normalize('sem quebra'))->toEndWith("\r\n");
});

it('é idempotente: normalizar duas vezes não empilha BOM nem duplica quebras', function () {
    $uma  = PsScriptBuilder::normalize("Get-Process\nGet-Service\n");
    $duas = PsScriptBuilder::normalize($uma);

    expect($duas)->toBe($uma)
        ->and(substr_count($duas, PsScriptBuilder::BOM))->toBe(1);
});

it('normaliza string vazia sem inventar linha', function () {
    expect(PsScriptBuilder::normalize(''))->toBe(PsScriptBuilder::BOM);
});

it('stripBom tira o BOM e não mexe em quem não tem', function () {
    expect(PsScriptBuilder::stripBom(PsScriptBuilder::BOM . 'PID=1'))->toBe('PID=1')
        ->and(PsScriptBuilder::stripBom('PID=1'))->toBe('PID=1')
        ->and(PsScriptBuilder::stripBom(''))->toBe('');
});

it('stripBom só tira do COMEÇO', function () {
    $texto = 'antes' . PsScriptBuilder::BOM . 'depois';

    expect(PsScriptBuilder::stripBom($texto))->toBe($texto);
});

it('literal envolve em apóstrofo simples', function () {
    expect(PsScriptBuilder::literal('C:\\pasta\\arquivo.ps1'))->toBe("'C:\\pasta\\arquivo.ps1'");
});

it('literal DOBRA o apóstrofo, que é o único escape que existe ali', function () {
    expect(PsScriptBuilder::literal("O'Brien"))->toBe("'O''Brien'");
});

it('literal DOBRA cada aspa simples curva, que o PowerShell também fecha', function (string $aspa) {
    // U+2018 a U+201B fecham literal de apóstrofo no PowerShell como o '.
    // Sem dobrar, um valor com ’ fechava a string e o resto virava código.
    expect(PsScriptBuilder::literal('a' . $aspa . 'b'))->toBe("'a" . $aspa . $aspa . "b'");
})->with([
    'U+2018' => "\u{2018}",
    'U+2019' => "\u{2019}",
    'U+201A' => "\u{201A}",
    'U+201B' => "\u{201B}",
]);

it('literal dobra as cinco aspas juntas, sem sobra para fechar a string', function () {
    $veneno = "x'\u{2018}\u{2019}\u{201A}\u{201B}; Remove-Item C:\\ -Recurse";

    expect(PsScriptBuilder::literal($veneno))
        ->toBe("'x''\u{2018}\u{2018}\u{2019}\u{2019}\u{201A}\u{201A}\u{201B}\u{201B}; Remove-Item C:\\ -Recurse'");
});

it('literal deixa inerte o que interpolaria em aspas duplas', function (string $valor) {
    // Dentro de apóstrofo simples o PowerShell não interpola nada: nem
    // $variavel, nem $(...), nem crase. É isso que permite um valor validado
    // entrar num script gerado sem poder virar código.
    $saida = PsScriptBuilder::literal($valor);

    expect($saida)->toBe("'" . $valor . "'");
})->with([
    '$env:USERNAME',
    '$(Get-Process)',
    '`n',
    '"; Remove-Item C:\\ -Recurse; "',
]);

it('literal recusa byte nulo', function () {
    PsScriptBuilder::literal("valor\0malicioso");
})->throws(RuntimeException::class);

it('write() grava com BOM e CRLF e cria a pasta que falta', function () {
    $caminho = $this->dir . DIRECTORY_SEPARATOR . 'script.ps1';

    PsScriptBuilder::write($caminho, "Get-Process\nGet-Service");

    $bytes = (string) file_get_contents($caminho);

    expect(is_file($caminho))->toBeTrue()
        ->and(substr($bytes, 0, 3))->toBe(PsScriptBuilder::BOM)
        ->and(substr_count($bytes, "\r\n"))->toBe(2)
        ->and(substr_count($bytes, "\n") - substr_count($bytes, "\r\n"))->toBe(0);
});

it('write() sobrescreve sem empilhar BOM', function () {
    $caminho = $this->dir . DIRECTORY_SEPARATOR . 'script.ps1';

    PsScriptBuilder::write($caminho, 'primeiro');
    PsScriptBuilder::write($caminho, 'segundo');

    $bytes = (string) file_get_contents($caminho);

    expect(substr_count($bytes, PsScriptBuilder::BOM))->toBe(1)
        ->and($bytes)->toContain('segundo')
        ->and($bytes)->not->toContain('primeiro');
});
