<?php

declare(strict_types=1);

/*
 * O .env.example contra quem lê o ambiente: o Config, e os testes de
 * integração do MySQL para as MYSQL_TEST_*. Lido como texto.
 */

/**
 * As chaves que o arquivo declara, na ordem em que aparecem.
 *
 * @return list<string>
 */
function envExampleKeys(): array
{
    $texto = (string) file_get_contents(dirname(__DIR__, 2) . '/.env.example');
    preg_match_all('/^([A-Z][A-Z0-9_]*)=/m', $texto, $m);

    return $m[1];
}

/**
 * As chaves lidas do ambiente: self::env()/self::bool() no Config e
 * mysqlTestEnv() no MySQLProviderTest.
 *
 * @return list<string>
 */
function envKeysRead(): array
{
    $raiz = dirname(__DIR__, 2);
    $config = (string) file_get_contents($raiz . '/src/Config/Config.php');
    $mysql  = (string) file_get_contents($raiz . '/tests/Integration/MySQLProviderTest.php');

    preg_match_all("/self::(?:env|bool)\\('([A-Z0-9_]+)'/", $config, $c);
    preg_match_all("/mysqlTestEnv\\('([A-Z0-9_]+)'/", $mysql, $t);

    return array_values(array_unique([...$c[1], ...$t[1]]));
}

it('toda chave lida do ambiente está no .env.example', function () {
    expect(array_values(array_diff(envKeysRead(), envExampleKeys())))->toBe([]);
});

it('toda chave do .env.example é lida por alguém', function () {
    expect(array_values(array_diff(envExampleKeys(), envKeysRead())))->toBe([]);
});

it('o .env.example não repete chave', function () {
    $chaves = envExampleKeys();

    expect(array_values(array_unique($chaves)))->toBe($chaves);
});

it('a leitura acha as chaves dos dois lados, e não uma lista vazia contra outra', function () {
    expect(envKeysRead())->toContain('PHPORTO_AUTH_TOKEN', 'PHPORTO_API_ENABLED', 'MYSQL_TEST_USER')
        ->and(count(envExampleKeys()))->toBe(26);
});
