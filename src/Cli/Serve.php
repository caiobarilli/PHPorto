<?php

declare(strict_types=1);

namespace App\Cli;

use App\Config\Config;
use App\Network\History;
use App\Network\Launcher;
use App\Network\State;
use App\Network\Store;
use Closure;

final class Serve
{
    public const HOST = '127.0.0.1';

    public const WATCH_S = 2;

    /** @var Closure(array<string, list<string>>, string, Closure(): list<string>, Closure(string, string, ?int): void): int */
    private Closure $runner;

    /**
     * @param (Closure(array<string, list<string>>, string, Closure(): list<string>, Closure(string, string, ?int): void): int)|null $runner
     */
    public function __construct(
        private string $root,
        private Console $console,
        ?Closure $runner = null,
        private ?Launcher $launcher = null,
        private ?History $history = null,
        private ?Store $store = null,
    ) {
        $this->runner = $runner ?? Supervisor::run(...);
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

        $env = EnvReader::load($this->root, $this->console);

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

        $children = ['loopback' => self::command($this->root, $port)];
        $network = $this->networkChild();

        if ($network !== null) {
            $children['rede'] = $network;
        }

        $this->console->out('PHPorto em http://' . self::HOST . ':' . $port . "  (Ctrl+C encerra)\n");

        return ($this->runner)($children, $this->root, $this->tick(...), $this->event(...));
    }

    /**
     * @return list<string>
     */
    public static function command(string $root, int $port): array
    {
        return self::listen($root, self::HOST . ':' . $port);
    }

    /**
     * @return list<string>
     */
    public static function networkCommand(string $root, State $state): array
    {
        return self::listen($root, $state->host());
    }

    /**
     * @return list<string>
     */
    private static function listen(string $root, string $address): array
    {
        $public = $root . DIRECTORY_SEPARATOR . 'public';

        return [PHP_BINARY, '-S', $address, '-t', $public, $public . DIRECTORY_SEPARATOR . 'router.php'];
    }

    /**
     * @return list<string>|null
     */
    private function networkChild(): ?array
    {
        if ($this->launcher === null) {
            return null;
        }

        $launch = $this->launcher->prepare();

        if ($launch->start && $launch->state !== null) {
            $this->listening = $launch->state;

            return self::networkCommand($this->root, $launch->state);
        }

        if ($launch->enabled) {
            $reason = $launch->reason ?? 'motivo desconhecido';
            $this->console->err('Rede: nenhum listener de rede sobe agora: ' . $reason . ".\n");
            $this->history?->record('listener não subiu', $reason, false);
        }

        return null;
    }

    private int $nextWatch = 0;

    private ?State $listening = null;

    /**
     * @return list<string>
     */
    private function tick(): array
    {
        if ($this->store === null || time() < $this->nextWatch) {
            return [];
        }

        $this->nextWatch = time() + self::WATCH_S;

        return $this->store->load()->enabled ? [] : ['rede'];
    }

    private function event(string $name, string $kind, ?int $code): void
    {
        if ($name !== 'rede') {
            return;
        }

        $address = $this->listening?->address() ?? 'rede';

        switch ($kind) {
            case 'start':
                $this->console->out('Rede em ' . $address . "\n");
                $this->history?->record('listener subiu', $address);

                break;
            case 'exit':
                $this->console->err('Rede: o listener de rede caiu' . ($code === null ? '' : ' (código ' . $code . ')') . ". O loopback continua.\n");
                $this->history?->record('listener caiu', 'código ' . ($code ?? '?'), false);

                break;
            case 'failed':
                $this->console->err("Rede: o listener de rede não iniciou. O loopback continua.\n");
                $this->history?->record('listener não iniciou', '', false);

                break;
            case 'stopped':
                $this->console->out("Rede desligada: listener de rede encerrado.\n");
                $this->history?->record('listener encerrado', 'rede desligada');

                break;
        }
    }
}
