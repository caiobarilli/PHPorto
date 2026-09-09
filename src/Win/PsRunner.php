<?php

declare(strict_types=1);

namespace App\Win;

use RuntimeException;

/**
 * Executa um .ps1 no PowerShell do Windows e devolve a saída, com teto.
 *
 * POR QUE ISTO NÃO PASSA PELO WSL: o PHP já roda no Windows. Medido nesta
 * máquina, com a VM do WSL quente: 191/198/242 ms chamando o powershell.exe
 * direto, contra 397/405/457 ms atravessando o interop da Debian — mais os
 * ~4543 ms de acordar a VM quando ela está fria. A volta Windows -> Linux ->
 * Windows não devolvia nada em troca, porque o canal com o processo elevado é
 * por arquivo de qualquer forma.
 *
 * O QUE ESTA CLASSE NÃO FAZ: elevar. Ela roda no mesmo token do php -S, que é
 * integridade Média. Quem eleva é a Elevation, e o resultado dessa elevação
 * não volta por aqui — volta por arquivo.
 */
final class PsRunner
{
    /**
     * Teto de saída de UMA execução.
     *
     * O Runner do WSL ainda lê a saída inteira com file_get_contents, e um
     * `find /` derruba o PHP no memory_limit antes de chegar ao banco. Essa
     * dívida é de lá e segue de lá; este motor é código novo e nasce com o
     * teto, porque escrevê-lo aqui custa uma constante e uma leitura em
     * pedaços. O valor é 1 MiB: um audit ou um relatório de tshark cabem com
     * folga, e o que passar disso a pessoa não vai ler numa página.
     */
    public const MAX_OUTPUT_BYTES = 1048576;

    /** Tamanho de cada pedaço lido do arquivo de saída. */
    private const CHUNK_BYTES = 65536;

    /** Intervalo de sondagem do processo, igual ao do Runner do WSL. */
    private const POLL_US = 80000;

    /** Espera pelo término após o sinal, antes de partir para o taskkill. */
    private const KILL_GRACE_US = 200000;

    public function __construct(
        private readonly string $filesDir,
    ) {
    }

    /**
     * Roda o script no caminho dado e espera o fim, ou o timeout.
     *
     * O script é um ARQUIVO e o que vai para a linha de comando é só o caminho
     * dele — mesma regra do cmd.sh no lado WSL. Ver PsScriptBuilder.
     */
    public function run(string $scriptPath, int $timeoutSeconds): PsResult
    {
        if (!is_file($scriptPath)) {
            throw new RuntimeException("Script não encontrado: {$scriptPath}");
        }

        $outFile = $this->filesDir . DIRECTORY_SEPARATOR . 'ps-stdout.tmp';
        $errFile = $this->filesDir . DIRECTORY_SEPARATOR . 'ps-stderr.tmp';

        file_put_contents($outFile, '');
        file_put_contents($errFile, '');

        // bypass_shell pelo mesmo motivo do Runner do WSL: a forma de array do
        // proc_open aspeia TODO argumento no Windows, e o powershell.exe
        // deixaria de reconhecer os próprios flags. Continua seguro porque
        // nenhum texto de usuário está nesta string — só flags fixos e um
        // caminho que este código escreveu.
        //
        // -NoProfile é economia e previsibilidade: o perfil do usuário pode
        // imprimir coisa na saída e mudar o que este código lê.
        $cmdLine = 'powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -File '
            . self::arg($scriptPath);

        $descriptors = [
            0 => ['file', self::nullDevice(), 'r'],
            1 => ['file', $outFile, 'a'],
            2 => ['file', $errFile, 'a'],
        ];

        $started = microtime(true);
        $pipes   = [];
        $proc    = @proc_open($cmdLine, $descriptors, $pipes, $this->filesDir, null, ['bypass_shell' => true]);

        if (!is_resource($proc)) {
            return new PsResult("[phporto] falha ao iniciar powershell.exe\n", null, false, 0);
        }

        $exitCode = null;
        $timedOut = false;

        while (true) {
            $status = proc_get_status($proc);

            if ($status['running'] === false) {
                $exitCode = $status['exitcode'] < 0 ? null : $status['exitcode'];
                break;
            }

            if ((microtime(true) - $started) >= $timeoutSeconds) {
                $timedOut = true;
                self::kill($proc, $status['pid']);
                break;
            }

            usleep(self::POLL_US);
        }

        proc_close($proc);
        $durationMs = (int) round((microtime(true) - $started) * 1000);

        [$output, $truncated] = self::readBounded($outFile);

        // stderr vem depois porque o script gerado funde os fluxos com *>&1 e
        // só sobra aqui o que o próprio host emitir — erro de ligação de
        // parâmetro, por exemplo.
        [$stray, $strayTruncated] = self::readBounded($errFile, max(0, self::MAX_OUTPUT_BYTES - strlen($output)));
        if (trim($stray) !== '') {
            $output .= $stray;
        }

        if ($timedOut) {
            $output = self::withNewline($output)
                . sprintf('[phporto] TIMEOUT: %ds estourados, processo morto.', $timeoutSeconds) . "\n";
        }

        @unlink($outFile);
        @unlink($errFile);

        return new PsResult($output, $exitCode, $timedOut, $durationMs, $truncated || $strayTruncated);
    }

