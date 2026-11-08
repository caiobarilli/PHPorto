<?php

declare(strict_types=1);

namespace App\Autostart;

use App\Win\PsResult;
use App\Win\WinAction;
use Closure;
use InvalidArgumentException;
use RuntimeException;

final class Manager
{
    public const TIMEOUT_S = 120;

    /** @var Closure(WinAction, array<string, string|int|bool>, int): PsResult */
    private Closure $dispatch;

    /** @var Closure(int): bool */
    private Closure $responds;

    /**
     * @param Closure(WinAction, array<string, string|int|bool>, int): PsResult $dispatch
     * @param (Closure(int): bool)|null $responds
     */
    public function __construct(
        private Store $store,
        private Task $task,
        Closure $dispatch,
        private History $history,
        private string $root,
        private string $phpBinary,
        private string $userName,
        ?Closure $responds = null,
    ) {
        $this->dispatch = $dispatch;
        $this->responds = $responds ?? static function (int $port): bool {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.5);

            if (!is_resource($socket)) {
                return false;
            }

            fclose($socket);

            return true;
        };
    }

    public function script(): string
    {
        return rtrim($this->root, '\\/') . '\\phporto';
    }

    public function enable(): Result
    {
        try {
            $params = WinAction::Autostart->validate([
                'SubAction' => 'on',
                'UserName' => $this->userName,
                'Program' => $this->phpBinary,
                'Script' => $this->script(),
            ]);
        } catch (InvalidArgumentException $e) {
            return new Result(false, [$e->getMessage()]);
        }

        $observed = $this->task->observe();

        if ($observed->matches($this->userName, $this->phpBinary, $this->script())) {
            $this->store->set(true);

            return new Result(true, ['O início com o Windows já estava ligado. Nada a fazer.']);
        }

        $done = $this->run($params);

        if (!$done->ok) {
            $this->history->record('ligar falhou', $done->message, false);

            return new Result(false, ['Não foi possível criar a tarefa do Agendador: ' . $done->message]);
        }

        $this->store->set(true);
        $this->history->record('ligado', 'tarefa ' . Task::NAME . ' criada (na inicialização, conta do próprio usuário, logon S4U)');

        return new Result(true, [
            'Início com o Windows ligado: a tarefa ' . Task::NAME . ' sobe o php phporto serve na inicialização, como ' . $this->userName . ', sem nível elevado.',
            'Isso não liga a rede privada, e a rede privada não liga isto.',
        ]);
    }

    public function disable(): Result
    {
        $observed = $this->task->observe();

        if ($observed->absent()) {
            $this->store->set(false);

            return new Result(true, ['O início com o Windows já estava desligado. Nada a fazer.']);
        }

        $done = $this->run(['SubAction' => 'off']);

        if (!$done->ok) {
            $this->history->record('desligar falhou', $done->message, false);

            return new Result(false, ['Não foi possível remover a tarefa do Agendador: ' . $done->message, 'O estado continua ligado.']);
        }

        $this->store->set(false);
        $this->history->record('desligado', 'tarefa ' . Task::NAME . ' removida');

        return new Result(true, ['Início com o Windows desligado. O servidor que estiver rodando não foi parado.']);
    }

    public function stopNow(): Result
    {
        $done = $this->run(['SubAction' => 'stop', 'Script' => $this->script()]);

        if (!$done->ok) {
            $this->history->record('parar agora falhou', $done->message, false);

            return new Result(false, ['Não foi possível parar o servidor: ' . $done->message]);
        }

        $this->history->record('parado agora', 'servidor da tarefa encerrado');

        return new Result(true, ['Servidor encerrado.']);
    }

    public function report(int $port): Report
    {
        $observed = $this->task->observe();

        return new Report(
            $this->store->enabled(),
            $observed,
            $observed->matches($this->userName, $this->phpBinary, $this->script()),
            ($this->responds)($port),
        );
    }

    /**
     * @param array<string, string|int|bool> $params
     */
    private function run(array $params): Done
    {
        try {
            $result = ($this->dispatch)(WinAction::Autostart, $params, self::TIMEOUT_S);
        } catch (RuntimeException $e) {
            return new Done(false, $e->getMessage());
        }

        if ($result->timedOut) {
            return new Done(false, 'a ação passou do prazo');
        }

        if (preg_match('/^\[ ERROR \] (.*)$/m', $result->output, $m) === 1) {
            return new Done(false, trim($m[1]));
        }

        if ($result->exitCode === 0 && str_contains($result->output, '[ OK ]')) {
            return new Done(true, 'ok');
        }

        return new Done(false, 'a ação não confirmou o resultado');
    }
}
