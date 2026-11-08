<?php

declare(strict_types=1);

namespace App\Cli;

use Dotenv\Dotenv;
use Dotenv\Exception\InvalidFileException;

final class EnvReader
{
    /**
     * @return array<string, string|null>|int
     */
    public static function load(string $root, Console $console): array|int
    {
        $file = $root . DIRECTORY_SEPARATOR . '.env';

        if (!is_file($file)) {
            $console->err('Não há .env em ' . $root . ". Copie o .env.example para .env e rode de novo.\n");

            return Status::CONFIG;
        }

        $contents = file_get_contents($file);

        if (!is_string($contents)) {
            $console->err("Não foi possível ler o .env.\n");

            return Status::CONFIG;
        }

        try {
            return Dotenv::parse($contents);
        } catch (InvalidFileException) {
            $console->err("O .env está inválido e não pôde ser lido. Confira cada linha, no formato CHAVE=valor.\n");

            return Status::CONFIG;
        }
    }
}
