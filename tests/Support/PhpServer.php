<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * Um php -S de verdade, numa porta livre, com o ambiente dado, para teste.
 *
 * O banco é um .sqlite temporário próprio, apagado no stop().
 */
final class PhpServer
{
    /** @var resource */
    private $proc;

    /** @param resource $proc */
    private function __construct($proc, public readonly int $port, private readonly string $db)
    {
        $this->proc = $proc;
    }

    /**
     * Sobe o servidor e espera ele aceitar conexão.
     *
     * Recebe as variáveis de ambiente a acrescentar. Devolve o servidor.
     *
     * @param array<string, string> $env
     */
    public static function start(array $env): self
    {
        $sonda = stream_socket_server('tcp://127.0.0.1:0');
        if ($sonda === false) {
            throw new RuntimeException('sem porta livre');
        }
        $nome  = (string) stream_socket_get_name($sonda, false);
        $porta = (int) substr($nome, (int) strrpos($nome, ':') + 1);
        fclose($sonda);

        $db = str_replace('\\', '/', sys_get_temp_dir()) . '/phporto_http_' . $porta . '.sqlite';

        $proc = proc_open(
            [PHP_BINARY, '-d', 'variables_order=EGPCS', '-S', '127.0.0.1:' . $porta, '-t', 'public', 'public/router.php'],
            [0 => ['file', 'NUL', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']],
            $pipes,
            dirname(__DIR__, 2),
            [...getenv(), 'SQLITE_PATH' => $db, ...$env],
        );

        if (!is_resource($proc)) {
            throw new RuntimeException('php -S não subiu');
        }

        for ($i = 0; $i < 50; $i++) {
            $s = @fsockopen('127.0.0.1', $porta, $errno, $errstr, 0.1);
            if (is_resource($s)) {
                fclose($s);

                return new self($proc, $porta, $db);
            }
            usleep(100000);
        }

        proc_terminate($proc);

        throw new RuntimeException('php -S não respondeu na porta ' . $porta);
    }

    /**
     * Faz uma requisição sem seguir redirecionamento.
     *
     * Recebe o método, o caminho, a senha do Basic (null = sem credencial) e o
     * corpo de formulário. Devolve [status, cabeçalhos, corpo].
     *
     * @param array<string, string> $form
     *
     * @return array{0: int, 1: list<string>, 2: string}
     */
    public function request(string $method, string $path, ?string $password, array $form = []): array
    {
        $cabecalhos = $password === null ? '' : 'Authorization: Basic ' . base64_encode('qualquer:' . $password) . "\r\n";
        $corpo      = '';

        if ($form !== []) {
            $corpo = http_build_query($form);
            $cabecalhos .= "Content-Type: application/x-www-form-urlencoded\r\n";
        }

        $resposta = file_get_contents('http://127.0.0.1:' . $this->port . $path, false, stream_context_create([
            'http' => [
                'method'          => $method,
                'header'          => $cabecalhos,
                'content'         => $corpo,
                'ignore_errors'   => true,
                'follow_location' => 0,
                'timeout'         => 30,
            ],
        ]));
        $linhas = http_get_last_response_headers() ?? [];

        preg_match('#^HTTP/\S+ (\d{3})#', $linhas[0] ?? '', $m);

        return [(int) ($m[1] ?? 0), $linhas, is_string($resposta) ? $resposta : ''];
    }

    /** Derruba o servidor e apaga o banco dele. */
    public function stop(): void
    {
        proc_terminate($this->proc);
        proc_close($this->proc);

        if (is_file($this->db)) {
            @unlink($this->db);
        }
    }
}
