<?php

declare(strict_types=1);

namespace App\Wsl;

use RuntimeException;

/**
 * Executa um script dentro do WSL e devolve stdout+stderr juntos, na ordem em
 * que saíram, mais o código de saída.
 *
 * Cada decisão aqui está documentada em .claude/skills/php-wsl-interop.md e
 * foi medida, não inferida.
 *
 * Ler getenv() nesta classe é exceção deliberada à regra "só o Config lê env":
 * não é configuração, é HERANÇA do ambiente do processo — o wsl.exe precisa de
 * SystemRoot, PATH e companhia para sequer iniciar. A configuração da
 * ferramenta continua entrando pelo construtor.
 */
final class Runner
{
    /** Intervalo de sondagem do processo. */
    private const POLL_US = 80000;

    /** Espera pelo término após o sinal, antes de partir para o taskkill. */
    private const KILL_GRACE_US = 200000;

    /**
     * @param array{root: string, distro: string, timeout: int} $wsl
     */
    public function __construct(
        private readonly array $wsl,
        private readonly string $filesDir,
    ) {
    }

    /**
     * @param array<string, string> $extraEnv variáveis repassadas ao Linux
     *
     * @throws RuntimeException se a raiz de execução não estiver configurada.
     */
    public function run(string $scriptBody, array $extraEnv = []): RunResult
    {
        if ($this->wsl['root'] === '') {
            throw new RuntimeException(
                'PHPORTO_WSL_ROOT está vazio ou ausente no .env. Nada é executado: sem ele '
                . 'o comando rodaria no home do usuário do WSL, em silêncio.'
            );
        }

        $this->ensureDirectory($this->filesDir);

        $script  = $this->filesDir . DIRECTORY_SEPARATOR . 'cmd.sh';
        $outFile = $this->filesDir . DIRECTORY_SEPARATOR . 'stdout.tmp';
        $errFile = $this->filesDir . DIRECTORY_SEPARATOR . 'stderr.tmp';

        file_put_contents($script, ScriptBuilder::normalize($scriptBody));
        file_put_contents($outFile, '');
        file_put_contents($errFile, '');

        // A forma de ARRAY do proc_open envolve TODO argumento em aspas no
        // Windows, e o wsl.exe não reconhece os próprios flags aspeados: "-d"
        // vira argumento comum, cai no shell de login e dá
        // "command not found: -d". Então montamos a linha e usamos
        // bypass_shell, que chama CreateProcess direto no executável, sem
        // cmd.exe nem powershell.exe no meio.
        //
        // Continua seguro porque o texto do usuário NÃO está nesta string —
        // só o nome da distro e um caminho que este código controla.
        $cmdLine = 'wsl.exe -d ' . self::arg($this->wsl['distro'])
            . ' -- bash -l ' . self::arg(self::toWslPath($script));

        $descriptors = [
            0 => ['file', self::nullDevice(), 'r'],
            1 => ['file', $outFile, 'a'],
            2 => ['file', $errFile, 'a'],
        ];

        $vars = ['PHPORTO_WSL_ROOT' => $this->wsl['root']] + $extraEnv;

        // Herda o ambiente do processo: o wsl.exe precisa de SystemRoot, PATH
        // e companhia para sequer iniciar.
        $env             = getenv();
        $env['WSL_UTF8'] = '1'; // mensagens do próprio wsl.exe em UTF-8, não UTF-16
        foreach ($vars as $k => $v) {
            $env[$k] = $v;
        }

        // Sem WSLENV o Windows NÃO repassa nada disso para dentro do Linux, e a
        // variável chega vazia: um cd "$PHPORTO_WSL_ROOT" falhando com
        // "diretório não encontrado" parece erro de configuração do usuário.
        // Ao renomear qualquer variável, esta lista precisa ser atualizada junto.
        $inherited     = trim($env['WSLENV'] ?? '');
        $names         = implode(':', array_keys($vars));
        $env['WSLENV'] = $inherited === '' ? $names : $inherited . ':' . $names;

        $timeout = $this->wsl['timeout'];
        $started = microtime(true);

        $pipes = [];
        $proc  = @proc_open($cmdLine, $descriptors, $pipes, $this->filesDir, $env, ['bypass_shell' => true]);

        if (!is_resource($proc)) {
            return new RunResult("[phporto] falha ao iniciar wsl.exe\n", null, false, 0);
        }

        $exitCode = null;
        $timedOut = false;

        while (true) {
            $status = proc_get_status($proc);

            if ($status['running'] === false) {
                $exitCode = $status['exitcode'] < 0 ? null : $status['exitcode'];
                break;
            }

            if ((microtime(true) - $started) >= $timeout) {
                $timedOut = true;
                self::kill($proc, $status['pid']);
                break;
            }

            usleep(self::POLL_US);
        }

        proc_close($proc);
        $durationMs = (int) round((microtime(true) - $started) * 1000);

        $output = self::toUtf8((string) @file_get_contents($outFile));

        // stderr só recebe ruído do shell de login, antes de o exec 2>&1 valer.
        $stray = self::toUtf8((string) @file_get_contents($errFile));
        if (trim($stray) !== '') {
            $output .= $stray;
        }

        if ($timedOut) {
            if ($output !== '' && !str_ends_with($output, "\n")) {
                $output .= "\n";
            }
            $output .= sprintf('[phporto] TIMEOUT: %ds estourados, processo morto.', $timeout) . "\n";
        }

        @unlink($outFile);
        @unlink($errFile);

        return new RunResult($output, $exitCode, $timedOut, $durationMs);
    }

