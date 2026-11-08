<?php

declare(strict_types=1);

namespace App\Cli;

use App\Autostart\Manager;
use App\Autostart\Result;
use App\Config\Config;
use Closure;

final class AutostartCommand
{
    /**
     * @param Closure(): Manager $manager
     */
    public function __construct(
        private string $root,
        private Console $console,
        private Closure $manager,
    ) {
    }

    /**
     * @param list<string> $args
     */
    public function run(array $args): int
    {
        if ($args === ['--help'] || $args === ['-h']) {
            $this->console->out(Help::for('autostart'));

            return Status::OK;
        }

        if (count($args) !== 1 || !in_array($args[0], ['on', 'off', 'status'], true)) {
            $this->console->err("Use: php phporto autostart on|off|status\n\n" . Help::for('autostart'));

            return Status::USAGE;
        }

        return match ($args[0]) {
            'on' => $this->finish(($this->manager)()->enable()),
            'off' => $this->finish(($this->manager)()->disable()),
            default => $this->status(),
        };
    }

    private function status(): int
    {
        $report = ($this->manager)()->report($this->port());
        $task = $report->task;

        $this->console->out('Início com o Windows: ' . ($report->enabled ? 'ligado' : 'desligado') . " (estado em files/autostart.json)\n");
        $this->console->out('Tarefa do Agendador: ' . ($task->present() ? 'presente' : ($task->absent() ? 'ausente' : 'não foi possível ler')) . "\n");

        if ($task->present()) {
            $this->console->out('Estado da tarefa: ' . ($task->state() ?? 'desconhecido') . "\n");
            $this->console->out('Tarefa igual ao esperado (conta, php.exe, phporto serve, S4U, sem nível elevado, na inicialização): ' . ($report->matches ? 'sim' : 'não') . "\n");
        }

        $this->console->out('Servidor responde no loopback: ' . ($report->serving ? 'sim' : 'não') . "\n");

        return Status::OK;
    }

    private function port(): int
    {
        $env = EnvReader::load($this->root, Console::quiet());

        return is_array($env) ? (Config::parsePort($env['PHPORTO_PORT'] ?? null) ?? Config::DEFAULT_PORT) : Config::DEFAULT_PORT;
    }

    private function finish(Result $result): int
    {
        $text = implode("\n", $result->lines) . "\n";

        if ($result->ok) {
            $this->console->out($text);

            return Status::OK;
        }

        $this->console->err($text);

        return Status::FAILURE;
    }
}
