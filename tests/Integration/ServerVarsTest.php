<?php

declare(strict_types=1);

use Tests\Support\PhpServer;

it('o php -S não define SERVER_ADDR, define SERVER_NAME como o endereço de escuta e ignora o Host forjado', function () {
    $root = str_replace('\\', '/', sys_get_temp_dir()) . '/phporto_servervars_' . bin2hex(random_bytes(4));
    mkdir($root, 0777, true);
    file_put_contents($root . '/router.php', '<?php header("Content-Type: application/json"); echo json_encode(array_intersect_key($_SERVER, array_flip(["SERVER_ADDR", "SERVER_NAME", "SERVER_PORT", "HTTP_HOST", "REMOTE_ADDR"])));');

    $porta = PhpServer::freePort();
    $nulo = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
    $proc = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $porta, '-t', $root, $root . '/router.php'],
        [0 => ['file', $nulo, 'r'], 1 => ['file', $nulo, 'w'], 2 => ['file', $nulo, 'w']],
        $pipes,
        $root,
    );
    expect(is_resource($proc))->toBeTrue();

    try {
        $pronto = false;
        set_error_handler(static fn (): bool => true);
        for ($i = 0; $i < 50 && !$pronto; $i++) {
            $s = @fsockopen('127.0.0.1', $porta, $errno, $errstr, 0.1);
            if (is_resource($s)) {
                fclose($s);
                $pronto = true;
            } else {
                usleep(100000);
            }
        }
        restore_error_handler();
        expect($pronto)->toBeTrue();

        $ler = static function (string $host) use ($porta): array {
            $corpo = file_get_contents('http://127.0.0.1:' . $porta . '/', false, stream_context_create([
                'http' => ['header' => 'Host: ' . $host . "\r\n", 'timeout' => 10],
            ]));

            return json_decode((string) $corpo, true, 512, JSON_THROW_ON_ERROR);
        };

        $normal = $ler('127.0.0.1:' . $porta);
        $forjado = $ler('192.168.1.20:' . $porta);

        expect($normal)->not->toHaveKey('SERVER_ADDR')
            ->and($normal['SERVER_NAME'])->toBe('127.0.0.1')
            ->and($normal['SERVER_PORT'])->toBe((string) $porta)
            ->and($forjado)->not->toHaveKey('SERVER_ADDR')
            ->and($forjado['HTTP_HOST'])->toBe('192.168.1.20:' . $porta)
            ->and($forjado['SERVER_NAME'])->toBe('127.0.0.1');
    } finally {
        proc_terminate($proc);
        proc_close($proc);
        PhpServer::removeRoot($root);
    }
});
