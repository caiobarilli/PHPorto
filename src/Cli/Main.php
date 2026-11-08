<?php

declare(strict_types=1);

namespace App\Cli;

use Closure;

final class Main
{
    /** @var Closure(): Serve */
    private Closure $serve;

    /**
     * @param (Closure(): Serve)|null $serve
     */
    public function __construct(private string $root, private Console $console, ?Closure $serve = null)
    {
        $this->serve = $serve ?? fn (): Serve => new Serve($this->root, $this->console);
    }

    /**
     * @param list<string> $args
     */
    public function run(array $args): int
    {
        $command = $args[0] ?? 'help';
        $rest = array_slice($args, 1);

        if (in_array($command, ['--help', '-h'], true)) {
            $command = 'help';
            $rest = [];
        }

        return match ($command) {
            'help' => $this->help($rest),
            'serve' => ($this->serve)()->run($rest),
            'token' => (new Token($this->root, $this->console))->run($rest),
            default => $this->unknown($command),
        };
    }

    /**
     * @param list<string> $args
     */
    private function help(array $args): int
    {
        if ($args === []) {
            $this->console->out(Help::general());

            return Status::OK;
        }

        if (count($args) === 1 && in_array($args[0], Help::COMMANDS, true)) {
            $this->console->out(Help::for($args[0]));

            return Status::OK;
        }

        $this->console->err('Comando desconhecido: ' . $args[0] . "\n\n" . Help::general());

        return Status::USAGE;
    }

    private function unknown(string $command): int
    {
        $shown = preg_match('/^[A-Za-z0-9:_-]{1,32}$/', $command) === 1 ? $command : '?';
        $this->console->err('Comando desconhecido: ' . $shown . "\n\n" . Help::general());

        return Status::USAGE;
    }
}
