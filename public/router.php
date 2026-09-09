<?php

declare(strict_types=1);

/**
 * Router do servidor embutido do PHP.
 *
 * Uso:
 *   php -S 127.0.0.1:4001 -t public public/router.php
 *
 * O -t public é a trava PRINCIPAL, e este arquivo é a segunda camada.
 *
 * A DIFERENÇA ENTRE AS DUAS IMPORTA. Enquanto o diretório servido era a raiz
 * do projeto, o código-fonte, o .env e o banco estavam todos dentro dele, e a
 * única defesa era uma regex acertar todos os casos — `/src/Config/Config.php`
 * não é dotfile, e passaria por qualquer regra escrita para dotfiles. Com
 * public/ como document root, esses caminhos não existem para o servidor: o
 * 404 vem de não haver o que servir, não de alguém ter lembrado de proibir.
 * Defende-se com geografia o que não se deve defender com expressão regular.
 *
 * O que sobra para este arquivo:
 *
 * 1. DOTFILES DENTRO DO public/. Hoje não há nenhum, mas o servidor embutido
 *    serviria qualquer um que aparecesse ali amanhã.
 *
 *    A ORDEM DAS OPERAÇÕES É PARTE DA CORREÇÃO. parse_url() devolve o caminho
 *    ainda percent-encoded: `/%2Eenv` continua `/%2Eenv`, não casa com uma
 *    regex que procura ponto literal, e passaria — mas o servidor decodifica
 *    depois. Por isso decodifica-se ANTES de casar. A barra invertida separa
 *    caminho no Windows, então `/..%5C.env` é a mesma família de desvio. O
 *    byte nulo é recusado sem análise: `%00` trunca caminho em camadas abaixo.
 *
 *    A regra bloqueia QUALQUER segmento iniciado por ponto, não apenas o
 *    último: em `/.git/config` o último segmento é `config`.
 *
 * 2. DESPACHO EXPLÍCITO. As rotas viram require do front controller em vez de
 *    depender do fallback implícito do servidor embutido para caminho
 *    inexistente. Como a ferramenta não tem nenhum arquivo estático (CSS e JS
 *    são embutidos na página), o desenho é LISTA DE PERMISSÃO: tudo que não é
 *    rota da aplicação é 404, sem precisar adivinhar o que mais existe na
 *    pasta de quem clonou.
 *
 * Nenhuma das duas substitui a trava de verdade, que é escutar em 127.0.0.1.
 */

/** Rotas exatas atendidas pelo front controller. */
const PHPORTO_ROUTES = ['/', '/config', '/wsl', '/win'];

/** Prefixo da API; o front controller decide se ela está ligada. */
const PHPORTO_API_PREFIX = '/api/';

$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$rawPath    = is_string($requestUri) ? $requestUri : '';

$parsed = parse_url($rawPath, PHP_URL_PATH);
$path   = is_string($parsed) && $parsed !== '' ? $parsed : '/';

$refuse = static function (): bool {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "404\n";

    return true;
};

// Byte nulo, cru ou codificado: recusa antes de qualquer normalização.
if (str_contains($path, "\0") || stripos($path, '%00') !== false) {
    return $refuse();
}

$path = rawurldecode($path);

if (str_contains($path, "\0")) {
    return $refuse();
}

// No Windows a barra invertida também separa caminho.
$path = str_replace('\\', '/', $path);

if (preg_match('#(^|/)\.#', $path) === 1) {
    return $refuse();
}

// Barra final é a mesma rota: /config/ e /config.
if ($path !== '/' && str_ends_with($path, '/')) {
    $path = rtrim($path, '/');
}

if (in_array($path, PHPORTO_ROUTES, true) || str_starts_with($path, PHPORTO_API_PREFIX)) {
    $_SERVER['PHPORTO_PATH'] = $path;
    require __DIR__ . '/index.php';

    return true;
}

return $refuse();