    /**
     * Lê um arquivo até o teto, em pedaços, e diz se cortou.
     *
     * Em PEDAÇOS e não com file_get_contents porque o ponto do teto é não
     * carregar o arquivo inteiro na memória: ler tudo e depois cortar já
     * teria estourado o memory_limit, que é exatamente a falha que este teto
     * existe para evitar.
     *
     * O aviso de corte entra na própria saída, não num campo separado: quem
     * lê a saída na tela precisa ver ali que ela não está inteira.
     *
     * @return array{0: string, 1: bool}
     */
    public static function readBounded(string $path, ?int $limit = null): array
    {
        $limit = $limit ?? self::MAX_OUTPUT_BYTES;

        if ($limit <= 0) {
            return ['', true];
        }

        // is_file() antes de filesize(): num arquivo ausente o filesize()
        // emite warning, e o "@" não o silencia sob o error handler do
        // PHPUnit — a suíte acusaria um aviso num caminho que é normal
        // (nada escrito ainda).
        if (!is_file($path)) {
            return ['', false];
        }

        $tamanho = filesize($path);

        if (!is_int($tamanho)) {
            return ['', false];
        }

        $fh = @fopen($path, 'rb');

        if ($fh === false) {
            return ['', false];
        }

        $buffer = '';

        while (true) {
            // O quanto falta é calculado ANTES e testado contra 1, e não só
            // usado dentro do min(): assim a invariante "o pedaço pedido é ao
            // menos um byte" fica escrita, em vez de depender de o leitor
            // deduzir a condição do while.
            $falta = $limit - strlen($buffer);

            if ($falta < 1) {
                break;
            }

            $chunk = fread($fh, min(self::CHUNK_BYTES, $falta));

            if (!is_string($chunk) || $chunk === '') {
                break;
            }

            $buffer .= $chunk;
        }

        fclose($fh);

        // O corte se decide pelo TAMANHO do arquivo, não por sondar o
        // descritor: é uma chamada só, e não depende de quantas leituras
        // foram necessárias para chegar ao teto.
        $truncated = $tamanho > $limit;

        $buffer = self::toUtf8(PsScriptBuilder::stripBom($buffer));

        if ($truncated) {
            $buffer = self::withNewline($buffer) . sprintf(
                '[phporto] SAÍDA CORTADA no teto de %d bytes (o comando gerou %d).',
                $limit,
                $tamanho
            ) . "\n";
        }

        return [$buffer, $truncated];
    }

    /**
     * Garante UTF-8 válido. Sem isso o htmlspecialchars() devolve string
     * vazia e a saída SOME da tela — o mesmo motivo do toUtf8 do Runner do
     * WSL, e a mesma solução.
     */
    public static function toUtf8(string $s): string
    {
        if ($s === '') {
            return '';
        }

        if (!mb_check_encoding($s, 'UTF-8')) {
            // O PowerShell 5.1 sem BOM na saída cai no codepage ANSI da
            // máquina; no Brasil isso é Windows-1252.
            $conv = @iconv('Windows-1252', 'UTF-8//IGNORE', $s);
            $s    = $conv === false ? mb_convert_encoding($s, 'UTF-8', 'UTF-8') : $conv;
        }

        return str_replace("\r\n", "\n", $s);
    }

    private static function withNewline(string $s): string
    {
        return $s === '' || str_ends_with($s, "\n") ? $s : $s . "\n";
    }

    /** Aspas só quando o valor tem espaço, como no Runner do WSL. */
    private static function arg(string $value): string
    {
        return preg_match('/\s/', $value) === 1 ? '"' . $value . '"' : $value;
    }

    private static function nullDevice(): string
    {
        return DIRECTORY_SEPARATOR !== '/' ? 'NUL' : '/dev/null';
    }

    /**
     * Mata o powershell.exe e o que ele pendurou embaixo.
     *
     * O /T é o que alcança os filhos — um winget ou um tshark iniciado pelo
     * script sobreviveria à morte só do pai. Vale a ressalva medida: isto só
     * funciona contra processo do MESMO nível de integridade. Contra o worker
     * elevado, o taskkill devolve "Acesso negado" — por isso o cancelamento
     * de ação elevada é por arquivo, e não por aqui.
     *
     * @param resource $proc
     */
    private static function kill($proc, int $pid): void
    {
        @proc_terminate($proc, 9);
        usleep(self::KILL_GRACE_US);

        $status = proc_get_status($proc);

        if ($status['running'] === true && $pid > 0 && DIRECTORY_SEPARATOR !== '/') {
            @exec('taskkill /F /T /PID ' . $pid);
        }
    }
}
