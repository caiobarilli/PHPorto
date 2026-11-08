<?php

declare(strict_types=1);

namespace App\Cli;

use App\Autostart\History as AutostartHistory;
use App\Autostart\Level;
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

    /** @var Closure(array<string, list<string>>, string, Closure(): list<string>, Closure(string, string, ?int): void, Closure(string): ?list<string>): int */
    private Closure $runner;

    /** @var list<string> */
    private array $loopback = [];

    /** @var Closure(): ?string */
    private Closure $level;

    /**
     * @param (Closure(array<string, list<string>>, string, Closure(): list<string>, Closure(string, string, ?int): void, Closure(string): ?list<string>): int)|null $runner
     * @param (Closure(): ?string)|null $level
     */
    public function __construct(
        private string $root,
        private Console $console,
        ?Closure $runner = null,
        private ?Launcher $launcher = null,
        private ?History $history = null,
        private ?Store $store = null,
        ?Closure $level = null,
        private ?AutostartHistory $refusals = null,
    ) {
        $this->runner = $runner ?? Supervisor::run(...);
        $this->level = $level ?? static fn (): ?string => Level::current();
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

        $level = ($this->level)();

        if (Level::elevated($level)) {
            $this->console->err(self::refusalMessage($level));
            $this->refusals?->record('serve recusado', 'nível de integridade ' . $level . ': o servidor não sobe elevado', false);

            return Status::FAILURE;
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

        $this->loopback = self::command($this->root, $port);
        $children = ['loopback' => $this->loopback];
        $network = $this->networkChild();

        if ($network !== null) {
            $children['rede'] = $network;
        }

        $this->console->out('PHPorto em http://' . self::HOST . ':' . $port . "  (Ctrl+C encerra)\n");

        return ($this->runner)($children, $this->root, $this->tick(...), $this->event(...), $this->respawn(...));
    }

    public static function refusalMessage(?string $level): string
    {
        return 'Recusado: este processo roda em nível de integridade ' . ($level ?? 'alto') . " (elevado).\n"
            . "O painel executa ações de administrador pelo UAC e, elevado, esse limite deixa de existir. O servidor não sobe.\n"
            . "Inicie por um terminal comum (não elevado) ou pela tarefa do Agendador: schtasks /Run /TN PHPorto\n"
            . "Uma sessão SSH de conta administradora é elevada: use a tarefa.\n";
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

    private function loopbackEvent(string $kind, ?int $code): void
    {
        match ($kind) {
            'exit' => $this->console->err('O servidor do loopback caiu' . ($code === null ? '' : ' (código ' . $code . ')') . ".
"),
            'restart' => $this->console->err('O servidor do loopback volta em instantes (tentativa ' . ($code ?? 0) . ").
"),
            'gaveup' => $this->console->err("O servidor do loopback não voltou depois de várias tentativas. Encerrando com falha.
"),
            default => null,
        };
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

    /**
     * @return list<string>|null
     */
    private function respawn(string $name): ?array
    {
        if ($name === 'loopback') {
            return $this->loopback;
        }

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
            $this->console->err('Rede: o listener de rede não volta: ' . $reason . ".
");
            $this->history?->record('listener não voltou', $reason, false);
        }

        return null;
    }

    private function event(string $name, string $kind, ?int $code): void
    {
        if ($name === 'loopback') {
            $this->loopbackEvent($kind, $code);

            return;
        }

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
            case 'restart':
                $this->console->err('Rede: o listener de rede volta em instantes (tentativa ' . ($code ?? 0) . ").
");
                $this->history?->record('listener reiniciando', 'tentativa ' . ($code ?? 0));

                break;
            case 'gaveup':
                $this->console->err("Rede: o listener de rede não voltou depois de várias tentativas. O loopback continua.
");
                $this->history?->record('listener desistiu', 'tentativas esgotadas', false);

                break;
            case 'stopped':
                $this->console->out("Rede desligada: listener de rede encerrado.\n");
                $this->history?->record('listener encerrado', 'rede desligada');

                break;
        }
    }
}
