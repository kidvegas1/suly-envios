<?php
$user = auth_require();
$method = get_method();
$pdo = db();

if ($method === 'GET') {
    $storeId = resolve_store_filter(!empty($_GET['store_id']) ? (int)$_GET['store_id'] : null);
    $resolvedEmployee = auth_resolve_employee(auth_is_store_locked());
    $employeeFilter = $_GET['employee'] ?? '';
    if ($resolvedEmployee && auth_is_personal_employee_scope()) {
        $employeeFilter = $resolvedEmployee['name'];
    }

    $storeSql = store_filter_sql('sl.store_id', $storeId);
    $where = 'WHERE 1=1' . $storeSql;
    $params = [];
    if ($storeId) $params[] = $storeId;

    if ($employeeFilter) {
        $where .= ' AND sl.employee_name = ?';
        $params[] = $employeeFilter;
    }

    $stmt = $pdo->prepare("SELECT sl.*, s.name as store_name FROM suly_ledger sl LEFT JOIN stores s ON s.id = sl.store_id {$where} ORDER BY sl.entry_date DESC, sl.id DESC LIMIT 200");
    $stmt->execute($params);
    $entries = $stmt->fetchAll();

  // Totals
    $totals = $pdo->prepare("SELECT
        COALESCE(SUM(owed_to_suly),0) as total_owed_to_suly,
        COALESCE(SUM(suly_owes),0) as total_suly_owes
        FROM suly_ledger sl {$where}");
    $totals->execute($params);
    $sums = $totals->fetch();

    if ($storeId) {
        $employees = $pdo->prepare('SELECT DISTINCT employee_name FROM suly_ledger WHERE store_id = ? AND employee_name IS NOT NULL AND employee_name != \'\' ORDER BY employee_name');
        $employees->execute([$storeId]);
    } else {
        $employees = $pdo->query('SELECT DISTINCT employee_name FROM suly_ledger WHERE employee_name IS NOT NULL AND employee_name != \'\' ORDER BY employee_name');
    }

    json_response([
        'entries'            => $entries,
        'total_owed_to_suly' => (float)$sums['total_owed_to_suly'],
        'total_suly_owes'    => (float)$sums['total_suly_owes'],
        'difference'         => (float)$sums['total_owed_to_suly'] - (float)$sums['total_suly_owes'],
        'employees'          => array_column($employees->fetchAll(), 'employee_name'),
        'current_employee'   => $resolvedEmployee,
        'employee_locked'    => $resolvedEmployee && auth_is_personal_employee_scope(),
        'scope'              => $storeId ? 'store' : 'all',
    ]);
}

if ($method === 'POST') {
    csrf_verify();
    $data = get_json_body();
    $storeId = resolve_store_id(!empty($data['store_id']) ? (int)$data['store_id'] : null);
    $act = $data['action'] ?? '';

    $resolvedEmployee = auth_resolve_employee(auth_is_store_locked());
    $employeeNameForEntry = ($resolvedEmployee && auth_is_personal_employee_scope())
        ? $resolvedEmployee['name']
        : sanitize($data['employee_name'] ?? '');

    if ($act === 'create') {
        validate_required($data, ['description', 'entry_date']);
        $stmt = $pdo->prepare('INSERT INTO suly_ledger (store_id, employee_name, description, owed_to_suly, suly_owes, entry_date, notes) VALUES (?,?,?,?,?,?,?)');
        $stmt->execute([
            $storeId,
            $employeeNameForEntry,
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
            $employeeNameForEntry,
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
