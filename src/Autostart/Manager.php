<?php

declare(strict_types=1);

namespace App\Autostart;

use App\Win\PsResult;
use App\Win\WinAction;
use Closure;
use RuntimeException;
use Throwable;

final class Manager
{
    public const TIMEOUT_S = 120;

    /** @var Closure(WinAction, array<string, string|int|bool>, int): PsResult */
    private Closure $dispatch;

    /** @var Closure(int): bool */
    private Closure $responds;

    /** @var Closure(): ?string */
    private Closure $level;

    /**
     * @param Closure(WinAction, array<string, string|int|bool>, int): PsResult $dispatch
     * @param (Closure(int): bool)|null $responds
     * @param (Closure(): ?string)|null $level
     */
    public function __construct(
        private Store $store,
        private Task $task,
        Closure $dispatch,
        private History $history,
        private string $root,
        private string $phpBinary,
        private ?Identity $identity,
        ?Closure $responds = null,
        ?Closure $level = null,
        private ?string $xmlDir = null,
    ) {
        $this->dispatch = $dispatch;
        $this->level = $level ?? static fn (): ?string => Level::current();
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

    public function xmlPath(): string
    {
        return ($this->xmlDir ?? rtrim($this->root, '\\/') . DIRECTORY_SEPARATOR . 'files') . DIRECTORY_SEPARATOR . TaskXml::FILE;
    }

    public function enable(): Result
    {
        $identity = $this->identity;

        if ($identity === null) {
            return new Result(false, ['Não foi possível ler o SID da conta deste processo (whoami /user). Nada foi criado.']);
        }

        if (preg_match('/' . WinAction::NET_FIREWALL_PROGRAM_PATTERN . '/', $this->phpBinary) !== 1) {
            return new Result(false, ['O php.exe deste processo não é um caminho local do Windows aceito. Nada foi criado.']);
        }

        if (preg_match('/' . WinAction::AUTOSTART_SCRIPT_PATTERN . '/', $this->script()) !== 1) {
            return new Result(false, ['O arquivo phporto não está numa pasta local do Windows aceita. Nada foi criado.']);
        }

        $observed = $this->task->observe();

        if ($observed->matches($identity->sid, $this->phpBinary, $this->script())) {
            $this->store->set(true);

            return new Result(true, ['O início com o Windows já estava ligado: a tarefa ' . Task::NAME . ' está como o esperado. Nada a fazer.']);
        }

        $lines = [];

        if ($observed->present()) {
            $logon = $observed->logon() ?? 'desconhecido';
            $done = $this->run(['SubAction' => 'off']);

            if (!$done->ok) {
                $this->history->record('substituir falhou', $done->message, false);

                return new Result(false, ['Não foi possível remover a tarefa antiga (logon ' . $logon . ') para substituí-la: ' . $done->message]);
            }

            $this->store->set(false);
            $this->history->record('tarefa antiga removida', 'tarefa ' . Task::NAME . ' com logon ' . $logon . ' removida para ser substituída');
            $lines[] = 'A tarefa antiga (logon ' . $logon . ') foi removida para ser substituída. O servidor que estiver rodando não foi parado.';
        }

        $path = $this->xmlPath();

        try {
            $this->writeXml($path, TaskXml::build($identity->sid, $this->phpBinary, $this->script()));
        } catch (Throwable $e) {
            $this->history->record('gerar o XML falhou', $e->getMessage(), false);

            return new Result(false, [...$lines, 'Não foi possível gravar o XML da tarefa em ' . $path . ': ' . $e->getMessage()]);
        }

        $this->history->record('XML da tarefa gerado', $path);

        return new Result(true, [
            ...$lines,
            'A tarefa ainda NÃO está criada. Gerei o XML em ' . $path . ' para a conta ' . $identity->name . '.',
            'A senha da conta é pedida só pelo Windows, nunca pelo PHPorto: abra o Agendador de Tarefas (taskschd.msc) como administrador, use Ação, Importar Tarefa, escolha esse arquivo e confirme. O diálogo do Windows pede a senha.',
            'Depois confira com php phporto autostart status ou na /config: a tarefa deve aparecer com logon Password e o servidor em nível médio.',
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

    public function restart(): Result
    {
        if ($this->task->observe()->absent()) {
            return new Result(false, ['Não há tarefa ' . Task::NAME . ' no Agendador. Ligue o início com o Windows antes de reiniciar pela tarefa.']);
        }

        $done = $this->run(['SubAction' => 'restart', 'Script' => $this->script()]);

        if (!$done->ok) {
            $this->history->record('reiniciar falhou', $done->message, false);

            return new Result(false, ['Não foi possível reiniciar o servidor pela tarefa: ' . $done->message]);
        }

        $this->history->record('reiniciado pela tarefa', 'servidor encerrado e tarefa ' . Task::NAME . ' iniciada');

        return new Result(true, ['Reinício pedido: o servidor foi encerrado e a tarefa ' . Task::NAME . ' o inicia de novo. O painel fica fora do ar por alguns segundos; recarregue a página.']);
    }

    public function report(int $port): Report
    {
        $observed = $this->task->observe();

        return new Report(
            $this->store->enabled(),
            $observed,
            $this->identity !== null && $observed->matches($this->identity->sid, $this->phpBinary, $this->script()),
            ($this->responds)($port),
            ($this->level)(),
        );
    }

    private function writeXml(string $path, string $content): void
    {
        $dir = dirname($path);

        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException('a pasta ' . $dir . ' não existe e não pôde ser criada');
        }

        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (file_put_contents($tmp, $content) === false || !@rename($tmp, $path)) {
            @unlink($tmp);

            throw new RuntimeException('a gravação falhou');
        }
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
