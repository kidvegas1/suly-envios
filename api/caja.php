<?php
$user = auth_require();
$method = get_method();
$pdo = db();

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

function caja_assert_session(PDO $pdo, int $sessionId, int $storeId): void {
    $stmt = $pdo->prepare('SELECT id FROM caja_sessions WHERE id = ? AND store_id = ?');
    $stmt->execute([$sessionId, $storeId]);
    if (!$stmt->fetch()) {
        json_error('Session not found', 404);
    }
}

// GET: List sessions or single session with entries
if ($method === 'GET') {
    $storeId = caja_store_id();
    if ($action === 'session' && isset($_GET['id'])) {
        $stmt = $pdo->prepare('SELECT * FROM caja_sessions WHERE id = ? AND store_id = ?');
        $stmt->execute([(int)$_GET['id'], $storeId]);
        $session = $stmt->fetch();
        if (!$session) json_error('Session not found', 404);

        $entries = $pdo->prepare('SELECT * FROM caja_entries WHERE session_id = ? ORDER BY sort_order, id');
        $entries->execute([$session['id']]);

        $denoms = $pdo->prepare('SELECT * FROM caja_denominations WHERE session_id = ? ORDER BY denomination DESC');
        $denoms->execute([$session['id']]);

        json_response([
            'session'       => $session,
            'entries'        => $entries->fetchAll(),
            'denominations' => $denoms->fetchAll(),
        ]);
    }

    // List sessions
    $date = $_GET['date'] ?? null;
    $where = 'WHERE cs.store_id = ?';
    $params = [$storeId];
    if ($date) {
        $where .= ' AND cs.session_date = ?';
        $params[] = $date;
    }

    $stmt = $pdo->prepare("SELECT cs.*, u.name as user_name,
        (SELECT COALESCE(SUM(total),0) FROM caja_entries WHERE session_id = cs.id) as entries_total
        FROM caja_sessions cs LEFT JOIN users u ON u.id = cs.user_id
        {$where} ORDER BY cs.session_date DESC, cs.id DESC LIMIT 50");
    $stmt->execute($params);

    json_response(['sessions' => $stmt->fetchAll()]);
}

// POST: Create/update
if ($method === 'POST') {
    csrf_verify();
    $data = get_json_body();
    $storeId = caja_store_id($data);
    $act = $data['action'] ?? '';

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
        $stmt = $pdo->prepare('UPDATE caja_entries SET cash_in = ?, checks_debits = ?, company = ?, notes = ? WHERE id = ? AND session_id IN (SELECT id FROM caja_sessions WHERE store_id = ?)');
        $stmt->execute([
            (float)($data['cash_in'] ?? 0),
            (float)($data['checks_debits'] ?? 0),
            sanitize($data['company'] ?? ''),
            sanitize($data['notes'] ?? ''),
            (int)$data['entry_id'],
            $storeId,
        ]);
        json_response(['success' => true]);
    }

    // Add custom entry
    if ($act === 'add_entry') {
        validate_required($data, ['session_id', 'company']);
        $sessionId = (int)$data['session_id'];
        caja_assert_session($pdo, $sessionId, $storeId);
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
        $stmt = $pdo->prepare('DELETE FROM caja_entries WHERE id = ? AND session_id IN (SELECT id FROM caja_sessions WHERE store_id = ?)');
        $stmt->execute([(int)$data['entry_id'], $storeId]);
        json_response(['success' => true]);
    }

    // Update denomination
    if ($act === 'update_denomination') {
        validate_required($data, ['denom_id', 'count']);
        $stmt = $pdo->prepare('UPDATE caja_denominations SET count = ? WHERE id = ? AND session_id IN (SELECT id FROM caja_sessions WHERE store_id = ?)');
        $stmt->execute([(int)$data['count'], (int)$data['denom_id'], $storeId]);
        json_response(['success' => true]);
    }

    // Close session
    if ($act === 'close_session') {
        validate_required($data, ['session_id']);
        $sessionId = (int)$data['session_id'];
        caja_assert_session($pdo, $sessionId, $storeId);
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(total),0) as t FROM caja_entries WHERE session_id = ?');
        $stmt->execute([$sessionId]);
        $total = (float)$stmt->fetch()['t'];

        $upd = $pdo->prepare("UPDATE caja_sessions SET status = 'closed', closing_balance = ?, notes = ? WHERE id = ? AND store_id = ?");
        $upd->execute([$total, sanitize($data['notes'] ?? ''), $sessionId, $storeId]);
        json_response(['success' => true, 'closing_balance' => $total]);
    }

    json_error('Unknown action');
}

json_error('Method not allowed', 405);
