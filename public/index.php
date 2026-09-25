<?php
declare(strict_types=1);

use Solosync\SyncServer\Core\AdminAuth;
use Solosync\SyncServer\Core\AdminService;
use Solosync\SyncServer\Core\ApiException;
use Solosync\SyncServer\Core\Auth;
use Solosync\SyncServer\Core\Config;
use Solosync\SyncServer\Core\Database;
use Solosync\SyncServer\Core\DeviceService;
use Solosync\SyncServer\Core\PackRepository;
use Solosync\SyncServer\Core\PluginRunner;
use Solosync\SyncServer\Core\SessionService;

require dirname(__DIR__) . '/src/bootstrap.php';

function jsonResponse(int $status, array $body): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function rawResponse(int $status, string $body): never {
    http_response_code($status);
    header('Content-Type: application/octet-stream');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    echo $body;
    exit;
}

function requestBody(int $maxBytes): string {
    $length = $_SERVER['CONTENT_LENGTH'] ?? null;
    if (is_string($length) && ctype_digit($length) && strlen($length) < 20 && (int)$length > $maxBytes) {
        throw new ApiException(413, 'REQUEST_TOO_LARGE', 'Request body is too large');
    }
    $stream = fopen('php://input', 'rb');
    if ($stream === false) throw new ApiException(400, 'INVALID_BODY', 'Unable to read request body');
    try { $body = stream_get_contents($stream, $maxBytes + 1); }
    finally { fclose($stream); }
    if (!is_string($body)) throw new ApiException(400, 'INVALID_BODY', 'Unable to read request body');
    if (strlen($body) > $maxBytes) throw new ApiException(413, 'REQUEST_TOO_LARGE', 'Request body is too large');
    return $body;
}

/** @return array<string,mixed> */
function jsonBody(int $maxBytes = 131072): array {
    try { $value = json_decode(requestBody($maxBytes), true, 64, JSON_THROW_ON_ERROR); }
    catch (JsonException) { throw new ApiException(400, 'INVALID_JSON', 'Invalid JSON body'); }
    if (!is_array($value)) throw new ApiException(400, 'INVALID_JSON', 'Expected a JSON object');
    return $value;
}

function adminCookiePath(string $route): string {
    $requestPath = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
    if ($route !== '' && $route !== '/' && str_ends_with($requestPath, $route)) {
        $prefix = rtrim(substr($requestPath, 0, -strlen($route)), '/');
        return $prefix === '' ? '/' : $prefix;
    }

    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (str_ends_with($scriptName, '/public/index.php')) {
        $prefix = substr($scriptName, 0, -strlen('/public/index.php'));
        return $prefix === '' ? '/' : $prefix;
    }
    $prefix = rtrim(str_replace('\\', '/', dirname($scriptName)), '/.');
    return $prefix === '' ? '/' : $prefix;
}

