<?php
$user = auth_require();
$method = get_method();
$pdo = db();

if ($method === 'GET') {
    auth_require_admin();
    $id = $_GET['id'] ?? null;
    if ($id) {
        $stmt = $pdo->prepare('SELECT * FROM stores WHERE id = ?');
        $stmt->execute([(int)$id]);
        $store = $stmt->fetch();
        if (!$store) json_error('Store not found', 404);
        json_response(['store' => $store]);
    }
    $stores = $pdo->query('SELECT * FROM stores WHERE ' . sql_is_active() . ' ORDER BY name')->fetchAll();
    json_response(['stores' => $stores]);
}

if ($method === 'POST') {
    auth_require_admin();
    csrf_verify();
    $data = get_json_body();
    $act = $data['action'] ?? 'create';

    if ($act === 'create') {
        validate_required($data, ['name']);
        $stmt = $pdo->prepare('INSERT INTO stores (name, address, phone, barri_agency_number, barri_operator_number, viamericas_agency_number, intercambio_agency_number, intermex_agency_number) VALUES (?,?,?,?,?,?,?,?)');
        $stmt->execute([
            sanitize($data['name']),
            sanitize($data['address'] ?? ''),
            sanitize($data['phone'] ?? ''),
            sanitize($data['barri_agency_number'] ?? ''),
            sanitize($data['barri_operator_number'] ?? ''),
            sanitize($data['viamericas_agency_number'] ?? ''),
            sanitize($data['intercambio_agency_number'] ?? ''),
            sanitize($data['intermex_agency_number'] ?? ''),
        ]);
        json_response(['success' => true, 'id' => sql_last_insert_id($pdo, 'stores')], 201);
    }

    if ($act === 'update') {
        validate_required($data, ['id', 'name']);
        $stmt = $pdo->prepare('UPDATE stores SET name = ?, address = ?, phone = ?, barri_agency_number = ?, barri_operator_number = ?, viamericas_agency_number = ?, intercambio_agency_number = ?, intermex_agency_number = ? WHERE id = ?');
        $stmt->execute([
            sanitize($data['name']),
            sanitize($data['address'] ?? ''),
            sanitize($data['phone'] ?? ''),
            sanitize($data['barri_agency_number'] ?? ''),
            sanitize($data['barri_operator_number'] ?? ''),
            sanitize($data['viamericas_agency_number'] ?? ''),
            sanitize($data['intercambio_agency_number'] ?? ''),
            sanitize($data['intermex_agency_number'] ?? ''),
            (int)$data['id'],
        ]);
        json_response(['success' => true]);
    }

    if ($act === 'deactivate') {
        validate_required($data, ['id']);
        $pdo->prepare('UPDATE stores SET active = ' . sql_bool(false) . ' WHERE id = ?')->execute([(int)$data['id']]);
        json_response(['success' => true]);
    }

    json_error('Unknown action', 400);
}

json_error('Method not allowed', 405);
