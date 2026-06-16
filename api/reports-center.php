<?php
$user = auth_require();
$method = get_method();
$pdo = db();

if ($method === 'GET') {
    $period = $_GET['period'] ?? 'monthly';
    $company = $_GET['company'] ?? '';
    if (auth_is_admin()) {
        $storeId = !empty($_GET['store_id']) ? (int)$_GET['store_id'] : null;
    } else {
        $storeId = resolve_store_id(!empty($_GET['store_id']) ? (int)$_GET['store_id'] : null);
    }
    $dateFrom = $_GET['date_from'] ?? null;
    $dateTo = $_GET['date_to'] ?? null;
    $sender = $_GET['sender'] ?? '';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = 100;
    $offset = ($page - 1) * $limit;

    // Compute date range from period if not custom
    if (!$dateFrom || !$dateTo) {
        $now = new DateTime();
        switch ($period) {
            case 'daily':
                $dateFrom = $now->format('Y-m-d');
                $dateTo = $dateFrom;
                break;
            case 'weekly':
                $start = (clone $now)->modify('monday this week');
                $dateFrom = $start->format('Y-m-d');
                $dateTo = $now->format('Y-m-d');
                break;
            case 'monthly':
                $dateFrom = $now->format('Y-m-01');
                $dateTo = $now->format('Y-m-t');
                break;
            case 'annual':
                $dateFrom = $now->format('Y-01-01');
                $dateTo = $now->format('Y-12-31');
                break;
            case 'all':
                $dateFrom = '2000-01-01';
                $dateTo = '2099-12-31';
                break;
            default:
                $dateFrom = $now->format('Y-m-01');
                $dateTo = $now->format('Y-m-t');
        }
    }

    $dateFromFull = $dateFrom . ' 00:00:00';
    $dateToFull = $dateTo . ' 23:59:59';

    // Build WHERE clauses
    $where = 't.date_sent BETWEEN ? AND ?';
    $params = [$dateFromFull, $dateToFull];

    if ($company) {
        $where .= ' AND t.company = ?';
        $params[] = $company;
    }
    if ($storeId) {
        $where .= ' AND t.store_id = ?';
        $params[] = $storeId;
    }
    if ($sender) {
        $where .= ' AND c.name LIKE ?';
        $params[] = '%' . $sender . '%';
    }

    // Summary totals
    $summaryQ = "SELECT
        COUNT(t.id) as total_transactions,
        COALESCE(SUM(t.amount_usd),0) as total_principal,
        COALESCE(SUM(t.fee),0) as total_fees,
        COALESCE(SUM(t.tax),0) as total_tax,
        COALESCE(SUM(t.amount_usd + COALESCE(t.fee,0) + COALESCE(t.tax,0)),0) as total_amount,
        COUNT(DISTINCT t.client_id) as unique_senders,
        COUNT(DISTINCT t.company) as companies_used
        FROM transfers t
        LEFT JOIN clients c ON c.id = t.client_id
        WHERE {$where}";
    $stmt = $pdo->prepare($summaryQ);
    $stmt->execute($params);
    $summary = $stmt->fetch();

    // Company breakdown
    $companyQ = "SELECT
        t.company,
        COUNT(*) as count,
        COALESCE(SUM(t.amount_usd),0) as total_principal,
        COALESCE(SUM(t.fee),0) as total_fees,
        COALESCE(SUM(t.tax),0) as total_tax,
        COALESCE(SUM(t.amount_usd + COALESCE(t.fee,0) + COALESCE(t.tax,0)),0) as total_amount
        FROM transfers t
        LEFT JOIN clients c ON c.id = t.client_id
        WHERE {$where} AND t.company IS NOT NULL AND t.company != ''
        GROUP BY t.company ORDER BY total_principal DESC";
    $stmt = $pdo->prepare($companyQ);
    $stmt->execute($params);
    $companies = $stmt->fetchAll();

    // Top senders
    $topQ = "SELECT
        c.id, c.name, c.phone, c.monthly_limit, c.income_verified, c.sender_id_path,
        COUNT(t.id) as transfer_count,
        COALESCE(SUM(t.amount_usd),0) as total_sent,
        COALESCE(SUM(t.fee),0) as total_fees,
        MAX(t.date_sent) as last_transfer
        FROM transfers t
        JOIN clients c ON c.id = t.client_id
        WHERE {$where}
        GROUP BY c.id ORDER BY total_sent DESC LIMIT 100";
    $stmt = $pdo->prepare($topQ);
    $stmt->execute($params);
    $topSenders = $stmt->fetchAll();

    // FinCEN flagged (>= $3000 in period)
    $fincenCount = 0;
    foreach ($topSenders as $s) {
        if ((float)$s['total_sent'] >= 3000) $fincenCount++;
    }

    // Daily breakdown for charts
    $dayExpr = sql_date('t.date_sent');
    $dailyQ = "SELECT
        {$dayExpr} as day,
        COUNT(*) as count,
        COALESCE(SUM(t.amount_usd),0) as total
        FROM transfers t
        LEFT JOIN clients c ON c.id = t.client_id
        WHERE {$where}
        GROUP BY {$dayExpr} ORDER BY day";
    $stmt = $pdo->prepare($dailyQ);
    $stmt->execute($params);
    $dailyBreakdown = $stmt->fetchAll();

    // Store breakdown
    $storeQ = "SELECT
        s.id as store_id, s.name as store_name,
        COUNT(t.id) as count,
        COALESCE(SUM(t.amount_usd),0) as total
        FROM transfers t
        JOIN stores s ON s.id = t.store_id
        LEFT JOIN clients c ON c.id = t.client_id
        WHERE {$where}
        GROUP BY s.id ORDER BY total DESC";
    $stmt = $pdo->prepare($storeQ);
    $stmt->execute($params);
    $storeBreakdown = $stmt->fetchAll();

    // Transactions list (paginated)
    $countQ = "SELECT COUNT(*) FROM transfers t LEFT JOIN clients c ON c.id = t.client_id WHERE {$where}";
    $stmt = $pdo->prepare($countQ);
    $stmt->execute($params);
    $totalRows = (int)$stmt->fetchColumn();

    $txnQ = "SELECT
        t.id, t.date_sent, t.amount_usd, t.fee, t.tax,
        (t.amount_usd + COALESCE(t.fee,0) + COALESCE(t.tax,0)) as total,
        t.company, t.transaction_code, t.transaction_type, t.beneficiary, t.source,
        c.name as client_name, c.id as client_id,
        s.name as store_name
        FROM transfers t
        LEFT JOIN clients c ON c.id = t.client_id
        LEFT JOIN stores s ON s.id = t.store_id
        WHERE {$where}
        ORDER BY t.date_sent DESC
        LIMIT ? OFFSET ?";
    $txnParams = array_merge($params, [$limit, $offset]);
    $stmt = $pdo->prepare($txnQ);
    $stmt->execute($txnParams);
    $transactions = $stmt->fetchAll();

    // Barri reports in range
    $brWhere = 'br.report_date_from >= ? AND br.report_date_to <= ?';
    $brParams = [$dateFrom, $dateTo];
    if ($storeId) {
        $brWhere .= ' AND br.store_id = ?';
        $brParams[] = $storeId;
    }
    $brQ = "SELECT br.id, br.agency_name, br.agency_number, br.company, br.report_type, br.report_date_from as date_from, br.report_date_to as date_to, br.total_transactions, br.total_principal, br.total_agcomm as total_commission, br.beginning_balance, br.ending_balance, br.total_fees, br.total_tax, br.filename, br.original_name, br.status, br.created_at, s.name as store_name FROM barri_reports br LEFT JOIN stores s ON s.id = br.store_id WHERE {$brWhere} ORDER BY br.report_date_from DESC";
    $stmt = $pdo->prepare($brQ);
    $stmt->execute($brParams);
    $barriReports = $stmt->fetchAll();

    // Available companies for filter
    $companiesAvail = $pdo->query("SELECT DISTINCT company FROM transfers WHERE company IS NOT NULL AND company != '' ORDER BY company")->fetchAll(PDO::FETCH_COLUMN);

    // Available stores
    $stores = $pdo->query("SELECT id, name FROM stores WHERE " . sql_is_active() . " ORDER BY name")->fetchAll();

    json_response([
        'summary' => $summary,
        'companies' => $companies,
        'top_senders' => $topSenders,
        'fincen_flagged' => $fincenCount,
        'daily_breakdown' => $dailyBreakdown,
        'store_breakdown' => $storeBreakdown,
        'transactions' => $transactions,
        'barri_reports' => $barriReports,
        'total_rows' => $totalRows,
        'page' => $page,
        'pages' => (int)ceil($totalRows / $limit),
        'filters' => [
            'period' => $period,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'company' => $company,
            'store_id' => $storeId,
            'sender' => $sender,
        ],
        'available_companies' => $companiesAvail,
        'available_stores' => $stores,
    ]);
}

