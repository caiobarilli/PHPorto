<?php

declare(strict_types=1);

use App\Win\UacPolicy;
use App\Win\UacVerdict;

/**
 * A política do UAC, lida da saída do reg.exe.
 *
 * NADA AQUI LÊ O REGISTRO: a consulta é injetada, e cada teste dá a saída que
 * o reg.exe daria. O formato foi conferido no Windows 11 em português; o
 * cabeçalho com o nome da chave sai igual em qualquer idioma.
 */

/** A saída do reg.exe para a chave, com os valores que se quiser. */
function saidaReg(array $dwords): string
{
    $linhas = ['', 'HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows\CurrentVersion\Policies\System'];

    foreach ($dwords as $nome => $valor) {
        $linhas[] = sprintf('    %s    REG_DWORD    0x%x', $nome, $valor);
    }

    $linhas[] = '    legalnoticecaption    REG_SZ    ';
    $linhas[] = '';
    $linhas[] = 'HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows\CurrentVersion\Policies\System\Audit';

    return implode("\r\n", $linhas);
}

/** A política com a consulta fingida, contando quantas vezes ela rodou. */
function politica(?string $saida, ?int &$chamadas = null): UacPolicy
{
    $chamadas = 0;

    return new UacPolicy(function () use ($saida, &$chamadas): ?string {
        $chamadas++;

        return $saida;
    });
}

it('lê os quatro valores da saída do reg.exe', function () {
    $valores = UacPolicy::parse(saidaReg([
        'ConsentPromptBehaviorAdmin' => 2,
        'ConsentPromptBehaviorUser'  => 3,
        'EnableLUA'                  => 1,
        'PromptOnSecureDesktop'      => 0,
    ]));

    expect($valores)->toBe([
        'EnableLUA'                  => 1,
        'ConsentPromptBehaviorAdmin' => 2,
        'PromptOnSecureDesktop'      => 0,
        'ConsentPromptBehaviorUser'  => 3,
    ]);
});

it('valor ausente vale o padrão do Windows', function () {
    expect(UacPolicy::parse(saidaReg(['EnableLUA' => 1])))->toBe(UacPolicy::DEFAULTS);
});

it('saída sem o cabeçalho da chave é lixo, e não padrão', function (string $saida) {
    expect(UacPolicy::parse($saida))->toBeNull();
})->with([
    'vazia'      => [''],
    'erro'       => ['ERRO: O sistema não conseguiu localizar a chave ou o valor do Registro especificado.'],
    'outra chave' => ["HKEY_LOCAL_MACHINE\\SOFTWARE\\Outra\r\n    EnableLUA    REG_DWORD    0x0"],
]);

it('o veredito segue EnableLUA e ConsentPromptBehaviorAdmin', function (?array $valores, UacVerdict $esperado) {
    expect(UacPolicy::judge($valores))->toBe($esperado);
})->with([
    'padrão, CPBA 5'        => [['EnableLUA' => 1, 'ConsentPromptBehaviorAdmin' => 5], UacVerdict::Ok],
    'sempre notificar, 2'   => [['EnableLUA' => 1, 'ConsentPromptBehaviorAdmin' => 2], UacVerdict::Ok],
    'pede senha, 1'         => [['EnableLUA' => 1, 'ConsentPromptBehaviorAdmin' => 1], UacVerdict::Ok],
    'silencioso, 0'         => [['EnableLUA' => 1, 'ConsentPromptBehaviorAdmin' => 0], UacVerdict::Silencioso],
    'desligado'             => [['EnableLUA' => 0, 'ConsentPromptBehaviorAdmin' => 5], UacVerdict::Desligado],
    'desligado vence o 0'   => [['EnableLUA' => 0, 'ConsentPromptBehaviorAdmin' => 0], UacVerdict::Desligado],
    'CPBA fora do conjunto' => [['EnableLUA' => 1, 'ConsentPromptBehaviorAdmin' => 9], UacVerdict::Desconhecido],
    'nada lido'             => [null, UacVerdict::Desconhecido],
]);

it('com prompt garantido, nada bloqueia', function () {
    $p = politica(saidaReg(['EnableLUA' => 1, 'ConsentPromptBehaviorAdmin' => 5]));

    expect($p->verdict())->toBe(UacVerdict::Ok)
        ->and($p->blockingReason())->toBeNull()
        ->and($p->summary())->toContain('padrão do Windows');
});

it('UAC SILENCIOSO RECUSA, com a correção colável na frase', function () {
    $p = politica(saidaReg(['EnableLUA' => 1, 'ConsentPromptBehaviorAdmin' => 0]));

    expect($p->blockingReason())->toContain('ConsentPromptBehaviorAdmin = 0')
        ->and($p->blockingReason())->toContain('Nada foi executado')
        ->and($p->blockingReason())->toContain('secpol.msc')
        ->and($p->blockingReason())->toContain(UacPolicy::FIX_CPBA)
        ->and(UacPolicy::FIX_CPBA)->toContain('/d 5 /f');
});

it('UAC desligado recusa e avisa que ligar exige reiniciar', function () {
    $p = politica(saidaReg(['EnableLUA' => 0]));

    expect($p->blockingReason())->toContain('já é Administrador')
        ->and($p->blockingReason())->toContain('reinicie')
        ->and($p->summary())->toContain('bloqueadas');
});

it('POLÍTICA ILEGÍVEL RECUSA: o lado fechado é o padrão', function () {
    $p = politica(null);

    expect($p->verdict())->toBe(UacVerdict::Desconhecido)
        ->and($p->blockingReason())->toContain('Não foi possível ler a política do UAC');
});

it('a consulta roda uma vez por instância, e não a cada pergunta', function () {
    $p = politica(saidaReg(['EnableLUA' => 1]), $chamadas);

    $p->verdict();
    $p->blockingReason();
    $p->summary();

    expect($chamadas)->toBe(1);
});

it('fora do Windows, a consulta de verdade não lê nada', function () {
    if (PHP_OS_FAMILY === 'Windows') {
        $this->markTestSkipped('No Windows ela lê o registro de verdade.');
    }

    expect((new UacPolicy())->verdict())->toBe(UacVerdict::Desconhecido);
});
