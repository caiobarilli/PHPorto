<?php

declare(strict_types=1);

use Tests\Support\PhpServer;

it('php phporto serve, chamado de outra pasta, atende em 127.0.0.1 na porta do .env', function () {
    $porta = PhpServer::freePort();
    $root = PhpServer::makeRoot(
        str_replace('\\', '/', sys_get_temp_dir()) . '/phporto_cli_' . $porta,
        ['PHPORTO_AUTH_TOKEN' => 'segredo-de-teste', 'PHPORTO_PORT' => (string) $porta],
    );
    $nulo = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
    $proc = proc_open(
        [PHP_BINARY, $root . '/phporto', 'serve'],
        [0 => ['file', $nulo, 'r'], 1 => ['file', $nulo, 'w'], 2 => ['file', $nulo, 'w']],
        $pipes,
        sys_get_temp_dir(),
    );

    expect(is_resource($proc))->toBeTrue();

    $status = null;
    set_error_handler(static fn (): bool => true);

    try {
        for ($i = 0; $i < 60 && $status === null; $i++) {
            $s = fsockopen('127.0.0.1', $porta, $errno, $errstr, 0.2);

            if (is_resource($s)) {
                fwrite($s, "GET / HTTP/1.1\r\nHost: 127.0.0.1:{$porta}\r\nConnection: close\r\n\r\n");
                $status = (string) fgets($s);
                fclose($s);
            } else {
                usleep(150000);
            }
        }
    } finally {
        restore_error_handler();
        $info = proc_get_status($proc);

        if (DIRECTORY_SEPARATOR === '\\') {
            exec('taskkill /T /F /PID ' . (int) $info['pid'] . ' 2>NUL');
        } else {
            proc_terminate($proc);
        }

        proc_close($proc);
        PhpServer::removeRoot($root);
    }

    expect($status)->not->toBeNull()->and($status)->toMatch('#^HTTP/\S+ (200|303|401)#');
});
