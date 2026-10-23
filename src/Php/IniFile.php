<?php

declare(strict_types=1);

namespace App\Php;

final class IniFile
{
    private const BOM = "\xEF\xBB\xBF";

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function lines(string $texto): array
    {
        if ($texto === '') {
            return [];
        }

        $pedacos = preg_split('/(\r\n|\n|\r)/', $texto, -1, PREG_SPLIT_DELIM_CAPTURE);
        $pedacos = $pedacos === false ? [$texto] : $pedacos;
        $linhas  = [];

        for ($i = 0, $n = count($pedacos); $i < $n; $i += 2) {
            $linhas[] = [$pedacos[$i], $pedacos[$i + 1] ?? ''];
        }

        $ultima = $linhas[count($linhas) - 1];

        if ($ultima[0] === '' && $ultima[1] === '') {
            array_pop($linhas);
        }

        return $linhas;
    }

    /**
     * @param list<array{0: string, 1: string}> $linhas
     */
    public static function join(array $linhas): string
    {
        $texto = '';

        foreach ($linhas as [$conteudo, $fim]) {
            $texto .= $conteudo . $fim;
        }

        return $texto;
    }

    public static function eol(string $texto): string
    {
        if (preg_match('/\r\n|\n|\r/', $texto, $m) === 1) {
            return $m[0];
        }

        return PHP_EOL;
    }

    public static function header(string $linha): ?bool
    {
        if (preg_match('/\A(?:' . self::BOM . ')?[ \t]*\[([^\]]*)\]/', $linha, $m) !== 1) {
            return null;
        }

        return preg_match('/\A[ \t]*(?:PATH|HOST)[ \t]*=/i', $m[1]) === 1;
    }

    public static function declared(string $texto, IniKey $chave): ?string
    {
        $achada = self::lastDeclaration(self::lines($texto), $chave);

        return $achada === null ? null : $achada['valor'];
    }

    /**
     * @param array<string, string> $novos
     */
    public static function apply(string $texto, array $novos): string
    {
        $linhas = self::lines($texto);
        $fim    = self::eol($texto);

        foreach ($novos as $nome => $valor) {
            $chave = IniKey::from($nome);
            $linha = $chave->value . ' = ' . $chave->format($valor);
            $achada = self::lastDeclaration($linhas, $chave);

            if ($achada !== null) {
                $i          = $achada['indice'];
                $linhas[$i] = [$achada['prefixo'] . $linha, $linhas[$i][1]];

                continue;
            }

            $exemplo = self::lastExample($linhas, $chave);

            if ($exemplo !== null) {
                if ($linhas[$exemplo][1] === '') {
                    $linhas[$exemplo] = [$linhas[$exemplo][0], $fim];
                }

                $linhas = array_merge(array_slice($linhas, 0, $exemplo + 1), [[$linha, $fim]], array_slice($linhas, $exemplo + 1));

                continue;
            }

            $total = count($linhas);

            if ($total > 0 && $linhas[$total - 1][1] === '') {
                $linhas[$total - 1] = [$linhas[$total - 1][0], $fim];
            }

            if (self::endsConditional($linhas)) {
                $linhas[] = ['[PHP]', $fim];
            }

            $linhas[] = [$linha, $fim];
        }

        return self::join($linhas);
    }

    /**
     * @param list<array{0: string, 1: string}> $linhas
     * @return array{indice: int, prefixo: string, valor: string}|null
     */
    private static function lastDeclaration(array $linhas, IniKey $chave): ?array
    {
        $padrao      = '/\A((?:' . self::BOM . ')?[ \t]*)' . preg_quote($chave->value, '/') . '[ \t]*=(.*)\z/s';
        $condicional = false;
        $achada      = null;

        foreach ($linhas as $i => [$conteudo]) {
            $cabecalho = self::header($conteudo);

            if ($cabecalho !== null) {
                $condicional = $cabecalho;

                continue;
            }

            if (!$condicional && preg_match($padrao, $conteudo, $m) === 1) {
                $achada = ['indice' => $i, 'prefixo' => $m[1], 'valor' => self::value($m[2])];
            }
        }

        return $achada;
    }

    /**
     * @param list<array{0: string, 1: string}> $linhas
     */
    private static function lastExample(array $linhas, IniKey $chave): ?int
    {
        $padrao      = '/\A(?:' . self::BOM . ')?[ \t]*;[ \t]*' . preg_quote($chave->value, '/') . '[ \t]*=/';
        $condicional = false;
        $achada      = null;

        foreach ($linhas as $i => [$conteudo]) {
            $cabecalho = self::header($conteudo);

            if ($cabecalho !== null) {
                $condicional = $cabecalho;

                continue;
            }

            if (!$condicional && preg_match($padrao, $conteudo) === 1) {
                $achada = $i;
            }
        }

        return $achada;
    }

    /**
     * @param list<array{0: string, 1: string}> $linhas
     */
    private static function endsConditional(array $linhas): bool
    {
        $condicional = false;

        foreach ($linhas as [$conteudo]) {
            $cabecalho = self::header($conteudo);

            if ($cabecalho !== null) {
                $condicional = $cabecalho;
            }
        }

        return $condicional;
    }

    private static function value(string $resto): string
    {
        $lido = @parse_ini_string('v =' . $resto, false, INI_SCANNER_RAW);

        if (is_array($lido) && isset($lido['v']) && is_string($lido['v'])) {
            return trim($lido['v']);
        }

        return trim($resto);
    }
}
