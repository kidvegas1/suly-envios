<?php
$user = auth_require();
$method = get_method();
$pdo = db();

require_once __DIR__ . '/../includes/company-flags.php';

$action = $_GET['action'] ?? '';

function caja_store_id(?array $data = null): int {
    $requested = null;
    if (!empty($_GET['store_id'])) {
        $requested = (int)$_GET['store_id'];
    } elseif ($data !== null && !empty($data['store_id'])) {
        $requested = (int)$data['store_id'];
    }
    return resolve_store_id($requested);
}

function caja_fetch_session(PDO $pdo, int $sessionId, int $storeId): array {
    $stmt = $pdo->prepare('SELECT * FROM caja_sessions WHERE id = ? AND store_id = ?');
    $stmt->execute([$sessionId, $storeId]);
    $session = $stmt->fetch();
    if (!$session) {
        json_error('Session not found', 404);
    }
    return $session;
}

function caja_assert_session(PDO $pdo, int $sessionId, int $storeId): void {
    caja_fetch_session($pdo, $sessionId, $storeId);
}

function caja_assert_open_session(PDO $pdo, int $sessionId, int $storeId): array {
    $session = caja_fetch_session($pdo, $sessionId, $storeId);
    if (($session['status'] ?? '') !== 'open') {
        json_error('Session is closed', 403);
    }
    return $session;
}

function caja_require_affected(PDOStatement $stmt, string $message = 'Record not found'): void {
    if ($stmt->rowCount() < 1) {
        json_error($message, 404);
    }
}

function caja_assert_entry_mutable(PDO $pdo, int $entryId, int $storeId): array {
    $stmt = $pdo->prepare(
        'SELECT e.*, s.status AS session_status, s.id AS session_id
         FROM caja_entries e
         JOIN caja_sessions s ON s.id = e.session_id
         WHERE e.id = ? AND s.store_id = ?'
    );
    $stmt->execute([$entryId, $storeId]);
    $row = $stmt->fetch();
    if (!$row) {
        json_error('Entry not found', 404);
    }
    if (($row['session_status'] ?? '') !== 'open') {
        json_error('Session is closed', 403);
    }
    return $row;
}

function caja_assert_denom_mutable(PDO $pdo, int $denomId, int $storeId): array {
    $stmt = $pdo->prepare(
        'SELECT d.*, s.status AS session_status, s.id AS session_id
         FROM caja_denominations d
         JOIN caja_sessions s ON s.id = d.session_id
         WHERE d.id = ? AND s.store_id = ?'
    );
    $stmt->execute([$denomId, $storeId]);
    $row = $stmt->fetch();
    if (!$row) {
        json_error('Denomination not found', 404);
    }
    if (($row['session_status'] ?? '') !== 'open') {
        json_error('Session is closed', 403);
    }
    return $row;
}

