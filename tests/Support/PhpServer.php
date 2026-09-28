<?php

declare(strict_types=1);

namespace Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Um php -S de verdade, numa porta livre, com .env próprio, para teste.
 *
 * Sobe numa raiz temporária com cópia de public/ e do src/bootstrap.php, um
 * vendor/autoload.php que carrega o do projeto, storage/ e files/ vazios, o
 * banco .sqlite e o .env dado. Nada do .env, do storage/ nem do files/ desta
 * máquina entra. A raiz é apagada no stop().
 */
final class PhpServer
{
    /** @var resource */
    private $proc;

    /** @param resource $proc */
    private function __construct($proc, public readonly int $port, private readonly string $root)
    {
        $this->proc = $proc;
    }

    /**
     * Sobe o servidor e espera ele aceitar conexão.
     *
     * Recebe as chaves do .env do servidor (valor vazio vira CHAVE=). Essas
     * chaves saem do ambiente herdado, para o .env valer. Devolve o servidor.
     *
     * @param array<string, string> $dotenv
     */
    public static function start(array $dotenv): self
    {
        $sonda = stream_socket_server('tcp://127.0.0.1:0');
        if ($sonda === false) {
            throw new RuntimeException('sem porta livre');
        }
        $nome  = (string) stream_socket_get_name($sonda, false);
        $porta = (int) substr($nome, (int) strrpos($nome, ':') + 1);
        fclose($sonda);

        $root = self::buildRoot(
            str_replace('\\', '/', sys_get_temp_dir()) . '/phporto_http_' . $porta,
            $dotenv,
        );

        $proc = proc_open(
            [PHP_BINARY, '-d', 'variables_order=EGPCS', '-S', '127.0.0.1:' . $porta, '-t', 'public', 'public/router.php'],
            [0 => ['file', 'NUL', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']],
            $pipes,
            $root,
            array_diff_key(getenv(), $dotenv, ['SQLITE_PATH' => '']),
        );

        if (!is_resource($proc)) {
            self::remove($root);

            throw new RuntimeException('php -S não subiu');
        }

        for ($i = 0; $i < 50; $i++) {
            $s = @fsockopen('127.0.0.1', $porta, $errno, $errstr, 0.1);
            if (is_resource($s)) {
                fclose($s);

                return new self($proc, $porta, $root);
            }
            usleep(100000);
        }

        proc_terminate($proc);
        proc_close($proc);
        self::remove($root);

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

    /** Derruba o servidor e apaga a raiz temporária dele. */
    public function stop(): void
    {
        proc_terminate($this->proc);
        proc_close($this->proc);
        self::remove($this->root);
    }

    /**
     * Monta a raiz temporária do servidor.
     *
     * Recebe o caminho da raiz e as chaves do .env. Devolve o caminho.
     *
     * @param array<string, string> $dotenv
     */
    private static function buildRoot(string $root, array $dotenv): string
    {
        $projeto = dirname(__DIR__, 2);

        self::remove($root);

        foreach (['public', 'src', 'vendor', 'storage', 'files'] as $pasta) {
            if (!mkdir($root . '/' . $pasta, 0o775, true)) {
                throw new RuntimeException('não criou ' . $root . '/' . $pasta);
            }
        }

        foreach (['public/index.php', 'public/router.php', 'src/bootstrap.php'] as $arquivo) {
            if (!copy($projeto . '/' . $arquivo, $root . '/' . $arquivo)) {
                throw new RuntimeException('não copiou ' . $arquivo);
            }
        }

        file_put_contents(
            $root . '/vendor/autoload.php',
            "<?php\n\nreturn require " . var_export($projeto . '/vendor/autoload.php', true) . ";\n",
        );

        $linhas = [];
        foreach ([...$dotenv, 'SQLITE_PATH' => $root . '/database.sqlite'] as $chave => $valor) {
            if (preg_match('/^[A-Z][A-Z0-9_]*$/', $chave) !== 1 || preg_match('/["$\\\\\r\n]/', $valor) === 1) {
                throw new RuntimeException('chave ou valor que o .env de teste não escreve: ' . $chave);
            }
            $linhas[] = $valor === '' ? $chave . '=' : $chave . '="' . $valor . '"';
        }
        file_put_contents($root . '/.env', implode("\n", $linhas) . "\n");

        return $root;
    }

    /** Apaga a raiz temporária, se existir. Recebe o caminho. */
    private static function remove(string $root): void
    {
        if (!is_dir($root)) {
            return;
        }

        $itens = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($itens as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($root);
    }
}
