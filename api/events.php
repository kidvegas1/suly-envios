<?php
$user = auth_require();
$method = get_method();
$pdo = db();

if ($method === 'GET') {
    $storeId = resolve_store_id(!empty($_GET['store_id']) ? (int)$_GET['store_id'] : null);
    $status = $_GET['status'] ?? '';
    $where = 'WHERE e.store_id = ?';
    $params = [$storeId];
    if ($status) { $where .= ' AND e.status = ?'; $params[] = $status; }

    $stmt = $pdo->prepare("SELECT e.* FROM events e {$where} ORDER BY e.event_date DESC LIMIT 200");
    $stmt->execute($params);

    $upcoming = $pdo->prepare('SELECT COUNT(*) as cnt FROM events WHERE store_id = ? AND event_date >= ' . sql_curdate() . " AND status IN ('booked','confirmed')");
    $upcoming->execute([$storeId]);

    json_response([
        'events'   => $stmt->fetchAll(),
        'upcoming' => (int)$upcoming->fetch()['cnt'],
    ]);
}

if ($method === 'POST') {
    csrf_verify();
    $data = get_json_body();
    $storeId = resolve_store_id(!empty($data['store_id']) ? (int)$data['store_id'] : null);
    $act = $data['action'] ?? '';

    if ($act === 'create') {
        validate_required($data, ['client_name', 'event_date']);
        $stmt = $pdo->prepare('INSERT INTO events (store_id, client_name, phone, event_date, deposit, balance, color_theme, package, payment_method, status, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$storeId, sanitize($data['client_name']), sanitize($data['phone'] ?? ''), $data['event_date'], (float)($data['deposit'] ?? 0), (float)($data['balance'] ?? 0), sanitize($data['color_theme'] ?? ''), sanitize($data['package'] ?? ''), sanitize($data['payment_method'] ?? ''), $data['status'] ?? 'booked', sanitize($data['notes'] ?? '')]);
        json_response(['success' => true, 'id' => sql_last_insert_id($pdo, 'events')], 201);
    }

    if ($act === 'update') {
        validate_required($data, ['id', 'client_name', 'event_date']);
        $stmt = $pdo->prepare('UPDATE events SET client_name = ?, phone = ?, event_date = ?, deposit = ?, balance = ?, color_theme = ?, package = ?, payment_method = ?, status = ?, notes = ? WHERE id = ? AND store_id = ?');
        $stmt->execute([sanitize($data['client_name']), sanitize($data['phone'] ?? ''), $data['event_date'], (float)($data['deposit'] ?? 0), (float)($data['balance'] ?? 0), sanitize($data['color_theme'] ?? ''), sanitize($data['package'] ?? ''), sanitize($data['payment_method'] ?? ''), $data['status'] ?? 'booked', sanitize($data['notes'] ?? ''), (int)$data['id'], $storeId]);
        json_response(['success' => true]);
    }

    if ($act === 'delete') {
        validate_required($data, ['id']);
        $pdo->prepare('DELETE FROM events WHERE id = ? AND store_id = ?')->execute([(int)$data['id'], $storeId]);
        json_response(['success' => true]);
    }

    json_error('Unknown action');
}

json_error('Method not allowed', 405);
