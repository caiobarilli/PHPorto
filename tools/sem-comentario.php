<?php

declare(strict_types=1);

const SEM_COMENTARIO_TAGS_PARAM = ['param', 'phpstan-param', 'psalm-param', 'var', 'phpstan-var', 'psalm-var', 'property', 'property-read', 'property-write'];
const SEM_COMENTARIO_TAGS_TIPO  = ['return', 'phpstan-return', 'psalm-return', 'extends', 'implements', 'use', 'template-extends', 'template-implements', 'template-use'];

/**
 * @return list<string>
 */
function semComentarioArquivos(string $raiz): array
{
    $arquivos = [];
    foreach (['src', 'public', 'tests', 'tools'] as $dir) {
        if (!is_dir("$raiz/$dir")) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$raiz/$dir", FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $caminho = str_replace('\\', '/', (string) $f);
            if (str_ends_with($caminho, '.php') && !str_contains($caminho, '/tests/Pester/')) {
                $arquivos[] = $caminho;
            }
        }
    }
    foreach (glob("$raiz/*.php") ?: [] as $f) {
        $arquivos[] = str_replace('\\', '/', $f);
    }
    foreach (['.php-cs-fixer.php', '.php-cs-fixer.dist.php'] as $f) {
        if (is_file("$raiz/$f")) {
            $arquivos[] = "$raiz/$f";
        }
    }
    sort($arquivos);

    return array_values(array_unique($arquivos));
}

function semComentarioFimDoTipo(string $s, int $i): int
{
    $nivel = 0;
    $n     = strlen($s);
    $inicio = $i;
    while ($i < $n) {
        $c = $s[$i];
        if ($c === '"' || $c === "'") {
            $fim = strpos($s, $c, $i + 1);
            if ($fim === false) {
                return -1;
            }
            $i = $fim + 1;
            continue;
        }
        if (str_contains('<{([', $c)) {
            $nivel++;
        } elseif (str_contains('>})]', $c) && !($c === '>' && $i > 0 && $s[$i - 1] === '-')) {
            $nivel--;
            if ($nivel < 0) {
                return -1;
            }
        } elseif (ctype_space($c) && $nivel === 0) {
            $antes = rtrim(substr($s, $inicio, $i - $inicio));
            $resto = ltrim(substr($s, $i));
            if ($antes === '' || preg_match('/[:|&,]$/', $antes) === 1 || preg_match('/^[:|&]/', $resto) === 1) {
                $i++;
                continue;
            }

            return $i;
        }
        $i++;
    }

    return $nivel === 0 ? $n : -1;
}

function semComentarioTagValida(string $tag, string $resto): bool
{
    $resto = trim($resto);
    if (in_array($tag, ['throws', 'phpstan-throws'], true)) {
        return preg_match('/^\\\\?[A-Za-z_][\w\\\\]*(\s*\|\s*\\\\?[A-Za-z_][\w\\\\]*)*$/', $resto) === 1;
    }
    if (in_array($tag, ['template', 'template-covariant', 'template-contravariant', 'phpstan-template', 'psalm-template'], true)) {
        if (preg_match('/^[A-Za-z_]\w*$/', $resto) === 1) {
            return true;
        }
        if (preg_match('/^[A-Za-z_]\w*\s+(of|as)\s+(.+)$/s', $resto, $m) !== 1) {
            return false;
        }

        return semComentarioFimDoTipo($m[2], 0) === strlen($m[2]);
    }
    if (in_array($tag, ['phpstan-type', 'psalm-type'], true)) {
        if (preg_match('/^[A-Za-z_]\w*\s*=?\s*(.+)$/s', $resto, $m) !== 1) {
            return false;
        }

        return semComentarioFimDoTipo($m[1], 0) === strlen($m[1]);
    }
    if (in_array($tag, ['phpstan-import-type', 'psalm-import-type'], true)) {
        return preg_match('/^[A-Za-z_]\w*\s+from\s+\\\\?[A-Za-z_][\w\\\\]*(\s+as\s+[A-Za-z_]\w*)?$/', $resto) === 1;
    }
    $param = in_array($tag, SEM_COMENTARIO_TAGS_PARAM, true);
    if (!$param && !in_array($tag, SEM_COMENTARIO_TAGS_TIPO, true)) {
        return false;
    }
    $fim = semComentarioFimDoTipo($resto, 0);
    if ($fim <= 0) {
        return false;
    }
    $depois = trim(substr($resto, $fim));
    if ($depois === '') {
        return true;
    }

    return $param && preg_match('/^&?(\.\.\.)?\$[A-Za-z_]\w*$/', $depois) === 1;
}

function semComentarioDocbloco(string $doc): bool
{
    $corpo = (string) preg_replace('~^/\*\*|\*/$~', '', trim($doc));
    $linhas = [];
    foreach (preg_split('/\R/', $corpo) ?: [] as $l) {
        $l = trim((string) preg_replace('/^\s*\*(?!\/)/', '', $l));
        if ($l !== '') {
            $linhas[] = $l;
        }
    }
    if ($linhas === []) {
        return false;
    }
    $tags = [];
    foreach ($linhas as $l) {
        if (preg_match('/^@([a-z][a-z-]*)(.*)$/s', $l, $m) === 1) {
            $tags[] = [$m[1], $m[2]];
        } elseif ($tags === []) {
            return false;
        } else {
            $tags[count($tags) - 1][1] .= ' ' . $l;
        }
    }
    foreach ($tags as [$tag, $resto]) {
        if (!semComentarioTagValida($tag, $resto)) {
            return false;
        }
    }

    return true;
}

