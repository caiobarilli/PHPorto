<?php

declare(strict_types=1);

/**
 * Roda o PHP-CS-Fixer sobre uma cópia do código em LF.
 *
 * POR QUE ESTE ARQUIVO EXISTE:
 *
 * O repositório se apoia no `core.autocrlf=true` do Windows, então a árvore de
 * trabalho tem `.php` em CRLF. O PSR-12 exige LF. Rodar `composer cs-check`
 * direto reporta 37 dos 53 arquivos como "podem ser corrigidos", e o diff de
 * cada um é o arquivo inteiro — o que esconde qualquer problema de estilo de
 * verdade no meio do ruído.
 *
 * MEDIDO, e foi surpresa: desligar a regra `line_ending` NÃO resolve. Com ela
 * fora, os 37 continuam, agora com um diff de cinco linhas cada — as regras do
 * PSR-12 que mexem no bloco de abertura (`<?php`, `declare`, `namespace`)
 * reescrevem aquele trecho em LF e deixam o arquivo misto. Não há uma chave que
 * faça o fixer ignorar fim de linha.
 *
 * Daí a cópia: converte para LF num diretório temporário, roda o fixer lá, e
 * devolve o código de saída. O que sobra no relatório é estilo de verdade.
 *
 * NÃO CORRIGE NADA NO LUGAR, de propósito. O fixer roda em `--dry-run` sobre a
 * cópia, que é descartada — escrever de volta traria o LF junto e brigaria com
 * o autocrlf a cada checkout. Para corrigir, `composer cs-fix` na árvore real;
 * o que ele mexe é estilo, e o fim de linha volta ao normal no próximo commit.
 */

/**
 * Apaga a cópia. Chamada em todo caminho de saída, inclusive nos de erro:
 * deixar a cópia para trás encheria o temp de árvores do projeto.
 */
function limpar(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $itens = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($itens as $item) {
        if (!$item instanceof SplFileInfo) {
            continue;
        }

        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }

    @rmdir($dir);
}

$raiz = dirname(__DIR__);
$alvo = ['src', 'public', 'tests'];

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phporto-cs-' . bin2hex(random_bytes(6));

if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "Não foi possível criar o diretório temporário: {$tmp}\n");

    exit(1);
}

$copiados = 0;

foreach ($alvo as $dir) {
    $origem = $raiz . DIRECTORY_SEPARATOR . $dir;

    if (!is_dir($origem)) {
        continue;
    }

    $itens = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($origem, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($itens as $item) {
        if (!$item instanceof SplFileInfo || $item->getExtension() !== 'php') {
            continue;
        }

        $relativo = substr($item->getPathname(), strlen($raiz) + 1);
        $destino  = $tmp . DIRECTORY_SEPARATOR . $relativo;
        $pastaDst = dirname($destino);

        if (!is_dir($pastaDst) && !mkdir($pastaDst, 0775, true) && !is_dir($pastaDst)) {
            fwrite(STDERR, "Não foi possível criar {$pastaDst}\n");
            limpar($tmp);

            exit(1);
        }

        $conteudo = file_get_contents($item->getPathname());

        if ($conteudo === false) {
            fwrite(STDERR, "Não foi possível ler {$relativo}\n");
            limpar($tmp);

            exit(1);
        }

        file_put_contents($destino, str_replace("\r\n", "\n", $conteudo));
        $copiados++;
    }
}

// A config resolve os caminhos por __DIR__, então copiá-la para a raiz da
// cópia é o que faz o finder apontar para lá, sem um segundo arquivo de
// config para manter em dia.
$config = file_get_contents($raiz . DIRECTORY_SEPARATOR . '.php-cs-fixer.php');

if ($config === false) {
    fwrite(STDERR, "Não foi possível ler .php-cs-fixer.php\n");
    limpar($tmp);

    exit(1);
}

file_put_contents($tmp . DIRECTORY_SEPARATOR . '.php-cs-fixer.php', str_replace("\r\n", "\n", $config));

$fixer = $raiz . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'php-cs-fixer';

echo "cs-check em cópia LF: {$copiados} arquivo(s) em {$tmp}\n";

// --using-cache=no: o cache é indexado por caminho, e o caminho muda a cada
// execução — um cache aqui nunca acertaria, e ainda deixaria lixo no temp.
$comando = sprintf(
    '%s %s fix --dry-run --diff --config=%s --using-cache=no',
    escapeshellarg(PHP_BINARY),
    escapeshellarg($fixer),
    escapeshellarg($tmp . DIRECTORY_SEPARATOR . '.php-cs-fixer.php')
);

$codigo = 0;
passthru($comando, $codigo);

limpar($tmp);

exit($codigo);
