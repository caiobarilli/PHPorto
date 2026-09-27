<?php

declare(strict_types=1);

/**
 * Gera o token de acesso do PHPorto e grava em PHPORTO_AUTH_TOKEN no .env.
 *
 * Rode na raiz do projeto:  php token.php
 *
 * Imprime o token na tela. Se o .env já tem um token, avisa e pede
 * confirmação antes de trocar — trocar derruba o acesso de quem estava dentro.
 *
 * É O ÚNICO LUGAR DO PROJETO QUE ESCREVE NO .env, e a exceção confirma a
 * regra: quem escreve é este script de linha de comando, rodado por quem é
 * dono da máquina. O processo web nunca escreve no .env. Isto não é permissão
 * para uma tela fazer o mesmo.
 */

use App\Config\EnvFile;

require __DIR__ . '/vendor/autoload.php';

/** A chave do .env. */
const PHPORTO_TOKEN_KEY = 'PHPORTO_AUTH_TOKEN';

/** Bytes aleatórios do token; em hexadecimal, o dobro de caracteres. */
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

// Troca por arquivo temporário e rename: uma escrita interrompida no meio não
// deixa o .env, que guarda credencial de banco, pela metade.
$temporario = $arquivo . '.tmp-' . bin2hex(random_bytes(4));

if (file_put_contents($temporario, EnvFile::with($conteudo, PHPORTO_TOKEN_KEY, $token)) === false
    || !rename($temporario, $arquivo)) {
    @unlink($temporario);
    fwrite(STDERR, "Não foi possível gravar o .env. Nada mudou.\n");
    exit(1);
}

echo "\nToken gravado em " . PHPORTO_TOKEN_KEY . " no .env:\n\n    {$token}\n\n";
echo "No navegador, cole no campo de senha do diálogo; o usuário é ignorado.\n";
echo "Pela API:  curl -u :{$token} http://127.0.0.1:4001/api/executions\n";
