<?php

declare(strict_types=1);

namespace App\Cli;

use App\Autostart\History as AutostartHistory;
use App\Autostart\Identity;
use App\Autostart\Manager as AutostartManager;
use App\Autostart\Store as AutostartStore;
use App\Autostart\Task;
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
use App\Win\PsResult;
use App\Win\UacPolicy;
use App\Win\WinAction;
use Closure;

final class Main
{
    /** @var Closure(): Serve */
    private Closure $serve;

    /** @var Closure(): Manager */
    private Closure $manager;

    /** @var Closure(): AutostartManager */
    private Closure $autostart;

    private ?History $history = null;

    private Machine $machine;

    /**
     * @param (Closure(): Serve)|null $serve
     * @param (Closure(): Manager)|null $manager
     * @param (Closure(): AutostartManager)|null $autostart
     */
    public function __construct(private string $root, private Console $console, ?Closure $serve = null, ?Closure $manager = null, ?Machine $machine = null, ?Closure $autostart = null)
    {
        $this->autostart = $autostart ?? $this->defaultAutostart(...);
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
            'autostart' => (new AutostartCommand($this->root, $this->console, $this->autostart))->run($rest),
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
        return $this->history ??= new History($this->serviceFactory());
    }

    private function serviceFactory(): Closure
    {
        return function (): ExecutionLogService {
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
        };
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
            null,
            new AutostartHistory($this->serviceFactory()),
        );
    }

    private function oneShot(): OneShot
    {
        $winDir = $this->root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Win';
        $elevation = new Elevation(
            filesDir: $this->filesDir(),
            storageDir: $this->root . DIRECTORY_SEPARATOR . 'storage',
            winDir: $winDir,
        );

        return new OneShot($this->filesDir(), $winDir, $elevation, new UacPolicy());
    }

    private function defaultAutostart(): AutostartManager
    {
        $oneShot = $this->oneShot();
        return new AutostartManager(
            new AutostartStore($this->filesDir()),
            new Task($this->filesDir()),
            static fn (WinAction $action, array $params, int $timeout): PsResult => $oneShot->dispatch($action, $params, $timeout),
            new AutostartHistory($this->serviceFactory()),
            $this->root,
            PHP_BINARY,
            Identity::current(),
        );
    }

    private function defaultManager(): Manager
    {
        $oneShot = $this->oneShot();

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
