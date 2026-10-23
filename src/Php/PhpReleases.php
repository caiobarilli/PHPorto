<?php

declare(strict_types=1);

namespace App\Php;

use App\Win\WinAction;
use Closure;

final class PhpReleases
{
    public const HOST = 'downloads.php.net';

    public const BASE_PATH = '/~windows/releases/';

    public const BASE_URL = 'https://' . self::HOST . self::BASE_PATH;

    public const LISTING_URL = self::BASE_URL . 'releases.json';

    public const PATH_PATTERN = '#\A/~windows/releases/(?:releases\.json|php-[0-9]{1,2}\.[0-9]{1,2}\.[0-9]{1,3}-nts-Win32-v[sc][0-9]{1,3}-x64\.zip)\z#';

    public const BUILD_PATTERN = '/\Ants-(v[sc][0-9]{1,3}-x64)\z/';

    public const FETCH_TIMEOUT_S = 4;

    public const MAX_LISTING_BYTES = 1048576;

    public const CACHE_OK_S = 3600;

    public const CACHE_FAIL_S = 300;

    public const POST_FIELD = 'php_update';

    public static function minor(string $version): ?string
    {
        if (preg_match('/' . WinAction::PHP_UPDATE_VERSION_PATTERN . '/', $version, $m) !== 1) {
            return null;
        }

        return $m[1] . '.' . $m[2];
    }

    public static function isAllowedUrl(string $url): bool
    {
        if (preg_match('/[\x00-\x20\x7F\\\\]/', $url) === 1) {
            return false;
        }

        $partes = parse_url($url);

        if (!is_array($partes)) {
            return false;
        }

        foreach (['port', 'user', 'pass', 'query', 'fragment'] as $proibida) {
            if (array_key_exists($proibida, $partes)) {
                return false;
            }
        }

        return ($partes['scheme'] ?? '') === 'https'
            && ($partes['host'] ?? '') === self::HOST
            && preg_match(self::PATH_PATTERN, $partes['path'] ?? '') === 1;
    }

    public static function parse(string $json, string $minor): ?PhpRelease
    {
        $dados = json_decode($json, true);

        if (!is_array($dados) || !is_array($dados[$minor] ?? null)) {
            return null;
        }

        $linha  = $dados[$minor];
        $versao = $linha['version'] ?? null;

        if (!is_string($versao) || self::minor($versao) !== $minor) {
            return null;
        }

        $builds = array_keys($linha);
        rsort($builds, SORT_STRING);

        foreach ($builds as $build) {
            if (!is_string($build) || preg_match(self::BUILD_PATTERN, $build, $m) !== 1 || !is_array($linha[$build])) {
                continue;
            }

            $zip = $linha[$build]['zip'] ?? null;

            if (!is_array($zip)) {
                continue;
            }

            $arquivo = $zip['path'] ?? null;
            $hash    = $zip['sha256'] ?? null;

            if ($arquivo !== 'php-' . $versao . '-nts-Win32-' . $m[1] . '.zip' || !is_string($hash)) {
                continue;
            }

            $hash = strtolower($hash);

            if (preg_match('/' . WinAction::PHP_UPDATE_SHA256_PATTERN . '/', $hash) !== 1) {
                continue;
            }

            $release = new PhpRelease($versao, $hash, $arquivo);

            if (self::isAllowedUrl($release->url())) {
                return $release;
            }
        }

        return null;
    }

    public static function newer(string $current, PhpRelease $release): bool
    {
        $minor = self::minor($current);

        return $minor !== null
            && self::minor($release->version) === $minor
            && version_compare($release->version, $current, '>');
    }

    public static function dirOf(string $binary): ?string
    {
        $corte = strrpos($binary, '\\');

        if ($corte === false) {
            return null;
        }

        $dir = substr($binary, 0, $corte);

        if (strlen($dir) > WinAction::PHP_UPDATE_DIR_MAX || preg_match('/' . WinAction::PHP_UPDATE_DIR_PATTERN . '/', $dir) !== 1) {
            return null;
        }

        return $dir;
    }