if ($method === 'POST') {
    csrf_verify();
    $data = get_json_body();
    $act = $data['action'] ?? '';

    if ($act === 'export') {
        $dateFrom = ($data['date_from'] ?? date('Y-m-01')) . ' 00:00:00';
        $dateTo = ($data['date_to'] ?? date('Y-m-t')) . ' 23:59:59';
        $company = $data['company'] ?? '';
        if (auth_is_admin()) {
            $storeId = !empty($data['store_id']) ? (int)$data['store_id'] : null;
        } else {
            $storeId = resolve_store_id(!empty($data['store_id']) ? (int)$data['store_id'] : null);
        }
        $sender = $data['sender'] ?? '';

        $where = 't.date_sent BETWEEN ? AND ?';
        $params = [$dateFrom, $dateTo];
        if ($company) { $where .= ' AND t.company = ?'; $params[] = $company; }
        if ($storeId) { $where .= ' AND t.store_id = ?'; $params[] = $storeId; }
        if ($sender) { $where .= ' AND c.name LIKE ?'; $params[] = '%' . $sender . '%'; }

        $filename = 'suly-envios-report-' . date('Y-m-d') . '.csv';
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $csv = function($out, $row) { fputcsv($out, $row, ',', '"', '\\'); };
        $out = fopen('php://output', 'w');
        $csv($out, ['Date', 'Store', 'Client', 'Beneficiary', 'Company', 'Reference', 'Type', 'Principal (USD)', 'Fee', 'Tax', 'Total', 'Source']);

        $q = "SELECT t.date_sent, s.name as store_name, c.name as client_name, t.beneficiary, t.company, t.transaction_code, t.transaction_type, t.amount_usd, t.fee, t.tax, (t.amount_usd + COALESCE(t.fee,0) + COALESCE(t.tax,0)) as total, t.source FROM transfers t LEFT JOIN clients c ON c.id = t.client_id LEFT JOIN stores s ON s.id = t.store_id WHERE {$where} ORDER BY t.date_sent";
        $stmt = $pdo->prepare($q);
        $stmt->execute($params);

        $rowCount = 0;
        $totalPrincipal = 0;
        $totalFees = 0;
        $totalTax = 0;
        $totalAmount = 0;

        while ($row = $stmt->fetch()) {
            $rowTotal = (float)$row['amount_usd'] + (float)($row['fee'] ?? 0) + (float)($row['tax'] ?? 0);
            $csv($out, [
                $row['date_sent'], $row['store_name'] ?? '', $row['client_name'] ?? '',
                $row['beneficiary'] ?? '', $row['company'] ?? '', $row['transaction_code'] ?? '',
                $row['transaction_type'] ?? '', $row['amount_usd'], $row['fee'] ?? 0,
                $row['tax'] ?? 0, number_format($rowTotal, 2, '.', ''), $row['source'] ?? 'manual'
            ]);
            $rowCount++;
            $totalPrincipal += (float)$row['amount_usd'];
            $totalFees += (float)($row['fee'] ?? 0);
            $totalTax += (float)($row['tax'] ?? 0);
            $totalAmount += $rowTotal;
        }

        $csv($out, []);
        $csv($out, ['TOTALS', '', '', '', '', '', '', number_format($totalPrincipal, 2, '.', ''), number_format($totalFees, 2, '.', ''), number_format($totalTax, 2, '.', ''), number_format($totalAmount, 2, '.', ''), $rowCount . ' records']);

        fclose($out);
        exit;
    }

    json_error('Unknown action');
}

json_error('Method not allowed', 405);
