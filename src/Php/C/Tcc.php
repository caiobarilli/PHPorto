<?php

declare(strict_types=1);

namespace App\Php\C;

use Closure;
use RuntimeException;
use ZipArchive;

final class Tcc
{
    public const VERSION = '0.9.27';

    public const HOST = 'download-mirror.savannah.gnu.org';

    public const ZIP_MISSING = 'A extensão zip não está carregada neste PHP, e o instalador precisa dela para abrir o pacote. Ative zip na seção Extensões, suba o php -S de novo e tente outra vez. Nada foi baixado.';
    public const URL = 'https://' . self::HOST . '/releases/tinycc/tcc-' . self::VERSION . '-win64-bin.zip';

    public const SHA256 = '34a721949a2583fdff725312da092fa0f5f1f284b702e6f811c6954714faabb2';

    public const ZIP_ROOT = 'tcc/';

    public const LIBTCC = 'libtcc.dll';

    public const MARKER = 'phporto-tcc.json';

    public const FETCH_TIMEOUT_S = 30;

    public const MAX_ZIP_BYTES = 4194304;

    public const MAX_ENTRIES = 512;

    public const MAX_EXTRACTED_BYTES = 33554432;

    public const ACTION = 'tcc-instalar';

    public static function dir(string $storageDir): string
    {
        return $storageDir . DIRECTORY_SEPARATOR . 'tcc';
    }

    public static function status(string $dir, string $sha256 = self::SHA256): TccStatus
    {
        $arquivo = $dir . DIRECTORY_SEPARATOR . self::MARKER;
        $marca   = is_file($arquivo) ? @file_get_contents($arquivo) : false;
        $dados = is_string($marca) ? json_decode($marca, true) : null;
        $dll   = self::libDir($dir) . DIRECTORY_SEPARATOR . self::LIBTCC;

        if (!is_array($dados) || !is_string($dados['version'] ?? null) || ($dados['sha256'] ?? null) !== $sha256 || !is_file($dll)) {
            return new TccStatus($dir);
        }

        return new TccStatus($dir, $dados['version'], $dll);
    }

    public static function libDir(string $dir): string
    {
        return $dir . DIRECTORY_SEPARATOR . rtrim(self::ZIP_ROOT, '/');
    }

