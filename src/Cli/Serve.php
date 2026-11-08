<?php

declare(strict_types=1);

namespace App\Cli;

use App\Config\Config;
use Closure;
use Dotenv\Dotenv;
use Dotenv\Exception\InvalidFileException;

final class Serve
{
    public const HOST = '127.0.0.1';

    /** @var Closure(list<string>, string): int */
    private Closure $runner;

    /**
     * @param (Closure(list<string>, string): int)|null $runner
     */
    public function __construct(private string $root, private Console $console, ?Closure $runner = null)
    {
        $this->runner = $runner ?? Process::foreground(...);
    }

    /**
     * @param list<string> $args
     */
    public function run(array $args): int
    {
        if ($args === ['--help'] || $args === ['-h']) {
            $this->console->out(Help::for('serve'));

            return Status::OK;
        }

        if ($args !== []) {
            $this->console->err("Argumento inesperado em serve.\n\n" . Help::for('serve'));

            return Status::USAGE;
        }

        $env = $this->env();

        if (is_int($env)) {
            return $env;
        }

        $port = Config::parsePort($env['PHPORTO_PORT'] ?? null);

        if ($port === null) {
            $this->console->err("PHPORTO_PORT inválida no .env. Use um número de 1 a 65535.\n");

            return Status::CONFIG;
        }

        if (($env['PHPORTO_AUTH_TOKEN'] ?? '') === '') {
            $this->console->err("Aviso: o .env não tem PHPORTO_AUTH_TOKEN, então nada será servido. Rode: php phporto token\n");
        }

        $this->console->out('PHPorto em http://' . self::HOST . ':' . $port . "  (Ctrl+C encerra)\n");

        return ($this->runner)(self::command($this->root, $port), $this->root);
    }

    /**
     * @return list<string>
     */
    public static function command(string $root, int $port): array
    {
        $public = $root . DIRECTORY_SEPARATOR . 'public';

        return [PHP_BINARY, '-S', self::HOST . ':' . $port, '-t', $public, $public . DIRECTORY_SEPARATOR . 'router.php'];
    }

    /**
     * @return array<string, string|null>|int
     */
    private function env(): array|int
    {
        $file = $this->root . DIRECTORY_SEPARATOR . '.env';

        if (!is_file($file)) {
            $this->console->err('Não há .env em ' . $this->root . ". Copie o .env.example para .env e rode de novo.\n");

            return Status::CONFIG;
        }

        $contents = file_get_contents($file);

        if (!is_string($contents)) {
            $this->console->err("Não foi possível ler o .env.\n");

            return Status::CONFIG;
        }

        try {
            return Dotenv::parse($contents);
        } catch (InvalidFileException) {
            $this->console->err("O .env está inválido e não pôde ser lido. Confira cada linha, no formato CHAVE=valor.\n");

            return Status::CONFIG;
        }
    }
}