try {
    $root = dirname(__DIR__);
    $config = Config::load($root . '/private/config.php');
    $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
    if (is_string($origin) && in_array($origin, $config->allowedOrigins(), true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Solosync-Digest, X-Solosync-Admin');
        header('Access-Control-Allow-Methods: GET, PUT, POST, OPTIONS');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }
    if ($config->bool('require_https') && !$config->requestIsHttps()) {
        throw new ApiException(400, 'HTTPS_REQUIRED', 'HTTPS is required');
    }
    $method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $route = $_GET['route'] ?? parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/';
    if (!is_string($route) || strlen($route) > 2048) throw new ApiException(400, 'INVALID_ROUTE', 'Invalid route');

    if ($method === 'GET' && $route === '/admin') {
        http_response_code(308);
        header('Location: ./admin/');
        exit;
    }
    if ($method === 'GET' && $route === '/admin/') {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; connect-src 'self'; base-uri 'none'; frame-ancestors 'none'");
        readfile(__DIR__ . '/admin.html'); exit;
    }
    if ($method === 'GET' && $route === '/admin.js') {
        header('Content-Type: application/javascript; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
        readfile(__DIR__ . '/admin.js');
        exit;
    }
    if ($method === 'GET' && $route === '/admin.css') {
        header('Content-Type: text/css; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
        readfile(__DIR__ . '/admin.css');
        exit;
    }

    $adminAuth = new AdminAuth($config);
    $adminCookiePath = adminCookiePath($route);
    if ($method === 'POST' && $route === '/v1/admin/login') {
        $body = jsonBody(8192);
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';
        jsonResponse(200, ['authenticated' => true, ...$adminAuth->login($password, $adminCookiePath)]);
    }
    if ($method === 'GET' && $route === '/v1/admin/session') {
        $session = $adminAuth->session();
        jsonResponse(200, $session === null
            ? ['authenticated' => false]
            : ['authenticated' => true, ...$session]);
    }
    if ($method === 'POST' && $route === '/v1/admin/logout') {
        $adminAuth->requireMutationHeader();
        $adminAuth->logout($adminCookiePath);
        jsonResponse(200, ['authenticated' => false]);
    }

    $db = Database::connect($config);
    if ($method === 'GET' && $route === '/v1/capabilities') {
        $installed = Database::isInstalled($db);
        $bound = false;
        if ($installed) {
            $bound = $db->query('SELECT group_id IS NOT NULL FROM sync_instance WHERE id=1')->fetchColumn() == 1;
        }
        jsonResponse(200, [
            'formatVersion' => 1,
            'installed' => $installed,
            'bound' => $bound,
            'serverId' => $config->string('server_identity'),
            'maxPackBytes' => $config->int('max_pack_bytes', 1024, 64 * 1024 * 1024),
            'maxPageSize' => $config->int('max_page_size', 1, 1000),
            'minimumPollMs' => $config->int('minimum_poll_ms', 0, 3600000),
        ]);
    }

    $adminService = new AdminService($db, $config);
    if (str_starts_with($route, '/v1/admin/')) {
        $adminAuth->requireAuth();
        if ($method !== 'GET') $adminAuth->requireMutationHeader();
        if ($method === 'GET' && $route === '/v1/admin/status') jsonResponse(200, $adminService->status());
        if ($method === 'POST' && $route === '/v1/admin/install') {
            jsonResponse(200, ['appliedMigrations' => $adminService->install($root . '/migrations')]);
        }
        if ($method === 'POST' && $route === '/v1/admin/migrate') {
            jsonResponse(200, ['appliedMigrations' => $adminService->install($root . '/migrations')]);
        }
        if ($method === 'GET' && $route === '/v1/admin/connection-keys') {
            jsonResponse(200, ['keys' => $adminService->connectionKeys()]);
        }
        if ($method === 'POST' && $route === '/v1/admin/connection-keys') {
            $body = jsonBody();
            jsonResponse(201, $adminService->createConnectionKey(is_string($body['label'] ?? null) ? $body['label'] : 'default'));
        }
        if ($method === 'POST' && preg_match('#^/v1/admin/connection-keys/([0-9a-f]{16})/revoke$#D', $route, $m)) {
            jsonResponse(200, ['id' => $m[1], 'revoked' => $adminService->revokeConnectionKey($m[1])]);
        }
        if ($method === 'POST' && $route === '/v1/admin/maintenance') {
            $body = jsonBody();
            $limit = is_int($body['limit'] ?? null) ? $body['limit'] : 50;
            jsonResponse(200, (new PluginRunner($db, $config))->run($limit));
        }
        throw new ApiException(404, 'NOT_FOUND', 'Admin endpoint not found');
    }

    if (!Database::isInstalled($db)) throw new ApiException(503, 'NOT_INSTALLED', 'Solosync server is not installed');
    if ($method === 'POST' && $route === '/v1/session/exchange') {
        jsonResponse(200, (new SessionService($db))->exchange(jsonBody()));
    }

    $auth = new Auth($db);
    $credential = $auth->requireCredential();
    if ($method === 'GET' && $route === '/v1/group') jsonResponse(200, (new SessionService($db))->group());
    if ($method === 'GET' && $route === '/v1/packs') {
        $after = is_string($_GET['after'] ?? null) ? $_GET['after'] : '0';
        $limitRaw = $_GET['limit'] ?? '100';
        if (!is_string($limitRaw) || !ctype_digit($limitRaw)) throw new ApiException(400, 'INVALID_LIMIT', 'Invalid page limit');
        jsonResponse(200, (new PackRepository($db, $config))->list($after, (int)$limitRaw));
    }
    if (preg_match('#^/v1/packs/([A-Za-z0-9._:-]{1,128})$#D', $route, $m)) {
        $repo = new PackRepository($db, $config);
        if ($method === 'GET') rawResponse(200, $repo->get($m[1]));
        if ($method === 'PUT') {
            $digest = (string)($_SERVER['HTTP_X_SOLOSYNC_DIGEST'] ?? '');
            $result = $repo->put($m[1], requestBody($config->int('max_pack_bytes', 1024, 64 * 1024 * 1024)), $digest, $credential['device_id']);
            jsonResponse($result['created'] ? 201 : 200, $result);
        }
    }
    if ($method === 'POST' && preg_match('#^/v1/devices/([^/]{1,128})/revoke$#D', $route, $m)) {
        jsonResponse(200, (new DeviceService($db))->revoke(rawurldecode($m[1])));
    }
    throw new ApiException(404, 'NOT_FOUND', 'Endpoint not found');
} catch (ApiException $error) {
    jsonResponse($error->status, ['code' => $error->apiCode, 'message' => $error->getMessage()]);
} catch (Throwable $error) {
    error_log('[Solosync] ' . $error::class . ': ' . $error->getMessage());
    jsonResponse(500, ['code' => 'INTERNAL_ERROR', 'message' => 'Internal server error']);
}
