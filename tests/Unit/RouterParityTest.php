<?php

declare(strict_types=1);

function paridadeRotasIndex(): array
{
    $codigo = (string) file_get_contents(dirname(__DIR__, 2) . '/public/index.php');
    $inicio = strrpos($codigo, 'match ($path) {');
    $fim    = $inicio === false ? false : strpos($codigo, '};', $inicio);

    if ($inicio === false || $fim === false) {
        return [];
    }

    preg_match_all("/^\\s*'([^']+)'\\s*=>/m", substr($codigo, $inicio, $fim - $inicio), $m);
    $rotas = $m[1];
    sort($rotas);

    return $rotas;
}

function paridadeRotasRouter(): array
{
    $codigo = (string) file_get_contents(dirname(__DIR__, 2) . '/public/router.php');

    if (preg_match('/const PHPORTO_ROUTES = \[([^\]]*)\];/', $codigo, $m) !== 1) {
        return [];
    }

    preg_match_all("/'([^']+)'/", $m[1], $r);
    $rotas = $r[1];
    sort($rotas);

    return $rotas;
}

it('acha as rotas nos dois arquivos', function () {
    expect(paridadeRotasIndex())->toContain('/', '/ollama')
        ->and(paridadeRotasRouter())->toContain('/', '/ollama');
});

it('toda página do index.php passa pelo router do php -S e vice-versa', function () {
    expect(array_values(array_diff(paridadeRotasIndex(), paridadeRotasRouter())))->toBe([])
        ->and(array_values(array_diff(paridadeRotasRouter(), paridadeRotasIndex())))->toBe([]);
});
