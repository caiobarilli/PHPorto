<?php

declare(strict_types=1);

namespace App\Php;

use Closure;
use InvalidArgumentException;
use RuntimeException;

final class PhpIni
{
    public const MAX_BYTES = 1_048_576;

    public const BACKUP_SUFFIX = '.bak';

    public const FIELD = 'ini';

    public const HASH_FIELD = 'ini_hash';

    /**
     * @param list<string> $escaneados
     * @param Closure(string): (string|false) $lerAtual
     */
    public static function load(?string $ini, array $escaneados, Closure $lerAtual): ?IniForm
    {
        if ($ini === null) {
            return null;
        }

        try {
            $texto = self::read($ini);
        } catch (RuntimeException $e) {
            return new IniForm($ini, '', [], false, $e->getMessage());
        }

        $linhas = [];

        foreach (IniKey::cases() as $chave) {
            $declarado = IniFile::declared($texto, $chave);
            $atual     = $lerAtual($chave->value);

            $linhas[] = new IniRow(
                key: $chave,
                value: $declarado === null ? $chave->default() : $chave->normalize($declarado),
                inFile: $declarado !== null,
                running: self::running($chave, $atual),
                overriddenBy: self::overriddenBy($escaneados, $chave),
            );
        }

        return new IniForm($ini, hash('sha256', $texto), $linhas, self::writable($ini));
    }

    public static function read(string $ini): string
    {
        if (!is_file($ini) || is_link($ini)) {
            throw new RuntimeException('O php.ini carregado não é um arquivo comum no disco.');
        }

        $tamanho = @filesize($ini);

        if ($tamanho === false || $tamanho > self::MAX_BYTES) {
            throw new RuntimeException('O php.ini passa de 1 MiB. Esta tela não lê arquivo desse tamanho.');
        }

        $texto = @file_get_contents($ini, false, null, 0, self::MAX_BYTES + 1);

        if (!is_string($texto) || strlen($texto) > self::MAX_BYTES) {
            throw new RuntimeException('Não deu para ler o php.ini.');
        }

        return $texto;
    }

    public static function writable(string $ini): bool
    {
        return is_file($ini) && !is_link($ini) && is_writable($ini) && is_writable(dirname($ini));
    }

    /**
     * @param list<IniRow> $atuais
     * @return array<string, string>
     */
    public static function changes(array $atuais, mixed $enviado): array
    {
        if (!is_array($enviado)) {
            throw new InvalidArgumentException('Formulário sem as chaves do php.ini.');
        }

        $permitidas = array_map(static fn (IniKey $k): string => $k->value, IniKey::cases());

        foreach (array_keys($enviado) as $nome) {
            if (!is_string($nome) || !in_array($nome, $permitidas, true)) {
                throw new InvalidArgumentException('Chave fora da lista: ' . mb_substr((string) $nome, 0, 64) . '. Nada foi gravado.');
            }
        }

        $erros  = [];
        $novos  = [];
        $finais = [];

        foreach ($atuais as $linha) {
            $nome  = $linha->key->value;
            $valor = $enviado[$nome] ?? null;

            if (!is_string($valor)) {
                $erros[] = $nome . ': faltou o valor.';

                continue;
            }

            $finais[$nome] = $linha->value;

            if ($valor === $linha->value) {
                continue;
            }

            try {
                $novos[$nome]  = $linha->key->validate($valor);
                $finais[$nome] = $novos[$nome];
            } catch (InvalidArgumentException $e) {
                $erros[] = $e->getMessage();
            }
        }

        if ($erros === []) {
            $upload = IniKey::bytes($finais[IniKey::UploadMaxFilesize->value] ?? '');
            $post   = IniKey::bytes($finais[IniKey::PostMaxSize->value] ?? '');

            if ($upload !== null && $post !== null && $post > 0 && $post < $upload) {
                $erros[] = 'post_max_size não pode ser menor que upload_max_filesize.';
            }
        }

        if ($erros !== []) {
            throw new InvalidArgumentException(implode(' ', $erros) . ' Nada foi gravado.');
        }

        return $novos;
    }

