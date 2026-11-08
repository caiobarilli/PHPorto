<?php

declare(strict_types=1);

namespace App\Cli;

use App\Config\Config;
use App\Config\EnvFile;

final class Token
{
    public const KEY = 'PHPORTO_AUTH_TOKEN';

    public const BYTES = 32;

    public function __construct(private string $root, private Console $console)
    {
    }

    /**
     * @param list<string> $args
     */
    public function run(array $args): int
    {
        if ($args === ['--help'] || $args === ['-h']) {
            $this->console->out(Help::for('token'));

            return Status::OK;
        }

        if ($args !== []) {
            $this->console->err("Argumento inesperado em token.\n\n" . Help::for('token'));

            return Status::USAGE;
        }

        $file = $this->root . DIRECTORY_SEPARATOR . '.env';

        if (!is_file($file)) {
            $this->console->err('Não há .env em ' . $this->root . ". Copie o .env.example para .env e rode de novo.\n");

            return Status::CONFIG;
        }

        $contents = file_get_contents($file);

        if (!is_string($contents)) {
            $this->console->err("Não foi possível ler o .env.\n");

            return Status::FAILURE;
        }

        $current = EnvFile::value($contents, self::KEY);

        if ($current !== null && $current !== '' && !$this->confirmed()) {
            $this->console->out("Nada mudou.\n");

            return Status::FAILURE;
        }

        $token = bin2hex(random_bytes(self::BYTES));
        $temporary = $file . '.tmp-' . bin2hex(random_bytes(4));

        if (file_put_contents($temporary, EnvFile::with($contents, self::KEY, $token)) === false
            || !rename($temporary, $file)) {
            @unlink($temporary);
            $this->console->err("Não foi possível gravar o .env. Nada mudou.\n");

            return Status::FAILURE;
        }

        $port = Config::parsePort(EnvFile::value($contents, 'PHPORTO_PORT')) ?? Config::DEFAULT_PORT;

        $this->console->out("\nToken gravado em " . self::KEY . " no .env:\n\n    {$token}\n\n");
        $this->console->out("No navegador, cole no formulário de entrada.\n");
        $this->console->out("Pela API:  curl -u :{$token} http://127.0.0.1:{$port}/api/executions\n");

        return Status::OK;
    }

    private function confirmed(): bool
    {
        $this->console->out('O .env já tem um ' . self::KEY . ".\n");
        $this->console->out("Trocar derruba o acesso de quem estiver usando o token atual.\n");
        $this->console->out('Substituir? [s/N] ');

        return in_array(strtolower($this->console->readLine()), ['s', 'sim'], true);
    }
}
