<?php
$user = auth_require();
$method = get_method();
$pdo = db();

if ($method === 'GET') {
    $storeId = resolve_store_id(!empty($_GET['store_id']) ? (int)$_GET['store_id'] : null);
    $employee = $_GET['employee'] ?? '';

    $where = 'WHERE sl.store_id = ?';
    $params = [$storeId];

    if ($employee) {
        $where .= ' AND sl.employee_name = ?';
        $params[] = $employee;
    }

    $stmt = $pdo->prepare("SELECT sl.* FROM suly_ledger sl {$where} ORDER BY sl.entry_date DESC, sl.id DESC LIMIT 200");
    $stmt->execute($params);
    $entries = $stmt->fetchAll();

    // Totals
    $totals = $pdo->prepare("SELECT
        COALESCE(SUM(owed_to_suly),0) as total_owed_to_suly,
        COALESCE(SUM(suly_owes),0) as total_suly_owes
        FROM suly_ledger sl {$where}");
    $totals->execute($params);
    $sums = $totals->fetch();

    // Employee list
    $employees = $pdo->prepare('SELECT DISTINCT employee_name FROM suly_ledger WHERE store_id = ? AND employee_name IS NOT NULL AND employee_name != "" ORDER BY employee_name');
    $employees->execute([$storeId]);

    json_response([
        'entries'            => $entries,
        'total_owed_to_suly' => (float)$sums['total_owed_to_suly'],
        'total_suly_owes'    => (float)$sums['total_suly_owes'],
        'difference'         => (float)$sums['total_owed_to_suly'] - (float)$sums['total_suly_owes'],
        'employees'          => array_column($employees->fetchAll(), 'employee_name'),
    ]);
}

if ($method === 'POST') {
    csrf_verify();
    $data = get_json_body();
    $storeId = resolve_store_id(!empty($data['store_id']) ? (int)$data['store_id'] : null);
    $act = $data['action'] ?? '';

    if ($act === 'create') {
        validate_required($data, ['description', 'entry_date']);
        $stmt = $pdo->prepare('INSERT INTO suly_ledger (store_id, employee_name, description, owed_to_suly, suly_owes, entry_date, notes) VALUES (?,?,?,?,?,?,?)');
        $stmt->execute([
            $storeId,
            sanitize($data['employee_name'] ?? ''),
            sanitize($data['description']),
            (float)($data['owed_to_suly'] ?? 0),
            (float)($data['suly_owes'] ?? 0),
            $data['entry_date'],
            sanitize($data['notes'] ?? ''),
        ]);
        json_response(['success' => true, 'id' => sql_last_insert_id($pdo, 'suly_ledger')], 201);
    }

    if ($act === 'update') {
        validate_required($data, ['id', 'description']);
        $stmt = $pdo->prepare('UPDATE suly_ledger SET employee_name = ?, description = ?, owed_to_suly = ?, suly_owes = ?, entry_date = ?, notes = ? WHERE id = ? AND store_id = ?');
        $stmt->execute([
            sanitize($data['employee_name'] ?? ''),
            sanitize($data['description']),
            (float)($data['owed_to_suly'] ?? 0),
            (float)($data['suly_owes'] ?? 0),
            $data['entry_date'],
            sanitize($data['notes'] ?? ''),
            (int)$data['id'],
            $storeId,
        ]);
        json_response(['success' => true]);
    }

    if ($act === 'delete') {
        validate_required($data, ['id']);
        $stmt = $pdo->prepare('DELETE FROM suly_ledger WHERE id = ? AND store_id = ?');
        $stmt->execute([(int)$data['id'], $storeId]);
        json_response(['success' => true]);
    }

    json_error('Unknown action');
}

json_error('Method not allowed', 405);
