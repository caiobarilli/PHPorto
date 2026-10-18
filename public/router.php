<?php

declare(strict_types=1);

const PHPORTO_ROUTES = ['/', '/config', '/wsl', '/win', '/hyperv'];

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

if (str_contains($path, "\0") || stripos($path, '%00') !== false) {
    return $refuse();
}

$path = rawurldecode($path);

if (str_contains($path, "\0")) {
    return $refuse();
}

$path = str_replace('\\', '/', $path);

if (preg_match('#(^|/)\.#', $path) === 1) {
    return $refuse();
}

if ($path !== '/' && str_ends_with($path, '/')) {
    $path = rtrim($path, '/');
}

if (in_array($path, PHPORTO_ROUTES, true) || str_starts_with($path, PHPORTO_API_PREFIX)) {
    $_SERVER['PHPORTO_PATH'] = $path;
    require __DIR__ . '/index.php';

    return true;
}

return $refuse();
