<?php

declare(strict_types=1);

namespace App\Wsl;

use Closure;
use InvalidArgumentException;
use RuntimeException;

final class Upload
{
    public const MAX_BYTES = 25 * 1024 * 1024;

    public const FIELD = 'arquivo';

    public const EXIT_DESTINATION_EXISTS = 79;

    private const NAME_MAX_BYTES = 255;

    /**
     * @param Closure(string): bool $isUploaded
     * @param Closure(string, string): bool $move
     */
    public function __construct(
        private readonly string $filesDir,
        private readonly Closure $isUploaded,
        private readonly Closure $move,
    ) {
    }

    public static function forRequest(string $filesDir): self
    {
        return new self(
            $filesDir,
            static fn (string $path): bool => is_uploaded_file($path),
            static fn (string $from, string $to): bool => move_uploaded_file($from, $to),
        );
    }

    public static function iniBytes(string $value): int
    {
        $value = trim($value);

        if (preg_match('/^(-?\d+)\s*([kmg])?$/i', $value, $m) !== 1) {
            return 0;
        }

        $n = (int) $m[1];
        if ($n <= 0) {
            return 0;
        }

        return match (strtolower($m[2] ?? '')) {
            'g'     => $n * 1024 * 1024 * 1024,
            'm'     => $n * 1024 * 1024,
            'k'     => $n * 1024,
            default => $n,
        };
    }

    public static function limit(?string $uploadMax = null, ?string $postMax = null): int
    {
        $tetos = [self::MAX_BYTES];

        foreach ([$uploadMax ?? (string) ini_get('upload_max_filesize'), $postMax ?? (string) ini_get('post_max_size')] as $ini) {
            $bytes = self::iniBytes($ini);
            if ($bytes > 0) {
                $tetos[] = $bytes;
            }
        }

        return min($tetos);
    }

    /**
     * @param array<mixed> $server
     * @param array<mixed> $post
     * @param array<mixed> $files
     */
    public static function postOverflow(array $server, array $post, array $files, ?string $postMax = null): bool
    {
        $teto    = self::iniBytes($postMax ?? (string) ini_get('post_max_size'));
        $tamanho = $server['CONTENT_LENGTH'] ?? null;
        $metodo  = $server['REQUEST_METHOD'] ?? null;

        return $teto > 0
            && is_string($metodo) && strtoupper($metodo) === 'POST'
            && $post === [] && $files === []
            && is_numeric($tamanho) && (int) $tamanho > $teto;
    }

    public static function megabytes(int $bytes): string
    {
        return number_format($bytes / (1024 * 1024), $bytes % (1024 * 1024) === 0 ? 0 : 1, ',', '.') . ' MB';
    }

    public static function displayName(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        $name = basename($name);
        $name = (string) preg_replace('/[\x00-\x1f\x7f]/', '', $name);

        if (!mb_check_encoding($name, 'UTF-8')) {
            $name = mb_convert_encoding($name, 'UTF-8', 'UTF-8');
        }

        return mb_strcut($name, 0, self::NAME_MAX_BYTES, 'UTF-8');
    }

    public static function record(string $name, int $size, string $destination): string
    {
        return 'cp -v -- "$PHPORTO_SRC" "$PHPORTO_DST"' . "\n"
            . '  arquivo enviado: ' . $name . ' (' . number_format($size, 0, ',', '.') . ' bytes)' . "\n"
            . '  destino (PHPORTO_DST): ' . $destination;
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function destination(mixed $raw): string
    {
        $dst = trim(is_string($raw) ? $raw : '');

        if ($dst === '') {
            throw new InvalidArgumentException('Preencha o destino do anexo.');
        }

        InputLimit::path('Destino', $dst);

        return $dst;
    }

    /**
     * @return array{name: string, size: int, tmp: string}
     * @throws InvalidArgumentException
     */
    public function accept(mixed $file, int $limit): array
    {
        if (!is_array($file) || !array_key_exists('error', $file)) {
            throw new InvalidArgumentException('Escolha um arquivo para enviar.');
        }

        if (is_array($file['error'])) {
            throw new InvalidArgumentException('Envie um arquivo por vez.');
        }

        $erro = is_int($file['error']) ? $file['error'] : UPLOAD_ERR_NO_FILE;

        $acima = sprintf('O arquivo passou do limite de %s por envio. Nada foi copiado.', self::megabytes($limit));

        $mensagem = match ($erro) {
            UPLOAD_ERR_OK                              => null,
            UPLOAD_ERR_NO_FILE                         => 'Escolha um arquivo para enviar.',
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE  => $acima,
            UPLOAD_ERR_PARTIAL                         => 'O arquivo chegou pela metade. Envie de novo.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'O servidor não conseguiu guardar o arquivo recebido. Nada foi copiado.',
            UPLOAD_ERR_EXTENSION                       => 'Uma extensão do PHP barrou o envio. Nada foi copiado.',
            default                                    => 'O envio falhou. Nada foi copiado.',
        };

        if ($mensagem !== null) {
            throw new InvalidArgumentException($mensagem);
        }

        $tmp  = is_string($file['tmp_name'] ?? null) ? $file['tmp_name'] : '';
        $size = is_int($file['size'] ?? null) ? $file['size'] : -1;
        $name = self::displayName(is_string($file['name'] ?? null) ? $file['name'] : '');

        if ($tmp === '' || !($this->isUploaded)($tmp)) {
            throw new InvalidArgumentException('O arquivo não chegou por um envio deste formulário. Nada foi copiado.');
        }

        $real = filesize($tmp);
        if ($real === false || $size < 0) {
            throw new InvalidArgumentException('O envio falhou. Nada foi copiado.');
        }

        if (max($size, $real) > $limit) {
            throw new InvalidArgumentException($acima);
        }

        if ($name === '') {
            $name = 'arquivo';
        }

        return ['name' => $name, 'size' => $real, 'tmp' => $tmp];
    }

    /**
     * @param array{name: string, size: int, tmp: string} $file
     * @param Closure(string, array<string, string>): RunResult $run
     * @throws RuntimeException
     */
    public function copy(array $file, string $destination, Closure $run): RunResult
    {
        if (!is_dir($this->filesDir) && !@mkdir($this->filesDir, 0o775, true) && !is_dir($this->filesDir)) {
            throw new RuntimeException('Não foi possível criar a pasta de trabalho. Nada foi copiado.');
        }

        $temp = $this->filesDir . DIRECTORY_SEPARATOR . 'anexo-' . bin2hex(random_bytes(8)) . '.tmp';

        try {
            if (!($this->move)($file['tmp'], $temp)) {
                throw new RuntimeException('Não foi possível preparar o arquivo para o WSL. Nada foi copiado.');
            }

            return $run(ScriptBuilder::upload(), [
                'PHPORTO_SRC' => Runner::toWslPath($temp),
                'PHPORTO_DST' => $destination,
            ]);
        } finally {
            if (is_file($temp)) {
                @unlink($temp);
            }
        }
    }
}
