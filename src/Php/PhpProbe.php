<?php

declare(strict_types=1);

namespace App\Php;

final class PhpProbe
{
    public const MEASURE_TIMEOUT_S = 5;

    public const UPLOAD_PROGRESS = 'session.upload_progress.enabled';

    private const MAX_OUTPUT_BYTES = 65536;

    private const POLL_US = 50_000;

    private const SNIPPET = 'echo json_encode([PHP_VERSION, (bool) ZEND_THREAD_SAFE, PHP_BINARY, php_ini_loaded_file(), PHP_SAPI]);';

    public static function current(): ?PhpBuild
    {
        if (!is_file(PHP_BINARY)) {
            return null;
        }

        ob_start();
        phpinfo(INFO_GENERAL);
        $info = (string) ob_get_clean();

        $ini = php_ini_loaded_file();
        $ini = $ini === false ? null : $ini;

        return new PhpBuild(
            version: PHP_VERSION,
            threadSafe: ZEND_THREAD_SAFE,
            binary: PHP_BINARY,
            ini: $ini,
            sapi: PHP_SAPI,
            compiler: self::infoField($info, 'Compiler'),
            arch: self::infoField($info, 'Architecture'),
            uploadProgress: self::flag(ini_get(self::UPLOAD_PROGRESS)),
            uploadProgressDefault: !self::declaredInFiles(self::iniFiles($ini, php_ini_scanned_files()), self::UPLOAD_PROGRESS),
        );
    }

    public static function infoField(string $info, string $campo): ?string
    {
        $nome = preg_quote($campo, '/');

        if (preg_match('/<td class="e">\s*' . $nome . '\s*<\/td>\s*<td class="v">\s*([^<]*?)\s*<\/td>/', $info, $m) === 1) {
            $valor = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');

            return $valor === '' ? null : $valor;
        }

        if (preg_match('/^' . $nome . '\s*=>\s*(.*?)\s*$/m', $info, $m) === 1) {
            return $m[1] === '' ? null : $m[1];
        }

        return null;
    }

    public static function flag(string|false $valor): ?bool
    {
        if ($valor === false) {
            return null;
        }

        return in_array(strtolower(trim($valor)), ['1', 'on', 'yes', 'true'], true);
    }

    /**
     * @return list<string>
     */
    public static function iniFiles(?string $ini, string|false $scanned): array
    {
        $arquivos = $ini === null ? [] : [$ini];

        if ($scanned !== false) {
            foreach (explode(',', $scanned) as $arquivo) {
                $arquivo = trim($arquivo);

                if ($arquivo !== '') {
                    $arquivos[] = $arquivo;
                }
            }
        }

        return $arquivos;
    }

    public static function declares(string $iniText, string $chave): bool
    {
        return preg_match('/^[ \t]*' . preg_quote($chave, '/') . '[ \t]*=/mi', $iniText) === 1;
    }

    /**
     * @param list<string> $arquivos
     */
    public static function declaredInFiles(array $arquivos, string $chave): bool
    {
        foreach ($arquivos as $arquivo) {
            $texto = is_file($arquivo) ? @file_get_contents($arquivo) : false;

            if (is_string($texto) && self::declares($texto, $chave)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param non-empty-string $separador
     */
    public static function onPath(string $path, string $separador, string $executavel): ?string
    {
        foreach (explode($separador, $path) as $pasta) {
            $pasta = trim($pasta, " \t\"");

            if ($pasta === '') {
                continue;
            }

            $candidato = rtrim($pasta, '/\\') . DIRECTORY_SEPARATOR . $executavel;

            if (is_file($candidato)) {
                return $candidato;
            }
        }

        return null;
    }

    public static function sameFile(string $a, string $b, bool $ignorarCaixa): bool
    {
        $ra = realpath($a);
        $rb = realpath($b);
        $a  = $ra === false ? $a : $ra;
        $b  = $rb === false ? $b : $rb;

        return $ignorarCaixa ? strcasecmp($a, $b) === 0 : $a === $b;
    }

    public static function fromJson(string $saida): ?PhpBuild
    {
        $dados = json_decode(trim($saida), true);

        if (!is_array($dados) || count($dados) !== 5) {
            return null;
        }

        [$versao, $ts, $binario, $ini, $sapi] = array_values($dados);

        if (!is_string($versao) || $versao === '' || !is_bool($ts) || !is_string($binario) || !is_string($sapi)) {
            return null;
        }

        if ($ini !== false && !is_string($ini)) {
            return null;
        }

        return new PhpBuild(
            version: $versao,
            threadSafe: $ts,
            binary: $binario,
            ini: $ini === false ? null : $ini,
            sapi: $sapi,
        );
    }

    public static function windows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    public static function executable(): string
    {
        return self::windows() ? 'php.exe' : 'php';
    }

    private static function nullDevice(): string
    {
        return self::windows() ? 'NUL' : '/dev/null';
    }

    public static function measure(string $binario, int $timeout = self::MEASURE_TIMEOUT_S): ?PhpBuild
    {
        if (!is_file($binario)) {
            return null;
        }

        $saidaArquivo = tempnam(sys_get_temp_dir(), 'phporto-php-');

        if ($saidaArquivo === false) {
            return null;
        }

        try {
            $descritores = [
                0 => ['file', self::nullDevice(), 'r'],
                1 => ['file', $saidaArquivo, 'w'],
                2 => ['file', self::nullDevice(), 'w'],
            ];

            $pipes = [];
            $proc  = @proc_open([$binario, '-r', self::SNIPPET], $descritores, $pipes, null, null, ['bypass_shell' => true]);

            if (!is_resource($proc)) {
                return null;
            }

            $inicio = microtime(true);

            while (true) {
                $status = proc_get_status($proc);

                if ($status['running'] === false) {
                    break;
                }

                if ((microtime(true) - $inicio) >= $timeout) {
                    proc_terminate($proc);
                    proc_close($proc);

                    return null;
                }

                usleep(self::POLL_US);
            }

            proc_close($proc);

            $saida = @file_get_contents($saidaArquivo, false, null, 0, self::MAX_OUTPUT_BYTES);

            return is_string($saida) ? self::fromJson($saida) : null;
        } finally {
            @unlink($saidaArquivo);
        }
    }
}
