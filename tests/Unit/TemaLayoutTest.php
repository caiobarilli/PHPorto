<?php

declare(strict_types=1);

use App\Http\LayoutView;
use App\Http\Respond;

function temaLayout(): string
{
    return Respond::render('layout.php', new LayoutView('PHPorto', '<div class="wrap">x</div>'));
}

function temaCss(): string
{
    preg_match('#<style>(.*?)</style>#s', temaLayout(), $m);

    return $m[1] ?? '';
}

/** @return array<string, string> nome => valor das variáveis declaradas no trecho */
function temaTokens(string $bloco): array
{
    preg_match_all('~(--[\w-]+):\s*([^;]+);~', $bloco, $m);

    return array_combine($m[1], array_map('trim', $m[2]));
}

/** O corpo de um bloco pelo seletor exato, até a primeira chave que fecha. */
function temaBloco(string $css, string $seletor): string
{
    $i = strpos($css, $seletor . ' {');
    if ($i === false) {
        return '';
    }
    $ini = $i + strlen($seletor) + 2;

    return substr($css, $ini, (int) strpos($css, '}', $ini) - $ini);
}

it('o claro guarda os valores de antes, agora com nome', function () {
    $raiz = temaTokens(temaBloco(temaCss(), ':root'));

    expect($raiz)->toMatchArray([
        '--ink' => '#1b1f24', '--mut' => '#6b7280', '--line' => '#d6d9de', '--bg' => '#ffffff',
        '--panel' => '#fafbfc', '--btn' => '#262626', '--amber' => '#b45309',
        '--btn-fg' => '#ffffff', '--btn-hover' => '#3a3a3a', '--forte' => '#262626',
        '--ok' => '#15803d', '--bad' => '#b91c1c', '--bad-bg' => '#fef2f2', '--nota' => '#92400e',
        '--code-bg' => '#f1f3f5', '--tag-bg' => '#ececf0', '--tag-fg' => '#3f3f46',
        '--cuidado-borda' => '#d97706', '--cuidado-bg' => '#fffbeb',
        '--trilho' => '#d6d9de', '--trilho-on' => '#16a34a', '--bolinha' => '#ffffff',
        '--claro-bg' => '#ffffff', '--claro-fg' => '#1b1f24', '--claro-hover' => '#fafbfc',
        '--term-borda' => 'none',
        '--sombra-bolinha' => 'rgba(0,0,0,.25)', '--sombra-modal' => 'rgba(0,0,0,.18)',
        '--sombra-aviso' => 'rgba(0,0,0,.14)', '--fundo-modal' => 'rgba(15,17,21,.45)',
    ]);
});

it('o escuro vale pelo atributo e pelo sistema, com os dois blocos iguais', function () {
    $css = temaCss();
    $atributo = temaBloco($css, 'html[data-tema="escuro"]');

    expect($css)->toMatch('~@media \(prefers-color-scheme: dark\) \{\s*html:not\(\[data-tema\]\) \{~');
    $sistema = temaBloco($css, 'html:not([data-tema])');

    expect(temaTokens($atributo))->not->toBeEmpty()
        ->and(temaTokens($sistema))->toBe(temaTokens($atributo))
        ->and($atributo)->toContain('color-scheme: dark;')
        ->and($sistema)->toContain('color-scheme: dark;')
        ->and(temaTokens($atributo)['--bg'] ?? '')->toBe('#16181d')
        ->and(temaTokens($atributo)['--term-borda'] ?? '')->toBe('1px solid var(--line)');
});

it('todo token do site tem o par escuro', function () {
    $css = temaCss();
    $site = array_filter(
        array_keys(temaTokens(temaBloco($css, ':root'))),
        fn (string $nome) => !preg_match('~^--(oh|ohl)-|^--(mono|sans|mono-console)$~', $nome)
    );

    expect(array_keys(temaTokens(temaBloco($css, 'html[data-tema="escuro"]'))))->toEqualCanonicalizing(array_values($site));
});

it('fora dos blocos de tokens, cor só aparece na paleta do console', function () {
    $css = temaCss();
    $css = str_replace(
        [temaBloco($css, ':root'), temaBloco($css, 'html[data-tema="escuro"]'), temaBloco($css, 'html:not([data-tema])')],
        '',
        $css
    );
    $css = preg_replace('~\n  \.term \{[^}]*\}~', '', $css);

    expect($css)->not->toMatch('~#[0-9a-fA-F]{3,8}\b|rgba?\(~');
});

it('os controles nativos acompanham o modo: meta e color-scheme', function () {
    $html = temaLayout();
    $css = temaCss();

    expect($html)->toContain('<meta name="color-scheme" content="light dark">')
        ->and(temaBloco($css, ':root'))->toContain('color-scheme: light dark;')
        ->and($css)->toContain('html[data-tema="claro"] { color-scheme: light; }');
});

it('o script do <head> só lê o modo, antes do <style>, e descarta a chave velha do console', function () {
    $html = temaLayout();
    $head = substr($html, 0, (int) strpos($html, '</head>'));

    expect(preg_match_all('#^<script\b#m', $head))->toBe(1);
    preg_match('#<script id="tema">(.*?)</script>#s', $head, $m);
    $js = $m[1] ?? '';

    expect(strpos($head, '<script id="tema">'))->toBeLessThan((int) strpos($head, '<style>'))
        ->and($js)->toContain("localStorage.getItem('phporto.tema')")
        ->and($js)->toContain("localStorage.removeItem('phporto.wsl.console.tema')")
        ->and($js)->toContain("document.documentElement.setAttribute('data-tema', t)")
        ->and($js)->toContain("t === 'claro' || t === 'escuro'")
        ->and($js)->toContain('try {')
        ->and($js)->not->toContain('setItem')
        ->and($js)->not->toContain('sessionStorage')
        ->and($js)->not->toContain('fetch(')
        ->and($js)->not->toContain('innerHTML');
});

it('só a /config grava o tema; as outras telas e o layout não gravam nada no navegador', function () {
    foreach (glob(dirname(__DIR__, 2) . '/src/Views/*.php') ?: [] as $arquivo) {
        $fonte = (string) file_get_contents($arquivo);
        $nome = basename($arquivo);

        expect($fonte)->not->toContain('sessionStorage');
        if ($nome === 'config.php') {
            expect(substr_count($fonte, 'localStorage.setItem('))->toBe(1)
                ->and(substr_count($fonte, 'localStorage.removeItem('))->toBe(1);
        } elseif ($nome === 'layout.php') {
            expect($fonte)->not->toContain('setItem')
                ->and(substr_count($fonte, 'localStorage.removeItem('))->toBe(1)
                ->and($fonte)->toContain("localStorage.removeItem('phporto.wsl.console.tema')");
        } else {
            expect($fonte)->not->toContain('localStorage');
        }
    }
});

it('o botão marcado da Aparência é o primário, nos dois modos', function () {
    expect(temaCss())->toContain('.btn-ghost[aria-pressed="true"] { background: var(--btn); color: var(--btn-fg); border-color: var(--btn); }');
});