    public static function fetch(string $url): ?string
    {
        if ($url !== self::URL) {
            return null;
        }

        $contexto = stream_context_create([
            'http' => [
                'method'          => 'GET',
                'follow_location' => 0,
                'timeout'         => self::FETCH_TIMEOUT_S,
                'ignore_errors'   => true,
                'header'          => "User-Agent: PHPorto\r\n",
            ],
            'ssl'  => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $corpo = @file_get_contents($url, false, $contexto, 0, self::MAX_ZIP_BYTES + 1);

        if (!is_string($corpo) || strlen($corpo) > self::MAX_ZIP_BYTES) {
            return null;
        }

        $cabecalhos = http_get_last_response_headers() ?? [];

        if (preg_match('#\AHTTP/\S+ 200(?:\s|\z)#', $cabecalhos[0] ?? '') !== 1) {
            return null;
        }

        return $corpo;
    }

    public static function safeEntry(string $nome): bool
    {
        if (!str_starts_with($nome, self::ZIP_ROOT) || preg_match('/[\x00-\x1F\x7F\\\\:*?"<>|]/', $nome) === 1) {
            return false;
        }

        foreach (explode('/', $nome) as $parte) {
            if ($parte === '..' || $parte === '.') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param Closure(string): ?string $baixar
     */
    public static function install(string $dir, Closure $baixar, string $sha256 = self::SHA256, ?bool $zip = null): string
    {
        if (!($zip ?? class_exists(ZipArchive::class))) {
            return self::ZIP_MISSING;
        }

        if (self::status($dir, $sha256)->installed()) {
            return 'O Tiny C Compiler ' . self::VERSION . ' já está instalado em ' . self::libDir($dir) . '. Nada mudou.';
        }

        $zip = $baixar(self::URL);

        if ($zip === null) {
            return 'Não deu para baixar ' . self::URL . '. Confira a rede e tente de novo. Nada foi instalado.';
        }

        $hash = hash('sha256', $zip);

        if (!hash_equals($sha256, $hash)) {
            return 'O zip baixado tem SHA256 ' . $hash . ', diferente do fixado no PHPorto (' . $sha256 . '). Nada foi instalado.';
        }

        try {
            self::extract($dir, $zip, $sha256);
        } catch (RuntimeException $e) {
            return $e->getMessage() . ' Nada foi instalado.';
        }

        return 'Tiny C Compiler ' . self::VERSION . ' instalado em ' . self::libDir($dir) . ', sem UAC e sem mexer no PATH.';
    }

    private static function extract(string $dir, string $zip, string $sha256): void
    {
        $pai = dirname($dir);

        if (!is_dir($pai) && !@mkdir($pai, 0o775, true)) {
            throw new RuntimeException('Não deu para criar ' . $pai . '.');
        }

        $tmp     = $dir . '.tmp-' . bin2hex(random_bytes(4));
        $arquivo = $tmp . '.zip';

        try {
            if (@file_put_contents($arquivo, $zip) !== strlen($zip)) {
                throw new RuntimeException('Não deu para gravar o zip em ' . $pai . '.');
            }

            $pacote = new ZipArchive();

            if ($pacote->open($arquivo, ZipArchive::RDONLY) !== true) {
                throw new RuntimeException('O zip baixado não abre.');
            }

            try {
                self::checkEntries($pacote);

                if (!@mkdir($tmp, 0o775) || !$pacote->extractTo($tmp)) {
                    throw new RuntimeException('Não deu para extrair o zip em ' . $pai . '.');
                }
            } finally {
                $pacote->close();
            }

            if (!is_file($tmp . DIRECTORY_SEPARATOR . rtrim(self::ZIP_ROOT, '/') . DIRECTORY_SEPARATOR . self::LIBTCC)) {
                throw new RuntimeException('O zip não trouxe ' . self::ZIP_ROOT . self::LIBTCC . '.');
            }

            $marca = json_encode(['version' => self::VERSION, 'sha256' => $sha256, 'url' => self::URL]);

            if (@file_put_contents($tmp . DIRECTORY_SEPARATOR . self::MARKER, (string) $marca) === false) {
                throw new RuntimeException('Não deu para gravar a marca da instalação.');
            }

            if (is_dir($dir)) {
                self::remove($dir);
            }

            if (!@rename($tmp, $dir)) {
                throw new RuntimeException('Não deu para mover a pasta extraída para ' . $dir . '.');
            }
        } finally {
            @unlink($arquivo);

            if (is_dir($tmp)) {
                self::remove($tmp);
            }
        }
    }

    private static function checkEntries(ZipArchive $pacote): void
    {
        if ($pacote->numFiles < 1 || $pacote->numFiles > self::MAX_ENTRIES) {
            throw new RuntimeException('O zip tem ' . $pacote->numFiles . ' entradas, fora do esperado.');
        }

        $total = 0;

        for ($i = 0; $i < $pacote->numFiles; $i++) {
            $info = $pacote->statIndex($i);

            if ($info === false || !self::safeEntry($info['name'])) {
                throw new RuntimeException('O zip tem uma entrada fora de ' . self::ZIP_ROOT . '.');
            }

            $total += $info['size'];
        }

        if ($total > self::MAX_EXTRACTED_BYTES) {
            throw new RuntimeException('O zip extraído passaria de ' . self::MAX_EXTRACTED_BYTES . ' bytes.');
        }
    }

    private static function remove(string $alvo): void
    {
        if (is_link($alvo) || is_file($alvo)) {
            @unlink($alvo);

            return;
        }

        foreach (scandir($alvo) ?: [] as $nome) {
            if ($nome !== '.' && $nome !== '..') {
                self::remove($alvo . DIRECTORY_SEPARATOR . $nome);
            }
        }

        @rmdir($alvo);
    }
}
