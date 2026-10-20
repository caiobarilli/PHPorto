<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/tools/sem-comentario.php';

it('o repositório não tem comentário explicativo', function () {
    expect(semComentarioVarrer(dirname(__DIR__, 2)))->toBe([]);
});

it('docblock só de tipo passa', function (string $doc) {
    expect(semComentarioDocbloco($doc))->toBeTrue();
})->with([
    ['/** @var list<string> */'],
    ["/**\n * @param array<string, int|bool> \$params\n * @return void\n */"],
    ["/**\n * @param Closure(string, int): PsResult \$run\n * @throws \\RuntimeException\n */"],
    ["/**\n * @phpstan-type AppConfig array{\n *     db: string,\n *     tz: string\n * }\n */"],
    ['/** @phpstan-import-type AppConfig from \\App\\Config\\Config */'],
    ['/** @param (Closure(): ?string)|null $query */'],
    ['/** @template T of object */'],
]);

it('docblock com texto falha', function (string $doc) {
    expect(semComentarioDocbloco($doc))->toBeFalse();
})->with([
    ['/** Explica o que a função faz. */'],
    ["/**\n * Explica.\n *\n * @return int\n */"],
    ['/** @param string $nome o nome da pessoa */'],
    ['/** @return int quantos foram */'],
    ['/** @throws RuntimeException quando falha */'],
    ['/** @var int contador */'],
    ['/** @see Outra */'],
    ['/** */'],
]);

it('acha comentário de HTML, CSS e JS na view, e respeita string e regex', function () {
    $fonte = "<?php\n\$x = 1;\n?>\n<!-- a -->\n<style>\n  a { content: \"/* não */\"; }\n  /* b */\n</style>\n<script>\n  var u = 'http://x/y';\n  var r = /\\/\\/[a-z]/g;\n  // c\n  f(1); /* d */\n</script>\n";

    expect(semComentarioInline($fonte))->toBe([
        [4, 'comentário HTML <!-- -->'],
        [7, 'comentário CSS'],
        [12, 'comentário JS'],
        [13, 'comentário JS'],
    ]);
});

it('a varredura aponta arquivo e linha', function () {
    $raiz = sys_get_temp_dir() . '/phporto_semcom_' . bin2hex(random_bytes(4));
    mkdir("$raiz/src/Views", 0777, true);
    file_put_contents("$raiz/src/A.php", "<?php\n\n#[Attribute]\nfinal class A\n{\n    public int \$n = 1; // conta\n}\n");
    file_put_contents("$raiz/src/Views/v.php", "<?php\n/** @var object \$view */\n?>\n<p>oi</p>\n<!-- x -->\n");

    try {
        expect(semComentarioVarrer($raiz))->toBe([
            'src/A.php:6: comentário PHP',
            'src/Views/v.php:5: comentário HTML <!-- -->',
        ]);
    } finally {
        unlink("$raiz/src/A.php");
        unlink("$raiz/src/Views/v.php");
        rmdir("$raiz/src/Views");
        rmdir("$raiz/src");
        rmdir($raiz);
    }
});
