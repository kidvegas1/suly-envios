<?php
$user = auth_require();
$method = get_method();
$pdo = db();

if ($method === 'GET') {
    $storeId = resolve_store_id(!empty($_GET['store_id']) ? (int)$_GET['store_id'] : null);
    $action = $_GET['action'] ?? 'today';

    if ($action === 'employees') {
        $stmt = $pdo->prepare('SELECT id, name, phone FROM employees WHERE store_id = ? AND status = \'active\' ORDER BY name');
        $stmt->execute([$storeId]);
        json_response(['employees' => $stmt->fetchAll()]);
    }

    if ($action === 'today') {
        $date = $_GET['date'] ?? date('Y-m-d');
        $stmt = $pdo->prepare("SELECT ci.*, e.name as employee_name
            FROM clock_ins ci
            JOIN employees e ON e.id = ci.employee_id
            WHERE ci.store_id = ? AND ' . sql_date('ci.clock_in_time') . ' = ?
            ORDER BY ci.clock_in_time DESC");
        $stmt->execute([$storeId, $date]);
        json_response(['clock_ins' => $stmt->fetchAll()]);
    }

    if ($action === 'history') {
        $employeeId = $_GET['employee_id'] ?? null;
        $startDate = $_GET['start'] ?? date('Y-m-d', strtotime('-7 days'));
        $endDate = $_GET['end'] ?? date('Y-m-d');

        $where = 'WHERE ci.store_id = ? AND ' . sql_date('ci.clock_in_time') . ' BETWEEN ? AND ?';
        $params = [$storeId, $startDate, $endDate];

        if ($employeeId) {
            $where .= ' AND ci.employee_id = ?';
            $params[] = (int)$employeeId;
        }

        $stmt = $pdo->prepare("SELECT ci.*, e.name as employee_name
            FROM clock_ins ci JOIN employees e ON e.id = ci.employee_id
            {$where} ORDER BY ci.clock_in_time DESC LIMIT 200");
        $stmt->execute($params);

        // Weekly hours summary
        $hours = $pdo->prepare("SELECT e.name, COALESCE(SUM(ci.hours_worked),0) as total_hours, COUNT(ci.id) as days
            FROM clock_ins ci JOIN employees e ON e.id = ci.employee_id
            {$where} AND ci.status = 'clocked_out'
            GROUP BY e.id, e.name ORDER BY e.name");
        $hours->execute($params);

        json_response([
            'clock_ins' => $stmt->fetchAll(),
            'hours_summary' => $hours->fetchAll(),
        ]);
    }

    json_error('Unknown action');
}

if ($method === 'POST') {
    csrf_verify();
    $storeId = resolve_store_id(!empty($_POST['store_id']) ? (int)$_POST['store_id'] : null);

    $action = $_POST['action'] ?? '';

    if ($action === 'clock_in') {
        $employeeId = (int)($_POST['employee_id'] ?? 0);
        if (!$employeeId) json_error('Employee is required');

        $empCheck = $pdo->prepare('SELECT id FROM employees WHERE id = ? AND store_id = ?');
        $empCheck->execute([$employeeId, $storeId]);
        if (!$empCheck->fetch()) json_error('Employee not found', 404);

        // Check if already clocked in
        $check = $pdo->prepare('SELECT id FROM clock_ins WHERE employee_id = ? AND ' . sql_date_eq_today('clock_in_time') . " AND status = 'clocked_in'");
        $check->execute([$employeeId]);
        if ($check->fetch()) json_error('Employee is already clocked in today');

        // Photo is required
        if (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
            json_error('Photo is required for clock-in');
        }

        $allowed = ['image/jpeg', 'image/png', 'image/webp'];
        if (!in_array($_FILES['photo']['type'], $allowed)) {
            json_error('Photo must be JPEG, PNG, or WebP');
        }

        $photoPath = upload_file($_FILES['photo'], 'clock-in');
        if (!$photoPath) json_error('Failed to save photo');

        $stmt = $pdo->prepare('INSERT INTO clock_ins (employee_id, store_id, clock_in_time, photo_path, status) VALUES (?,?,' . sql_now() . ',?,?)');
        $stmt->execute([$employeeId, $storeId, $photoPath, 'clocked_in']);

        json_response(['success' => true, 'clock_in_id' => sql_last_insert_id($pdo, 'clock_ins')], 201);
    }

    if ($action === 'clock_out') {
        $clockInId = (int)($_POST['clock_in_id'] ?? 0);
        if (!$clockInId) json_error('Clock-in ID is required');

        $stmt = $pdo->prepare('SELECT * FROM clock_ins WHERE id = ? AND store_id = ? AND status = \'clocked_in\'');
        $stmt->execute([$clockInId, $storeId]);
        $record = $stmt->fetch();
        if (!$record) json_error('Active clock-in not found');

        $photoPath = null;
        if (!empty($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $photoPath = upload_file($_FILES['photo'], 'clock-in');
        }

        $clockIn = new DateTime($record['clock_in_time']);
        $clockOut = new DateTime();
        $diff = $clockIn->diff($clockOut);
        $hours = round($diff->h + ($diff->i / 60), 2);

        $upd = $pdo->prepare('UPDATE clock_ins SET clock_out_time = ' . sql_now() . ', clock_out_photo_path = ?, hours_worked = ?, status = \'clocked_out\' WHERE id = ?');
        $upd->execute([$photoPath, $hours, $clockInId]);

        json_response(['success' => true, 'hours_worked' => $hours]);
    }

    if ($action === 'add_employee') {
        $data = $_POST;
        if (empty($data['name'])) json_error('Employee name is required');
        $stmt = $pdo->prepare('INSERT INTO employees (store_id, name, phone, hourly_rate) VALUES (?,?,?,?)');
        $stmt->execute([
            $storeId,
            sanitize($data['name']),
            sanitize($data['phone'] ?? ''),
            (float)($data['hourly_rate'] ?? 0),
        ]);
        json_response(['success' => true, 'employee_id' => sql_last_insert_id($pdo, 'employees')], 201);
    }

    json_error('Unknown action');
}

json_error('Method not allowed', 405);
