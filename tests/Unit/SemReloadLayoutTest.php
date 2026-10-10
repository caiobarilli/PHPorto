<?php

declare(strict_types=1);

use App\Http\Csrf;
use App\Http\LayoutView;
use App\Http\Respond;

/** O layout com um miolo qualquer, e o script comum que ele carrega. */
function layoutHtml(string $miolo = '<div class="wrap">x</div>'): string
{
    return Respond::render('layout.php', new LayoutView('PHPorto', $miolo));
}

/** Só o conteúdo dos <script> do HTML dado. */
function scripts(string $html): string
{
    preg_match_all('#<script>(.*?)</script>#s', $html, $m);

    return implode("\n", $m[1]);
}

it('o miolo fica dentro do <main id="conteudo">, e o aviso do rodapé fica fora dele', function () {
    $html = layoutHtml('<div class="wrap">miolo</div>');

    expect($html)->toContain('<main id="conteudo"><div class="wrap">miolo</div></main>')
        ->and($html)->toMatch('#</main>\s*<div class="aviso-rodape" id="aviso-rodape" role="status" aria-live="polite" hidden>#');
});

it('o script comum só pega formulário marcado, e manda o cabeçalho do sem-reload', function () {
    $js = scripts(layoutHtml());

    expect($js)->toContain("hasAttribute('data-sem-reload')")
        ->and($js)->toContain("'X-PHPorto-Sem-Reload': '1'")
        ->and($js)->toContain("mode: 'same-origin'")
        ->and($js)->toContain("credentials: 'same-origin'");
});

it('o script comum troca o token pelo nome do campo do Csrf', function () {
    expect(scripts(layoutHtml()))->toContain('var CAMPO = ' . json_encode(Csrf::fieldName()) . ';');
});

it('o script comum nunca reenvia o POST nem guarda nada no navegador', function () {
    $js = scripts(layoutHtml());

    // Na falha, o caminho é um GET da tela (location.replace), e só um fetch existe.
    expect(substr_count($js, 'fetch('))->toBe(1)
        ->and($js)->toContain('location.replace(location.pathname + location.search)')
        ->and($js)->not->toContain('location.reload')
        ->and($js)->not->toContain('localStorage')
        ->and($js)->not->toContain('sessionStorage')
        ->and($js)->not->toContain('innerHTML')
        ->and($js)->not->toContain('.submit()');
});

it('Sec-Fetch-Site: mesma origem e navegação direta passam, outro site não', function (?string $valor, bool $passa) {
    expect(Csrf::sameSite($valor))->toBe($passa);
})->with([
    'sem o cabeçalho'  => [null, true],
    'same-origin'      => ['same-origin', true],
    'none'             => ['none', true],
    'maiúscula'        => ['Same-Origin', true],
    'same-site'        => ['same-site', false],
    'cross-site'       => ['cross-site', false],
    'vazio'            => ['', false],
]);

it('POST de outro site é recusado antes de olhar o token, e o token não queima', function () {
    $_SERVER['HTTP_SEC_FETCH_SITE'] = 'cross-site';
    $token = Csrf::token();
    $_POST[Csrf::fieldName()] = $token;

    expect(Csrf::consume())->toBeFalse()
        ->and(Csrf::token())->toBe($token);

    $_SERVER['HTTP_SEC_FETCH_SITE'] = 'same-origin';

    expect(Csrf::consume())->toBeTrue();
})->after(function () {
    unset($_SERVER['HTTP_SEC_FETCH_SITE'], $_POST[Csrf::fieldName()]);
});