    /**
     * @param array<string, string> $novos
     */
    public static function write(string $ini, string $hashEsperado, array $novos): string
    {
        $texto = self::read($ini);

        if (!hash_equals(hash('sha256', $texto), $hashEsperado)) {
            throw new RuntimeException('O php.ini mudou no disco depois que esta tela abriu. Releia e tente de novo. Nada foi gravado.');
        }

        return self::commit($ini, $texto, IniFile::apply($texto, $novos));
    }

    public static function enableExtension(string $ini, string $hashEsperado, PhpExtension|string $nome): bool
    {
        $nome = $nome instanceof PhpExtension ? $nome : PhpExtension::tryFrom($nome);

        if ($nome === null) {
            throw new RuntimeException('Extensão fora da lista do PHPorto. Nada foi gravado.');
        }

        $texto = self::read($ini);

        if (!hash_equals(hash('sha256', $texto), $hashEsperado)) {
            throw new RuntimeException('O php.ini mudou no disco depois que esta tela abriu. Releia e tente de novo. Nada foi gravado.');
        }

        return self::commit($ini, $texto, IniFile::enableExtension($texto, $nome->value)) !== $texto;
    }

    private static function commit(string $ini, string $texto, string $novo): string
    {
        if ($novo === $texto) {
            return $novo;
        }

        if (!self::writable($ini)) {
            throw new RuntimeException('Este usuário não pode gravar o php.ini nem a pasta dele. Esta tela não grava com UAC: edite à mão ou use um PHP numa pasta sua, como C:\\php. Nada foi gravado.');
        }

        $bak = $ini . self::BACKUP_SUFFIX;
        self::atomic($bak, $texto, $ini);
        self::atomic($ini, $novo, $ini);

        return $novo;
    }

    private static function atomic(string $destino, string $conteudo, string $modelo): void
    {
        $pasta = dirname($destino);
        $tmp   = $pasta . DIRECTORY_SEPARATOR . '.phporto-ini-' . bin2hex(random_bytes(6)) . '.tmp';
        $fh    = @fopen($tmp, 'xb');

        if ($fh === false) {
            throw new RuntimeException('Não deu para criar o arquivo temporário ao lado do php.ini. O php.ini não foi alterado.');
        }

        try {
            $escrito = fwrite($fh, $conteudo);
            fflush($fh);
            fclose($fh);

            if ($escrito !== strlen($conteudo)) {
                throw new RuntimeException('Gravação incompleta de ' . basename($destino) . '. O php.ini não foi alterado.');
            }

            $modo = @fileperms($modelo);

            if (!PhpProbe::windows() && $modo !== false) {
                @chmod($tmp, $modo & 0777);
            }

            if (!@rename($tmp, $destino)) {
                throw new RuntimeException('Não deu para trocar ' . basename($destino) . ' pelo arquivo novo. O php.ini não foi alterado.');
            }
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }

        $lido = @file_get_contents($destino);

        if (!is_string($lido) || !hash_equals(hash('sha256', $conteudo), hash('sha256', $lido))) {
            throw new RuntimeException('O conteúdo gravado em ' . basename($destino) . ' não confere.');
        }
    }

    private static function running(IniKey $chave, string|false $atual): ?string
    {
        if ($atual === false) {
            return null;
        }

        if ($chave === IniKey::Timezone && trim($atual) === '') {
            return $chave->default();
        }

        return $chave->normalize($atual);
    }

    /**
     * @param list<string> $escaneados
     */
    private static function overriddenBy(array $escaneados, IniKey $chave): ?string
    {
        foreach (array_reverse($escaneados) as $arquivo) {
            $texto = is_file($arquivo) ? @file_get_contents($arquivo, false, null, 0, self::MAX_BYTES) : false;

            if (is_string($texto) && IniFile::declared($texto, $chave) !== null) {
                return $arquivo;
            }
        }

        return null;
    }
}
