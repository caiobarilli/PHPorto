<?php

declare(strict_types=1);

use App\Win\WinConfig;

/** A tradução do projeto, lida como a tela lê. */
function traducao(): array
{
    $arquivo = dirname(__DIR__, 2) . '/src/Win/config/' . WinConfig::TWEAKS_TRANSLATION . '.json';
    $dados   = json_decode((string) file_get_contents($arquivo), true, flags: JSON_THROW_ON_ERROR);

    return is_array($dados) ? $dados : [];
}

it('a tradução cobre os 62 tweaks, sem chave a mais, com rótulo e descrição', function () {
    $tweaks = traducao()['tweaks'];

    expect(array_keys($tweaks))->toEqualCanonicalizing(WinConfig::tweakKeys())
        ->and(count($tweaks))->toBe(62);

    foreach ($tweaks as $chave => $texto) {
        expect($texto['content'] ?? '')->not->toBe('', "$chave sem rótulo")
            ->and($texto['description'] ?? '')->not->toBe('', "$chave sem descrição");
    }
});

it('a tradução cobre as categorias do tweaks.json, sem a mais', function () {
    $categorias = array_values(array_unique(array_map(static fn (array $t): string => $t['category'], WinConfig::tweaks())));

    expect(array_keys(traducao()['categorias']))->toEqualCanonicalizing($categorias);
});

it('a tela recebe o texto em português, e chave, cuidado e aviso do Explorer não mudam', function () {
    $originais  = WinConfig::tweaks();
    $traduzidos = WinConfig::translateTweaks($originais);

    foreach ($originais as $i => $original) {
        expect($traduzidos[$i]['key'])->toBe($original['key'])
            ->and($traduzidos[$i]['caution'])->toBe($original['caution'])
            ->and($traduzidos[$i]['explorer'])->toBe($original['explorer']);
    }

    $porChave = array_column($traduzidos, null, 'key');

    expect($porChave['WPFTweaksTelemetry']['content'])->toBe('Telemetria — desativar')
        ->and($porChave['WPFTweaksUTC']['category'])->toBe('Ajustes avançados — CUIDADO');
});

it('sem o arquivo de tradução, a tela cai para o texto do tweaks.json', function () {
    $pasta = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phporto_sem_traducao_' . bin2hex(random_bytes(4));
    mkdir($pasta);

    try {
        $originais = WinConfig::tweaks();

        expect(WinConfig::translateTweaks($originais, $pasta))->toBe($originais);
    } finally {
        rmdir($pasta);
    }
});

it('chave sem tradução, ou tradução vazia, fica com o texto original', function () {
    $pasta = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phporto_traducao_parcial_' . bin2hex(random_bytes(4));
    mkdir($pasta);
    file_put_contents($pasta . '/' . WinConfig::TWEAKS_TRANSLATION . '.json', json_encode([
        'categorias' => [],
        'tweaks'     => ['A' => ['content' => 'traduzido', 'description' => '']],
    ]));

    $itens = [
        ['key' => 'A', 'content' => 'orig A', 'description' => 'desc A', 'category' => 'Cat', 'caution' => false, 'explorer' => false],
        ['key' => 'NOVO', 'content' => 'orig novo', 'description' => 'desc novo', 'category' => 'Cat', 'caution' => false, 'explorer' => false],
    ];

    try {
        $saida = WinConfig::translateTweaks($itens, $pasta);

        expect($saida[0]['content'])->toBe('traduzido')
            ->and($saida[0]['description'])->toBe('desc A')
            ->and($saida[1])->toBe($itens[1]);
    } finally {
        unlink($pasta . '/' . WinConfig::TWEAKS_TRANSLATION . '.json');
        rmdir($pasta);
    }
});

it('o tweaks.json do upstream continua com o hash da tabela', function () {
    expect(hash_file('sha256', dirname(__DIR__, 2) . '/src/Win/config/tweaks.json'))
        ->toBe('e96d41745d31e323cd0d3d0eab188a5ab08cf00148e99674d3d261b10e2d1ae9');
});