    /**
     * Converte um caminho Windows no caminho visto de dentro do WSL.
     * Ex.: C:\pasta\arquivo  ->  /mnt/c/pasta/arquivo
     */
    public static function toWslPath(string $winPath): string
    {
        $p = str_replace('\\', '/', $winPath);

        if (preg_match('~^([A-Za-z]):(/.*)?$~', $p, $m) === 1) {
            $p = '/mnt/' . strtolower($m[1]) . ($m[2] ?? '');
        }

        return $p;
    }

    /**
     * Garante que o texto é UTF-8 válido. Sem isso htmlspecialchars() devolve
     * string vazia e a saída SOME da tela. Também cobre o wsl.exe emitindo
     * mensagens próprias em UTF-16LE.
     */
    public static function toUtf8(string $s): string
    {
        if ($s === '') {
            return '';
        }

        // UTF-16LE tem NUL em posições ímpares; heurística barata e suficiente.
        $sample = substr($s, 0, 400);
        if (substr_count($sample, "\0") > strlen($sample) / 4) {
            $conv = @iconv('UTF-16LE', 'UTF-8//IGNORE', $s);
            if ($conv !== false) {
                $s = $conv;
            }
        }

        if (!mb_check_encoding($s, 'UTF-8')) {
            $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        }

        return str_replace("\r\n", "\n", $s);
    }

    /** Aspas só quando o valor tem espaço; o wsl.exe implica com aspas a mais. */
    private static function arg(string $value): string
    {
        return preg_match('/\s/', $value) === 1 ? '"' . $value . '"' : $value;
    }

    private static function nullDevice(): string
    {
        return self::isWindows() ? 'NUL' : '/dev/null';
    }

    private static function isWindows(): bool
    {
        return DIRECTORY_SEPARATOR !== '/';
    }

    /**
     * Mata o wsl.exe e tudo que ele pendurou embaixo.
     *
     * O /T do taskkill é o que faz a morte ATRAVESSAR a fronteira: matar só o
     * wsl.exe do lado Windows pode deixar o processo Linux pendurado, e isso é
     * falha silenciosa que só aparece quando a máquina fica lenta. Para
     * verificar à mão: rode um sleep longo, deixe o timeout disparar, e
     * confirme com `pgrep -a sleep` dentro da distro que nada sobrou.
     *
     * @param resource $proc
     */
    private static function kill($proc, int $pid): void
    {
        @proc_terminate($proc, 9);
        usleep(self::KILL_GRACE_US);

        $status = proc_get_status($proc);

        if ($status['running'] === true && $pid > 0 && self::isWindows()) {
            @exec('taskkill /F /T /PID ' . $pid);
        }
    }

    private function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new RuntimeException("Não foi possível criar a pasta de trabalho: {$dir}");
        }
    }
}
