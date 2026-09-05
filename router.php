<?php

declare(strict_types=1);

/**
 * Router do servidor embutido do PHP.
 *
 * Uso:
 *   php -S 127.0.0.1:4001 router.php
 *
 * Sem ele, o servidor embutido serve os estáticos do diretório — dotfiles
 * inclusive. `GET /.env` devolveria o arquivo inteiro, e `GET /.git/config`
 * devolveria a URL do remote. Este script devolve 404 antes disso e delega
 * todo o resto ao comportamento normal do servidor.
 *
 * A regra bloqueia QUALQUER segmento iniciado por ponto, não apenas o último:
 * em `/.git/config` o último segmento é `config`, que não começa com ponto —
 * uma regra baseada só no último segmento serviria o arquivo.
 *
 * O router não substitui a trava principal, que é escutar em 127.0.0.1.
 */

$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$path       = is_string($requestUri) ? (parse_url($requestUri, PHP_URL_PATH) ?: '/') : '/';

if (preg_match('#(^|/)\.#', $path) === 1) {
    http_response_code(404);

    return true;
}

// false = o servidor embutido trata a requisição normalmente (index.php ou estático).
return false;