/**
 * @return list<array{int, string}>
 */
function semComentarioInline(string $fonte): array
{
    $mascara = '';
    foreach (token_get_all($fonte) as $t) {
        $texto = is_array($t) ? $t[1] : $t;
        $mascara .= is_array($t) && $t[0] === T_INLINE_HTML ? $texto : (string) preg_replace('/[^\n]/', ' ', $texto);
    }
    $achados = [];
    $linha = static fn (int $pos): int => substr_count($mascara, "\n", 0, $pos) + 1;
    $n = strlen($mascara);
    $i = 0;
    while ($i < $n) {
        if (preg_match('~\G<(script|style)\b[^>]*>~i', $mascara, $m, 0, $i) === 1) {
            $ini = $i + strlen($m[0]);
            $fim = stripos($mascara, '</' . $m[1], $ini);
            $fim = $fim === false ? $n : $fim;
            $tipo = strtolower($m[1]) === 'script' ? 'JS' : 'CSS';
            foreach (semComentarioCodigo(substr($mascara, $ini, $fim - $ini), $tipo === 'JS') as $pos) {
                $achados[] = [$linha($ini + $pos), "comentário $tipo"];
            }
            $i = $fim;
            continue;
        }
        if (substr_compare($mascara, '<!--', $i, 4) === 0) {
            $achados[] = [$linha($i), 'comentário HTML <!-- -->'];
            $fim = strpos($mascara, '-->', $i + 4);
            $i = $fim === false ? $n : $fim + 3;
            continue;
        }
        $i++;
    }

    return $achados;
}

/**
 * @return list<int>
 */
function semComentarioCodigo(string $s, bool $js): array
{
    $achados = [];
    $n = strlen($s);
    $i = 0;
    while ($i < $n) {
        $c = $s[$i];
        if ($c === '"' || $c === "'" || ($js && $c === '`')) {
            $i++;
            while ($i < $n && $s[$i] !== $c) {
                $i += $s[$i] === '\\' ? 2 : 1;
            }
            $i++;
            continue;
        }
        if ($c === '/' && ($s[$i + 1] ?? '') === '*') {
            $achados[] = $i;
            $fim = strpos($s, '*/', $i + 2);
            $i = $fim === false ? $n : $fim + 2;
            continue;
        }
        if ($js && $c === '/' && ($s[$i + 1] ?? '') === '/') {
            $achados[] = $i;
            $fim = strpos($s, "\n", $i);
            $i = $fim === false ? $n : $fim;
            continue;
        }
        if ($js && $c === '/') {
            $antes = rtrim(substr($s, 0, $i));
            $regex = $antes === '' || str_contains('(,=:[!&|?{};+-*%<>~^', substr($antes, -1))
                || preg_match('/\b(return|typeof|case|do|else|in|of|new|delete|void|throw)$/', $antes) === 1;
            if ($regex) {
                $i++;
                $classe = false;
                while ($i < $n && ($s[$i] !== '/' || $classe) && $s[$i] !== "\n") {
                    if ($s[$i] === '\\') {
                        $i++;
                    } elseif ($s[$i] === '[') {
                        $classe = true;
                    } elseif ($s[$i] === ']') {
                        $classe = false;
                    }
                    $i++;
                }
                $i++;
                continue;
            }
        }
        $i++;
    }

    return $achados;
}

/**
 * @return list<string>
 */
function semComentarioVarrer(string $raiz): array
{
    $raiz = rtrim(str_replace('\\', '/', $raiz), '/');
    $erros = [];
    foreach (semComentarioArquivos($raiz) as $arquivo) {
        $fonte = (string) file_get_contents($arquivo);
        $rel = substr($arquivo, strlen($raiz) + 1);
        foreach (token_get_all($fonte) as $t) {
            if (!is_array($t)) {
                continue;
            }
            if ($t[0] === T_COMMENT) {
                $erros[] = "$rel:$t[2]: comentário PHP";
            } elseif ($t[0] === T_DOC_COMMENT && !semComentarioDocbloco($t[1])) {
                $erros[] = "$rel:$t[2]: docblock com texto além de tags de tipo";
            }
        }
        if (str_starts_with($rel, 'src/Views/')) {
            foreach (semComentarioInline($fonte) as [$l, $o]) {
                $erros[] = "$rel:$l: $o";
            }
        }
    }

    return $erros;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $erros = semComentarioVarrer($argv[1] ?? dirname(__DIR__));
    if ($erros === []) {
        echo "sem-comentario: nenhum comentário explicativo.\n";
        exit(0);
    }
    fwrite(STDERR, implode("\n", $erros) . "\n\n");
    fwrite(STDERR, count($erros) . " comentário(s) explicativo(s). O código não leva comentário: o porquê vai para .ai/ pelo Orchestrator, e o docblock fica só com a tag de tipo (@param TIPO \$nome, @return TIPO, @var TIPO, @throws Classe), sem texto depois.\n");
    exit(1);
}
