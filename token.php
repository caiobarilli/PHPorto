<?php

declare(strict_types=1);

use App\Config\EnvFile;

require __DIR__ . '/vendor/autoload.php';

const PHPORTO_TOKEN_KEY = 'PHPORTO_AUTH_TOKEN';

const PHPORTO_TOKEN_BYTES = 32;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$arquivo = __DIR__ . DIRECTORY_SEPARATOR . '.env';

if (!is_file($arquivo)) {
    fwrite(STDERR, "Não há .env em " . __DIR__ . ". Copie o .env.example para .env e rode de novo.\n");
    exit(1);
}

$conteudo = file_get_contents($arquivo);

if (!is_string($conteudo)) {
    fwrite(STDERR, "Não foi possível ler o .env.\n");
    exit(1);
}

$atual = EnvFile::value($conteudo, PHPORTO_TOKEN_KEY);

if ($atual !== null && $atual !== '') {
    echo "O .env já tem um " . PHPORTO_TOKEN_KEY . ".\n";
    echo "Trocar derruba o acesso de quem estiver usando o token atual.\n";
    echo "Substituir? [s/N] ";

    $resposta = fgets(STDIN);
    $resposta = strtolower(trim(is_string($resposta) ? $resposta : ''));

    if ($resposta !== 's' && $resposta !== 'sim') {
        echo "Nada mudou.\n";
        exit(1);
    }
}

$token = bin2hex(random_bytes(PHPORTO_TOKEN_BYTES));

$temporario = $arquivo . '.tmp-' . bin2hex(random_bytes(4));

if (file_put_contents($temporario, EnvFile::with($conteudo, PHPORTO_TOKEN_KEY, $token)) === false
    || !rename($temporario, $arquivo)) {
    @unlink($temporario);
    fwrite(STDERR, "Não foi possível gravar o .env. Nada mudou.\n");
    exit(1);
}

echo "\nToken gravado em " . PHPORTO_TOKEN_KEY . " no .env:\n\n    {$token}\n\n";
echo "No navegador, cole no formulário de entrada.\n";
echo "Pela API:  curl -u :{$token} http://127.0.0.1:4001/api/executions\n";
