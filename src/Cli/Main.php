<?php

declare(strict_types=1);

namespace App\Cli;

use App\Network\Firewall;
use App\Network\History;
use App\Network\Launcher;
use App\Network\Machine;
use App\Network\Manager;
use App\Network\Probe;
use App\Network\Store;
use App\Services\ExecutionLogService;
use App\Win\Elevation;
use App\Win\OneShot;
use App\Win\UacPolicy;
use Closure;

final class Main
{
    /** @var Closure(): Serve */
    private Closure $serve;

    /** @var Closure(): Manager */
    private Closure $manager;

    private ?History $history = null;

    private Machine $machine;

    /**
     * @param (Closure(): Serve)|null $serve
     * @param (Closure(): Manager)|null $manager
     */
    public function __construct(private string $root, private Console $console, ?Closure $serve = null, ?Closure $manager = null, ?Machine $machine = null)
    {
        $this->machine = $machine ?? new Machine();
        $this->serve = $serve ?? $this->defaultServe(...);
        $this->manager = $manager ?? $this->defaultManager(...);
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
            'network' => (new NetworkCommand($this->root, $this->console, $this->manager, $this->machine))->run($rest),
            default => $this->unknown($command),
        };
    }

    private function filesDir(): string
    {
        return $this->root . DIRECTORY_SEPARATOR . 'files';
    }

    private function history(): History
    {
        return $this->history ??= new History(function (): ExecutionLogService {
            $app = require $this->root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'bootstrap.php';
            $make = is_array($app) ? ($app['makeService'] ?? null) : null;

            if (!$make instanceof Closure) {
                throw new \RuntimeException('Sem serviço de histórico.');
            }

            $service = $make();

            if (!$service instanceof ExecutionLogService) {
                throw new \RuntimeException('Sem serviço de histórico.');
            }

            return $service;
        });
    }

    private function defaultServe(): Serve
    {
        $store = new Store($this->filesDir());

        return new Serve(
            $this->root,
            $this->console,
            null,
            new Launcher($store, $this->machine, new Probe($this->filesDir())),
            $this->history(),
            $store,
        );
    }

    private function defaultManager(): Manager
    {
        $winDir = $this->root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Win';
        $elevation = new Elevation(
            filesDir: $this->filesDir(),
            storageDir: $this->root . DIRECTORY_SEPARATOR . 'storage',
            winDir: $winDir,
        );
        $oneShot = new OneShot($this->filesDir(), $winDir, $elevation, new UacPolicy());

        return new Manager(
            new Store($this->filesDir()),
            $this->machine,
            new Probe($this->filesDir()),
            Firewall::fromOneShot($oneShot),
            $this->history(),
        );
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
