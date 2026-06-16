<?php
$method = get_method();

if ($method === 'POST') {
    $data = get_json_body();
    $action = $data['action'] ?? '';

    if ($action === 'login') {
        validate_required($data, ['email', 'password']);
        $user = auth_login($data['email'], $data['password']);
        if (!$user) {
            json_error('Invalid email or password.', 401);
        }
        json_response([
            'success' => true,
            'user'    => $user,
            'csrf'    => csrf_token(),
        ]);
    }

    if ($action === 'logout') {
        auth_logout();
        json_response(['success' => true]);
    }

    if ($action === 'switch_store') {
        auth_require_admin();
        validate_required($data, ['store_id']);
        $storeId = (int)$data['store_id'];
        $exists = db()->prepare('SELECT id FROM stores WHERE id = ? AND ' . sql_is_active());
        $exists->execute([$storeId]);
        if (!$exists->fetch()) {
            json_error('Store not found', 404);
        }
        auth_set_store($storeId);
        json_response(['success' => true, 'store_id' => $storeId]);
    }

    json_error('Unknown action.', 400);
}

if ($method === 'GET') {
    if (!auth_check()) {
        json_response(['authenticated' => false]);
    }
    $user = auth_user();
    $stores = db()->query('SELECT id, name, address, phone, barri_agency_number, barri_operator_number, viamericas_agency_number, intercambio_agency_number, intermex_agency_number FROM stores WHERE ' . sql_is_active() . ' ORDER BY name')->fetchAll();
    if ($user['role'] !== 'admin') {
        $userStoreId = (int)($user['store_id'] ?? 0);
        $stores = array_values(array_filter($stores, fn($s) => (int)$s['id'] === $userStoreId));
    }
    json_response([
        'authenticated' => true,
        'user'          => $user,
        'stores'        => $stores,
        'csrf'          => csrf_token(),
    ]);
}

json_error('Method not allowed', 405);