// GET: List sessions, single session, or company flags
if ($method === 'GET') {
    $storeId = caja_store_id();

    if ($action === 'list_company_flags') {
        json_response([
            'flags' => company_flags_list_active($pdo),
            'can_manage' => auth_is_admin(),
        ]);
    }

    if ($action === 'session' && isset($_GET['id'])) {
        $session = caja_fetch_session($pdo, (int)$_GET['id'], $storeId);

        $entries = $pdo->prepare('SELECT * FROM caja_entries WHERE session_id = ? ORDER BY sort_order, id');
        $entries->execute([$session['id']]);
        $entryRows = $entries->fetchAll();

        $denoms = $pdo->prepare('SELECT * FROM caja_denominations WHERE session_id = ? ORDER BY denomination DESC');
        $denoms->execute([$session['id']]);

        $labels = array_map(static fn(array $row): string => (string)($row['company'] ?? ''), $entryRows);

        json_response([
            'session'         => $session,
            'entries'         => $entryRows,
            'denominations'   => $denoms->fetchAll(),
            'company_flags'   => company_flags_map_for_labels($pdo, $labels),
            'can_manage_flags'=> auth_is_admin(),
        ]);
    }

    // List sessions for active store
    $date = $_GET['date'] ?? null;
    $where = 'WHERE cs.store_id = ?';
    $params = [$storeId];
    if ($date) {
        $where .= ' AND cs.session_date = ?';
        $params[] = $date;
    }

    $stmt = $pdo->prepare("SELECT cs.*, u.name as user_name, s.name as store_name,
        (SELECT COALESCE(SUM(total),0) FROM caja_entries WHERE session_id = cs.id) as entries_total
        FROM caja_sessions cs LEFT JOIN users u ON u.id = cs.user_id
        LEFT JOIN stores s ON s.id = cs.store_id
        {$where} ORDER BY cs.session_date DESC, cs.id DESC LIMIT 50");
    $stmt->execute($params);

    json_response(['sessions' => $stmt->fetchAll(), 'scope' => 'store', 'store_id' => $storeId]);
}

// POST: Create/update
if ($method === 'POST') {
    csrf_verify();
    $data = get_json_body();
    $storeId = caja_store_id($data);
    $act = $data['action'] ?? '';

    if ($act === 'set_company_flag') {
        company_flag_require_admin();
        validate_required($data, ['company', 'reason']);
        $flag = company_flag_set($pdo, (string)$data['company'], (string)$data['reason'], (int)$user['id']);
        json_response(['success' => true, 'flag' => $flag]);
    }

    if ($act === 'clear_company_flag') {
        company_flag_require_admin();
        validate_required($data, ['company']);
        company_flag_clear($pdo, (string)$data['company'], (int)$user['id']);
        json_response(['success' => true]);
    }

    // Open new session
    if ($act === 'open_session') {
        validate_required($data, ['session_date', 'opening_balance']);
        $stmt = $pdo->prepare('INSERT INTO caja_sessions (store_id, user_id, session_date, cashier_name, opening_balance, notes) VALUES (?,?,?,?,?,?)');
        $stmt->execute([
            $storeId,
            $user['id'],
            $data['session_date'],
            sanitize($data['cashier_name'] ?? $user['name']),
            (float)$data['opening_balance'],
            sanitize($data['notes'] ?? ''),
        ]);
        $sessionId = sql_last_insert_id($pdo, 'caja_sessions');

        // Default companies
        $companies = ['CAJA','BARRI','VIAMERICAS','INTERCAMBIO','DINEX','GARDA','VIALINK','JP CHEQUES','COMISIONES'];
        $sort = 0;
        $ins = $pdo->prepare('INSERT INTO caja_entries (session_id, company, cash_in, checks_debits, sort_order) VALUES (?,?,0,0,?)');
        foreach ($companies as $c) {
            $ins->execute([$sessionId, $c, $sort++]);
        }

        // Default denominations
        $denominations = [100, 50, 20, 10, 5, 1];
        $denIns = $pdo->prepare('INSERT INTO caja_denominations (session_id, denomination, count) VALUES (?,?,0)');
        foreach ($denominations as $d) {
            $denIns->execute([$sessionId, $d]);
        }

        json_response(['success' => true, 'session_id' => $sessionId], 201);
    }

    // Update entry
    if ($act === 'update_entry') {
        validate_required($data, ['entry_id']);
        $entryId = (int)$data['entry_id'];
        caja_assert_entry_mutable($pdo, $entryId, $storeId);
        $stmt = $pdo->prepare('UPDATE caja_entries SET cash_in = ?, checks_debits = ?, company = ?, notes = ? WHERE id = ? AND session_id IN (SELECT id FROM caja_sessions WHERE store_id = ? AND status = ?)');
        $stmt->execute([
            (float)($data['cash_in'] ?? 0),
            (float)($data['checks_debits'] ?? 0),
            sanitize($data['company'] ?? ''),
            sanitize($data['notes'] ?? ''),
            $entryId,
            $storeId,
            'open',
        ]);
        caja_require_affected($stmt, 'Entry not found');
        json_response(['success' => true]);
    }

    // Add custom entry
    if ($act === 'add_entry') {
        validate_required($data, ['session_id', 'company']);
        $sessionId = (int)$data['session_id'];
        caja_assert_open_session($pdo, $sessionId, $storeId);
        $stmt = $pdo->prepare('INSERT INTO caja_entries (session_id, company, cash_in, checks_debits, notes, sort_order) VALUES (?,?,?,?,?, (SELECT COALESCE(MAX(e2.sort_order),0)+1 FROM caja_entries e2 WHERE e2.session_id = ?))');
        $stmt->execute([
            $sessionId,
            sanitize($data['company']),
            (float)($data['cash_in'] ?? 0),
            (float)($data['checks_debits'] ?? 0),
            sanitize($data['notes'] ?? ''),
            $sessionId,
        ]);
        json_response(['success' => true, 'entry_id' => sql_last_insert_id($pdo, 'caja_entries')], 201);
    }

    // Delete entry
    if ($act === 'delete_entry') {
        validate_required($data, ['entry_id']);
        $entryId = (int)$data['entry_id'];
        caja_assert_entry_mutable($pdo, $entryId, $storeId);
        $stmt = $pdo->prepare('DELETE FROM caja_entries WHERE id = ? AND session_id IN (SELECT id FROM caja_sessions WHERE store_id = ? AND status = ?)');
        $stmt->execute([$entryId, $storeId, 'open']);
        caja_require_affected($stmt, 'Entry not found');
        json_response(['success' => true]);
    }

    // Update denomination
    if ($act === 'update_denomination') {
        validate_required($data, ['denom_id', 'count']);
        $denomId = (int)$data['denom_id'];
        caja_assert_denom_mutable($pdo, $denomId, $storeId);
        $stmt = $pdo->prepare('UPDATE caja_denominations SET count = ? WHERE id = ? AND session_id IN (SELECT id FROM caja_sessions WHERE store_id = ? AND status = ?)');
        $stmt->execute([(int)$data['count'], $denomId, $storeId, 'open']);
        caja_require_affected($stmt, 'Denomination not found');
        json_response(['success' => true]);
    }

    // Close session
    if ($act === 'close_session') {
        validate_required($data, ['session_id']);
        $sessionId = (int)$data['session_id'];
        caja_assert_open_session($pdo, $sessionId, $storeId);
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(total),0) as t FROM caja_entries WHERE session_id = ?');
        $stmt->execute([$sessionId]);
        $total = (float)$stmt->fetch()['t'];

        $upd = $pdo->prepare("UPDATE caja_sessions SET status = 'closed', closing_balance = ?, notes = ? WHERE id = ? AND store_id = ? AND status = 'open'");
        $upd->execute([$total, sanitize($data['notes'] ?? ''), $sessionId, $storeId]);
        caja_require_affected($upd, 'Session not found or already closed');
        json_response(['success' => true, 'closing_balance' => $total]);
    }

    json_error('Unknown action');
}

json_error('Method not allowed', 405);
