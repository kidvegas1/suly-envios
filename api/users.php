<?php
$user = auth_require_admin();
$method = get_method();
$pdo = db();

if ($method === 'GET') {
    $storeId = !empty($_GET['store_id']) ? (int)$_GET['store_id'] : null;
    $sql = 'SELECT u.id, u.name, u.email, u.role, u.store_id, u.active, u.created_at, s.name AS store_name
            FROM users u
            LEFT JOIN stores s ON s.id = u.store_id';
    $params = [];
    if ($storeId) {
        $sql .= ' WHERE u.store_id = ?';
        $params[] = $storeId;
    }
    $sql .= ' ORDER BY u.name';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    json_response(['users' => $stmt->fetchAll()]);
}

if ($method === 'POST') {
    csrf_verify();
    $data = get_json_body();
    $act = $data['action'] ?? 'create';

    if ($act === 'create') {
        validate_required($data, ['name', 'email', 'password', 'role']);
        $role = sanitize($data['role']);
        $allowed = ['admin', 'manager', 'cashier', 'employee'];
        if (!in_array($role, $allowed, true)) {
            json_error('Invalid role', 400);
        }
        $storeId = !empty($data['store_id']) ? (int)$data['store_id'] : null;
        if ($role !== 'admin' && !$storeId) {
            json_error('Store is required for non-admin users', 400);
        }
        if ($storeId) {
            $check = $pdo->prepare('SELECT id FROM stores WHERE id = ? AND ' . sql_is_active());
            $check->execute([$storeId]);
            if (!$check->fetch()) {
                json_error('Store not found', 404);
            }
        }
        $email = strtolower(trim($data['email']));
        $dup = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $dup->execute([$email]);
        if ($dup->fetch()) {
            json_error('Email already in use', 400);
        }
        if (strlen($data['password']) < 8) {
            json_error('Password must be at least 8 characters', 400);
        }
        $hash = password_hash($data['password'], PASSWORD_DEFAULT);
        $pdo->prepare('INSERT INTO users (name, email, password_hash, role, store_id, active) VALUES (?,?,?,?,?, ' . sql_bool(true) . ')')
            ->execute([
                sanitize($data['name']),
                $email,
                $hash,
                $role,
                $role === 'admin' ? ($storeId ?: null) : $storeId,
            ]);
        json_response(['success' => true, 'id' => sql_last_insert_id($pdo, 'users')], 201);
    }

    if ($act === 'update') {
        validate_required($data, ['id']);
        $id = (int)$data['id'];
        $existing = $pdo->prepare('SELECT id, role FROM users WHERE id = ?');
        $existing->execute([$id]);
        $row = $existing->fetch();
        if (!$row) {
            json_error('User not found', 404);
        }
        $fields = [];
        $params = [];
        if (isset($data['name'])) {
            $fields[] = 'name = ?';
            $params[] = sanitize($data['name']);
        }
        if (isset($data['role'])) {
            $role = sanitize($data['role']);
            if (!in_array($role, ['admin', 'manager', 'cashier', 'employee'], true)) {
                json_error('Invalid role', 400);
            }
            $fields[] = 'role = ?';
            $params[] = $role;
        }
        if (array_key_exists('store_id', $data)) {
            $storeId = $data['store_id'] !== null && $data['store_id'] !== '' ? (int)$data['store_id'] : null;
            if ($storeId) {
                $check = $pdo->prepare('SELECT id FROM stores WHERE id = ? AND ' . sql_is_active());
                $check->execute([$storeId]);
                if (!$check->fetch()) {
                    json_error('Store not found', 404);
                }
            }
            $fields[] = 'store_id = ?';
            $params[] = $storeId;
        }
        if (!empty($data['password'])) {
            if (strlen($data['password']) < 8) {
                json_error('Password must be at least 8 characters', 400);
            }
            $fields[] = 'password_hash = ?';
            $params[] = password_hash($data['password'], PASSWORD_DEFAULT);
        }
        if (isset($data['active'])) {
            $fields[] = 'active = ?';
            $params[] = db_bool((bool)$data['active']);
        }
        if (!$fields) {
            json_error('Nothing to update', 400);
        }
        $params[] = $id;
        $pdo->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
        json_response(['success' => true]);
    }

    if ($act === 'deactivate') {
        validate_required($data, ['id']);
        $id = (int)$data['id'];
        if ($id === (int)$user['id']) {
            json_error('You cannot deactivate your own account', 400);
        }
        $pdo->prepare('UPDATE users SET active = ' . sql_bool(false) . ' WHERE id = ?')->execute([$id]);
        json_response(['success' => true]);
    }

    json_error('Unknown action', 400);
}

json_error('Method not allowed', 405);
