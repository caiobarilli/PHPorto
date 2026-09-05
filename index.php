<?php

declare(strict_types=1);

/**
 * Endpoint HTTP da aplicação.
 *
 * POST /  body JSON { "entry": "..." }
 *   201 -> registrado
 *   400 -> valor inválido / body malformado
 *   409 -> valor duplicado
 *   500 -> erro interno
 *
 * OPTIONS / -> 204 (preflight CORS, sem corpo)
 * Outros métodos -> 405 (método não permitido)
 *
 * Agnóstico ao servidor de produção (Apache, Nginx+FPM ou php -S).
 */

use App\Exceptions\DuplicateEntryException;

/**
 * Responde JSON e encerra.
 *
 * @param array<string, mixed> $payload
 */
function respond(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Bootstrap (autoload, .env, config + factory lazy do serviço) ---
// Só lê env/config aqui — NÃO conecta no banco ainda, para que CORS e o
// preflight OPTIONS funcionem mesmo com o banco fora do ar.
try {
    $app = require __DIR__ . '/bootstrap.php';
} catch (Throwable $e) {
    error_log('[app] bootstrap falhou: ' . $e->getMessage());
    respond(500, ['success' => false, 'message' => 'Erro interno. Tente novamente.']);
}

/**
 * @var array{
 *     config: array{
 *         db_provider: string,
 *         cors_origin: string,
 *         mongo: array{uri: string, database: string, collection: string},
 *         mysql: array{host: string, port: string, database: string, user: string, password: string, table: string}
 *     },
 *     makeService: callable(): \App\Services\EntryService
 * } $app
 */
$config = $app['config'];

// --- CORS ---
$origin = $config['cors_origin'];
header('Access-Control-Allow-Origin: ' . $origin);
header('Vary: Origin');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Max-Age: 86400');

// Preflight — responde antes de tocar no banco.
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Só POST registra.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['success' => false, 'message' => 'Método não permitido.']);
}

// --- Lê e valida o corpo ---
$raw  = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);

if (!is_array($data) || !array_key_exists('entry', $data) || !is_string($data['entry'])) {
    respond(400, ['success' => false, 'message' => 'Valor inválido.']);
}

// --- Processa (aqui sim conecta no banco) ---
try {
    $service = ($app['makeService'])();
    $service->register($data['entry']);
    respond(201, ['success' => true, 'message' => 'Registro criado com sucesso.']);
} catch (InvalidArgumentException $e) {
    respond(400, ['success' => false, 'message' => 'Valor inválido.']);
} catch (DuplicateEntryException $e) {
    respond(409, ['success' => false, 'message' => 'Este registro já existe.']);
} catch (Throwable $e) {
    error_log('[app] erro interno: ' . $e->getMessage());
    respond(500, ['success' => false, 'message' => 'Erro interno. Tente novamente.']);
}
