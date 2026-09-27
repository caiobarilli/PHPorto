<?php

declare(strict_types=1);

/**
 * Config do PHP-CS-Fixer.
 *
 * Base PSR-12. Aplicado a todo o código — produção E testes —
 * já que o fixer mexe apenas em estilo (espaçamento, imports, chaves), nunca
 * em lógica; manter os testes no mesmo padrão evita diffs inconsistentes.
 */

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/public', __DIR__ . '/tests'])
    ->name('*.php')
    ->append([__DIR__ . '/token.php']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setRules([
        '@PSR12' => true,
    ])
    ->setFinder($finder)
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache');
