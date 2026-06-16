<?php
$user = auth_require();
$method = get_method();
$pdo = db();

if ($method === 'GET') {
    $storeId = resolve_store_id(!empty($_GET['store_id']) ? (int)$_GET['store_id'] : null);
    $year = (int)($_GET['year'] ?? date('Y'));

    // Monthly stats from transfer_statistics table
    $stmt = $pdo->prepare('SELECT company, month, year, transfer_count, total_usd FROM transfer_statistics WHERE store_id = ? AND year = ? ORDER BY month, company');
    $stmt->execute([$storeId, $year]);
    $stats = $stmt->fetchAll();

    $monthCol = sql_month('date_sent');
    $yearCol = sql_year('date_sent');

    // Also compute live from transfers table
    $live = $pdo->prepare("SELECT company, {$monthCol} as month, COUNT(*) as transfer_count, COALESCE(SUM(amount_usd),0) as total_usd FROM transfers WHERE store_id = ? AND {$yearCol} = ? AND company IS NOT NULL AND company != '' GROUP BY company, {$monthCol} ORDER BY month, company");
    $live->execute([$storeId, $year]);
    $liveStats = $live->fetchAll();

    // Company totals for the year
    $totals = $pdo->prepare("SELECT company, COUNT(*) as transfer_count, COALESCE(SUM(amount_usd),0) as total_usd FROM transfers WHERE store_id = ? AND {$yearCol} = ? AND company IS NOT NULL AND company != '' GROUP BY company ORDER BY total_usd DESC");
    $totals->execute([$storeId, $year]);

    // Monthly totals (all companies combined)
    $monthly = $pdo->prepare("SELECT {$monthCol} as month, COUNT(*) as transfer_count, COALESCE(SUM(amount_usd),0) as total_usd FROM transfers WHERE store_id = ? AND {$yearCol} = ? GROUP BY {$monthCol} ORDER BY month");
    $monthly->execute([$storeId, $year]);

    // Available years
    $years = $pdo->prepare("SELECT DISTINCT {$yearCol} as y FROM transfers WHERE store_id = ? ORDER BY y DESC");
    $years->execute([$storeId]);

    json_response([
        'saved_stats'    => $stats,
        'live_stats'     => $liveStats,
        'company_totals' => $totals->fetchAll(),
        'monthly_totals' => $monthly->fetchAll(),
        'years'          => array_column($years->fetchAll(), 'y'),
        'year'           => $year,
    ]);
}

if ($method === 'POST') {
    csrf_verify();
    $data = get_json_body();
    $storeId = resolve_store_id(!empty($data['store_id']) ? (int)$data['store_id'] : null);
    $act = $data['action'] ?? '';

    if ($act === 'refresh') {
        $year = (int)($data['year'] ?? date('Y'));
        $pdo->prepare('DELETE FROM transfer_statistics WHERE store_id = ? AND year = ?')->execute([$storeId, $year]);

        $monthCol = sql_month('date_sent');
        $yearCol = sql_year('date_sent');
        $ins = $pdo->prepare('INSERT INTO transfer_statistics (store_id, company, month, year, transfer_count, total_usd) VALUES (?,?,?,?,?,?)');
        $live = $pdo->prepare("SELECT company, {$monthCol} as month, COUNT(*) as cnt, COALESCE(SUM(amount_usd),0) as total FROM transfers WHERE store_id = ? AND {$yearCol} = ? AND company IS NOT NULL AND company != '' GROUP BY company, {$monthCol}");
        $live->execute([$storeId, $year]);
        foreach ($live->fetchAll() as $row) {
            $ins->execute([$storeId, $row['company'], $row['month'], $year, $row['cnt'], $row['total']]);
        }
        json_response(['success' => true]);
    }

    json_error('Unknown action');
}

json_error('Method not allowed', 405);
