<?php

declare(strict_types=1);

/**
 * Config do PHP-CS-Fixer.
 *
 * Base PSR-12. Aplicado a todo o código do backend — produção E testes —
 * já que o fixer mexe apenas em estilo (espaçamento, imports, chaves), nunca
 * em lógica; manter os testes no mesmo padrão evita diffs inconsistentes.
 * Exclui apenas dependências e artefatos gerados.
 */

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__)
    ->exclude(['vendor', '.phpstan-cache'])
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setRules([
        '@PSR12' => true,
    ])
    ->setFinder($finder)
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache');
