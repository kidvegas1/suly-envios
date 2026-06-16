<?php
require_once __DIR__ . '/db.php';

function auth_start(): void {
    if (session_status() === PHP_SESSION_NONE) {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path'     => '/',
            'httponly'  => true,
            'secure'    => $secure,
            'samesite'  => 'Lax',
        ]);
        session_start();
    }
}

function auth_login(string $email, string $password): array|false {
    $stmt = db()->prepare('SELECT id, name, email, password_hash, role, store_id FROM users WHERE email = ? AND ' . sql_is_active() . ' LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return false;
    }

    $_SESSION['user_id']   = $user['id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['store_id']  = $user['store_id'];

    session_regenerate_id(true);

    return [
        'id'       => $user['id'],
        'name'     => $user['name'],
        'role'     => $user['role'],
        'store_id' => $user['store_id'],
    ];
}

function auth_logout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function auth_check(): bool {
    return isset($_SESSION['user_id']);
}

function auth_user(): ?array {
    if (!auth_check()) return null;
    return [
        'id'       => $_SESSION['user_id'],
        'name'     => $_SESSION['user_name'],
        'role'     => $_SESSION['user_role'],
        'store_id' => $_SESSION['store_id'],
    ];
}

function auth_require(): array {
    if (!auth_check()) {
        http_response_code(401);
        echo json_encode(['error' => 'Authentication required']);
        exit;
    }
    return auth_user();
}

function auth_require_admin(): array {
    $user = auth_require();
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Admin access required']);
        exit;
    }
    return $user;
}

function auth_is_admin(): bool {
    return auth_check() && ($_SESSION['user_role'] ?? '') === 'admin';
}

function auth_is_store_locked(): bool {
    if (!auth_check()) return false;
    return in_array($_SESSION['user_role'] ?? '', ['manager', 'employee', 'cashier'], true);
}

function auth_require_store_access(int $storeId): void {
    if (auth_is_admin()) {
        return;
    }
    $mine = (int)($_SESSION['store_id'] ?? 0);
    if (!$mine || $storeId !== $mine) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied for this store']);
        exit;
    }
}

function auth_set_store(int $storeId): void {
    $_SESSION['store_id'] = $storeId;
}
