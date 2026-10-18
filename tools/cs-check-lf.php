<?php

declare(strict_types=1);

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
$alvo = ['src', 'public', 'tests', 'token.php'];

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phporto-cs-' . bin2hex(random_bytes(6));

if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "Não foi possível criar o diretório temporário: {$tmp}\n");

    exit(1);
}

$copiados = 0;

foreach ($alvo as $dir) {
    $origem = $raiz . DIRECTORY_SEPARATOR . $dir;

    if (is_file($origem)) {
        $itens = [new SplFileInfo($origem)];
    } elseif (is_dir($origem)) {
        $itens = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($origem, FilesystemIterator::SKIP_DOTS)
        );
    } else {
        continue;
    }

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

$config = file_get_contents($raiz . DIRECTORY_SEPARATOR . '.php-cs-fixer.php');

if ($config === false) {
    fwrite(STDERR, "Não foi possível ler .php-cs-fixer.php\n");
    limpar($tmp);

    exit(1);
}

file_put_contents($tmp . DIRECTORY_SEPARATOR . '.php-cs-fixer.php', str_replace("\r\n", "\n", $config));

$fixer = $raiz . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'php-cs-fixer';

echo "cs-check em cópia LF: {$copiados} arquivo(s) em {$tmp}\n";

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
