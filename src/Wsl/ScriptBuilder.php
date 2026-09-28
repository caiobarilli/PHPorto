<?php

declare(strict_types=1);

namespace App\Wsl;

/**
 * Monta o conteúdo do cmd.sh.
 *
 * O texto do usuário NUNCA vai para a linha de comando: ele é gravado em
 * arquivo e o wsl.exe recebe apenas o caminho. Assim aspas, cifrão, crase e
 * subshell passam intactos — qualquer escape que se escrevesse falharia em
 * algum deles, e a falha apareceria como erro DO COMANDO, mandando quem
 * depura para o lugar errado.
 *
 * Caminhos também não são interpolados: viajam pelo ambiente (ver Runner).
 */
final class ScriptBuilder
{
    /**
     * Quantas linhas o cabeçalho ocupa. É UMA, e isso é contrato:
     * o bash cita o número de linha do arquivo, então um erro na primeira
     * linha do que a pessoa digitou aparece como "cmd.sh: line 2". O
     * docs/wsl.md documenta esse deslocamento de +1; passar o cabeçalho para duas linhas
     * faria a documentação mentir sem ninguém perceber.
     */
    public const HEADER_LINES = 1;

    /** Código de saída quando a raiz não é acessível dentro do WSL. */
    public const EXIT_ROOT_UNREACHABLE = 78;

    /**
     * Tira BOM, normaliza qualquer quebra de linha para LF e garante LF final.
     *
     * O PHP no Windows escreve CRLF por vários caminhos e um editor pode
     * acrescentar BOM; o bash do Linux engasga com o \r e com o BOM. Sintomas
     * conhecidos: "$'\r': command not found", ou a primeira linha ignorada.
     */
    public static function normalize(string $text): string
    {
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        }

        $text = str_replace(["\r\n", "\r"], "\n", $text);

        if ($text !== '' && !str_ends_with($text, "\n")) {
            $text .= "\n";
        }

        return $text;
    }

    /**
     * Cabeçalho de todo cmd.sh, em UMA linha só.
     *
     * "exec 2>&1" funde os dois descritores dentro do próprio bash: é o único
     * jeito de a ordem das linhas ser a real, porque dois pipes separados no
     * Windows entregam stdout e stderr embaralhados pelo buffer.
     *
     * O cd guardado impede a queda silenciosa no home quando a raiz não
     * existe dentro da distro.
     */
    public static function header(): string
    {
        return 'exec 2>&1; cd "$PHPORTO_WSL_ROOT" || { echo "[phporto] PHPORTO_WSL_ROOT nao acessivel dentro do WSL: $PHPORTO_WSL_ROOT"; exit '
            . self::EXIT_ROOT_UNREACHABLE . '; }' . "\n";
    }

    /** Script de um comando digitado pela pessoa. */
    public static function command(string $userText): string
    {
        return self::header() . self::normalize($userText);
    }

    /**
     * Script do card de anexos.
     *
     * Os caminhos chegam em PHPORTO_SRC/PHPORTO_DST pelo ambiente. Aqui só o
     * til inicial é resolvido, porque "~" dentro de aspas não expande e
     * viraria uma pasta chamada "~" — e a resolução é feita SEM eval, que
     * transformaria um campo de texto em execução de código arbitrário no
     * exato lugar onde isso é mais difícil de notar.
     *
     * O "--" protege caminho que comece com hífen; o "-v" faz o cp declarar o
     * que copiou, o que serve de comprovante na saída e no registro.
     */
    public static function attachment(): string
    {
        return self::header()
            . 'case "$PHPORTO_SRC" in "~") PHPORTO_SRC="$HOME";; "~/"*) PHPORTO_SRC="$HOME/${PHPORTO_SRC#\~/}";; esac' . "\n"
            . 'case "$PHPORTO_DST" in "~") PHPORTO_DST="$HOME";; "~/"*) PHPORTO_DST="$HOME/${PHPORTO_DST#\~/}";; esac' . "\n"
            . 'cp -v -- "$PHPORTO_SRC" "$PHPORTO_DST"' . "\n";
    }

    /**
     * O que se registra de um anexo: o comando efetivo mais os dois caminhos.
     * O log precisa dizer o que foi copiado, e os caminhos não aparecem no
     * script justamente porque viajam pelo ambiente.
     */
    public static function attachmentRecord(string $src, string $dst): string
    {
        return 'cp -v -- "$PHPORTO_SRC" "$PHPORTO_DST"' . "\n"
            . '  origem  (PHPORTO_SRC): ' . $src . "\n"
            . '  destino (PHPORTO_DST): ' . $dst;
    }
}