    /**
     * @param Closure(): ?string $buscarLista
     * @param Closure(string): ?string $versaoNoDisco
     */
    public static function plan(?PhpBuild $ativo, bool $windows, Closure $buscarLista, Closure $versaoNoDisco): PhpUpdate
    {
        if ($ativo === null) {
            return new PhpUpdate('Sem a medida do PHP em uso, não há como comparar versão.');
        }

        if (!$windows) {
            return new PhpUpdate('Atualizar pelo zip oficial só existe no Windows.');
        }

        if ($ativo->threadSafe || $ativo->arch !== 'x64') {
            return new PhpUpdate('Só um PHP NTS x64 é atualizado por aqui, com o zip oficial do mesmo tipo. Este é ' . $ativo->thread() . '.');
        }

        $minor = self::minor($ativo->version);

        if ($minor === null) {
            return new PhpUpdate('A versão ' . $ativo->version . ' não é um patch de release. Não há o que comparar.');
        }

        $dir = self::dirOf($ativo->binary);

        if ($dir === null) {
            return new PhpUpdate('A pasta do php.exe em uso não tem um caminho que a atualização aceite: ' . $ativo->binary . '.');
        }

        $listing = $buscarLista();

        if ($listing === null) {
            return new PhpUpdate('Não deu para consultar a lista oficial do PHP para Windows agora. Tente de novo mais tarde.');
        }

        $release = self::parse($listing, $minor);

        if ($release === null) {
            return new PhpUpdate('A lista oficial não traz um zip NTS x64 da linha ' . $minor . '.');
        }

        if (!self::newer($ativo->version, $release)) {
            return new PhpUpdate('Em dia: ' . $ativo->version . ' é o último patch da linha ' . $minor . '.');
        }

        if ($versaoNoDisco($ativo->binary) === $release->version) {
            return new PhpUpdate('O php.exe da pasta já é o ' . $release->version . '. Feche o php -S e suba de novo para usar a versão nova.');
        }

        return new PhpUpdate('Há o ' . $release->version . ' na linha ' . $minor . '. Esta tela não troca de linha: só o patch.', $release, $dir);
    }

    public static function fetch(string $url): ?string
    {
        if (!self::isAllowedUrl($url)) {
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

        $corpo = @file_get_contents($url, false, $contexto, 0, self::MAX_LISTING_BYTES + 1);

        if (!is_string($corpo) || strlen($corpo) > self::MAX_LISTING_BYTES) {
            return null;
        }

        $cabecalhos = http_get_last_response_headers() ?? [];

        if (preg_match('#\AHTTP/\S+ 200(?:\s|\z)#', $cabecalhos[0] ?? '') !== 1) {
            return null;
        }

        return $corpo;
    }

    /**
     * @param Closure(): ?string $buscar
     */
    public static function cached(string $arquivo, int $agora, Closure $buscar): ?string
    {
        $salvo = is_file($arquivo) ? json_decode((string) @file_get_contents($arquivo), true) : null;

        if (is_array($salvo) && is_int($salvo['t'] ?? null)) {
            $corpo = $salvo['corpo'] ?? null;
            $ok    = is_string($corpo);
            $prazo = $ok ? self::CACHE_OK_S : self::CACHE_FAIL_S;

            if ($agora >= $salvo['t'] && $agora - $salvo['t'] < $prazo) {
                return $ok ? $corpo : null;
            }
        }

        $corpo = $buscar();
        $dir   = dirname($arquivo);

        if (is_dir($dir) || @mkdir($dir, 0o775, true)) {
            @file_put_contents($arquivo, json_encode(['t' => $agora, 'corpo' => $corpo]), LOCK_EX);
        }

        return $corpo;
    }
}
