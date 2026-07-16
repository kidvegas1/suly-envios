<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/helpers.php';

auth_start();

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Serve static files directly when using PHP built-in server
if (php_sapi_name() === 'cli-server') {
    $staticFile = __DIR__ . $uri;
    if ($uri !== '/' && is_file($staticFile)) {
        return false;
    }
}

$path = '/' . trim($uri, '/');

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

if (str_starts_with($path, '/api/')) {
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
    ob_start();

    header('Content-Type: application/json; charset=utf-8');

    $apiSegment = trim(substr($path, 5), '/');
    $apiFile = __DIR__ . '/api/' . basename($apiSegment) . '.php';
    if (file_exists($apiFile)) {
        try {
            require $apiFile;
        } catch (\Throwable $e) {
            ob_end_clean();
            json_error($e->getMessage(), 500);
        }
    } else {
        ob_end_clean();
        json_error('Endpoint not found', 404);
    }
    $leaked = ob_get_clean();
    if ($leaked && !headers_sent()) {
        error_log('[API leak] ' . $path . ': ' . substr($leaked, 0, 500));
    }
    exit;
}

$pageMap = [
    '/'            => 'pages/login.html',
    '/login'       => 'pages/login.html',
    '/dashboard'   => 'pages/dashboard.html',
    '/caja'        => 'pages/caja.html',
    '/clients'     => 'pages/clients.html',
    '/suly-ledger' => 'pages/suly-ledger.html',
    '/schedule'    => 'pages/schedule.html',
    '/employees'   => 'pages/employees.html',
    '/statistics'  => 'pages/statistics.html',
    '/accounting'  => 'pages/accounting.html',
    '/inventory'   => 'pages/inventory.html',
    '/events'      => 'pages/events.html',
    '/plates'      => 'pages/plates.html',
    '/import'      => 'pages/import.html',
    '/reports'        => 'pages/reports.html',
    '/reports-center' => 'pages/reports-center.html',
    '/analytics'      => 'pages/analytics.html',
    '/security'       => 'pages/security.html',
    '/stores'         => 'pages/stores.html',
];

if (isset($pageMap[$path])) {
    $pageKey = trim($path, '/') ?: 'login';
    if ($pageKey !== 'login' && !auth_check()) {
        header('Location: /login', true, 302);
        exit;
    }
    if ($pageKey !== 'login' && !auth_page_allowed($pageKey)) {
        header('Location: /dashboard', true, 302);
        exit;
    }
    $file = __DIR__ . '/' . $pageMap[$path];
    if (file_exists($file)) {
        readfile($file);
        exit;
    }
}

http_response_code(404);
echo '<!DOCTYPE html><html><body><h1>404 — Page Not Found</h1></body></html>';
