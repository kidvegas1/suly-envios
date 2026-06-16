<?php
$user = auth_require();
$method = get_method();
$pdo = db();

function barri_auto_match_store(PDO $pdo, array $data): ?int {
    $parsedAgency = trim($data['agency_number'] ?? '');
    if ($parsedAgency) {
        $storeMatch = $pdo->prepare('SELECT id FROM stores WHERE (barri_agency_number = ? OR viamericas_agency_number = ? OR intercambio_agency_number = ? OR intermex_agency_number = ?) AND ' . sql_is_active() . ' LIMIT 1');
        $storeMatch->execute([$parsedAgency, $parsedAgency, $parsedAgency, $parsedAgency]);
        $autoStore = $storeMatch->fetch();
        if ($autoStore) {
            return (int)$autoStore['id'];
        }
    }

    $reportAddr = strtolower(preg_replace('/[^a-z0-9]+/i', ' ', trim($data['agency_address'] ?? '')));
    $reportName = strtolower(trim($data['agency_name'] ?? ''));
    $reportStoreName = strtolower(trim($data['store_name'] ?? ''));

    if (!$reportAddr && !$reportName && !$reportStoreName) {
        return null;
    }

    $allStores = $pdo->query('SELECT id, name, address FROM stores WHERE ' . sql_is_active())->fetchAll();
    $bestId = null;
    $bestScore = 0;

    foreach ($allStores as $s) {
        $score = 0;
        $sAddr = strtolower(preg_replace('/[^a-z0-9]+/', ' ', trim($s['address'] ?? '')));
        $sName = strtolower(trim($s['name'] ?? ''));

        if ($reportAddr && $sAddr && strlen($reportAddr) > 5 && strlen($sAddr) > 5) {
            $rTokens = array_filter(explode(' ', $reportAddr), fn($t) => strlen($t) > 1);
            $hits = 0;
            foreach ($rTokens as $tok) {
                if (str_contains($sAddr, $tok)) $hits++;
            }
            if (count($rTokens) > 0) {
                $addrScore = $hits / count($rTokens);
                if ($addrScore >= 0.5) $score = max($score, $addrScore * 10);
            }
        }

        $namesToCheck = array_filter([$reportName, $reportStoreName]);
        foreach ($namesToCheck as $rn) {
            if (!$rn || !$sName) continue;
            if (str_contains($rn, $sName) || str_contains($sName, $rn)) {
                $score = max($score, 8);
            } else {
                $sWords = array_filter(explode(' ', $sName), fn($w) => strlen($w) > 2);
                $matched = 0;
                foreach ($sWords as $w) { if (str_contains($rn, $w)) $matched++; }
                if (count($sWords) > 0 && $matched / count($sWords) >= 0.5) {
                    $score = max($score, 5 * ($matched / count($sWords)));
                }
            }
        }

        if ($score > $bestScore) { $bestScore = $score; $bestId = (int)$s['id']; }
    }

    return ($bestId && $bestScore >= 3) ? $bestId : null;
}

function barri_normalize_report_data(array $data): array {
    $data['report_date_from'] = $data['report_date_from'] ?? $data['date_from'] ?? null;
    $data['report_date_to'] = $data['report_date_to'] ?? $data['date_to'] ?? null;
    $totals = $data['totals'] ?? [];
    $data['total_transactions'] = $data['total_transactions'] ?? ($totals['qty'] ?? 0);
    $data['total_principal'] = $data['total_principal'] ?? ($totals['principal'] ?? 0);
    $data['total_fees'] = $data['total_fees'] ?? ($totals['fee'] ?? 0);
    $data['total_tax'] = $data['total_tax'] ?? ($totals['tax'] ?? 0);
    $data['total_amount'] = $data['total_amount'] ?? ($totals['total'] ?? 0);
    $data['total_agcomm'] = $data['total_agcomm'] ?? ($totals['agcomm'] ?? 0);
    if (empty($data['agency_name'])) $data['agency_name'] = 'Unknown Agency';
    if (empty($data['report_date_from'])) $data['report_date_from'] = date('Y-m-d');
    if (empty($data['report_date_to'])) $data['report_date_to'] = $data['report_date_from'];
    return $data;
}

function barri_report_label(array $data): string {
    $agency = trim($data['agency_number'] ?? '') ?: trim($data['agency_name'] ?? 'Report');
    return $agency . ' (' . ($data['report_date_from'] ?? '?') . ' — ' . ($data['report_date_to'] ?? '?') . ')';
}

function barri_import_report(PDO $pdo, array $user, array $data, array $options = []): array {
    $explicitStore = $options['explicit_store'] ?? null;
    $pdfFile = $options['pdf_file'] ?? null;
    $skipDuplicates = $options['skip_duplicates'] ?? true;
    $sourceLabel = $options['source_label'] ?? barri_report_label($data);

    try {
        $data = barri_normalize_report_data($data);
        validate_required($data, ['agency_name', 'report_date_from', 'report_date_to', 'transactions']);

        $autoStoreId = barri_auto_match_store($pdo, $data);
        $unassigned = $autoStoreId === null;

        if ($autoStoreId) {
            $storeId = $autoStoreId;
            auth_require_store_access($storeId);
        } elseif ($explicitStore) {
            $storeId = (int)$explicitStore;
            auth_require_store_access($storeId);
        } else {
            $storeId = resolve_store_id();
        }

        $pdfPath = '';
        $originalName = '';
        if (!empty($pdfFile) && ($pdfFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $pdfPath = upload_file($pdfFile, 'barri-reports') ?: '';
            $originalName = $pdfFile['name'] ?? '';
        }

        $dateFrom = $data['report_date_from'];
        $dateTo = $data['report_date_to'];
        $agencyNum = sanitize($data['agency_number'] ?? '');

        if ($skipDuplicates) {
            $dup = $pdo->prepare('SELECT id FROM barri_reports WHERE store_id = ? AND agency_number = ? AND report_date_from = ? AND report_date_to = ?');
            $dup->execute([$storeId, $agencyNum, $dateFrom, $dateTo]);
            $existing = $dup->fetch();
            if ($existing) {
                return [
                    'status' => 'duplicate',
                    'label' => $sourceLabel,
                    'report_id' => (int)$existing['id'],
                    'store_id' => $storeId,
                    'unassigned' => $unassigned,
                    'auto_matched' => !$unassigned,
                    'error' => 'Duplicate report for agency and date range',
                ];
            }
        }

        $company = sanitize($data['company'] ?? 'Barri');
        $storeName = sanitize($data['store_name'] ?? '');
        $arExec = sanitize($data['ar_executive'] ?? '');
        $reportPhone = sanitize($data['phone'] ?? '');

        $reportType = 'barri';
        $companyLower = strtolower($company);
        if (str_contains($companyLower, 'viamerica')) $reportType = 'viamericas';
        elseif (str_contains($companyLower, 'intermex')) $reportType = 'intermex';
        elseif (str_contains($companyLower, 'intercambio')) $reportType = 'intercambio';
        elseif (str_contains($companyLower, 'ria')) $reportType = 'ria';

        $pdo->beginTransaction();

        $ins = $pdo->prepare('INSERT INTO barri_reports (store_id, user_id, agency_number, agency_name, agency_address, company, store_name, ar_executive, phone, report_date_from, report_date_to, beginning_balance, ending_balance, total_transactions, total_principal, total_fees, total_tax, total_amount, total_agcomm, filename, original_name, status, report_type) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $ins->execute([
            $storeId, $user['id'], $agencyNum, sanitize($data['agency_name']),
            sanitize($data['agency_address'] ?? ''), $company, $storeName, $arExec, $reportPhone,
            $dateFrom, $dateTo,
            (float)($data['beginning_balance'] ?? 0), (float)($data['ending_balance'] ?? 0),
            (int)($data['total_transactions'] ?? 0), (float)($data['total_principal'] ?? 0),
            (float)($data['total_fees'] ?? 0), (float)($data['total_tax'] ?? 0),
            (float)($data['total_amount'] ?? 0), (float)($data['total_agcomm'] ?? 0),
            $pdfPath, sanitize($originalName),
            'pending', $reportType
        ]);
        $reportId = sql_last_insert_id($pdo, 'barri_reports');

        $txnIns = $pdo->prepare('INSERT INTO barri_transactions (report_id, store_id, client_id, transaction_time, transaction_date, transaction_type, reference_number, customer_name, beneficiary_name, description, operator, quantity, principal, fee, tax, total, running_balance, ag_commission, variable_fee, variable_fx, matched, amount_received, received_currency, paying_bank, destination_country, destination_state, destination_city, payment_date, transaction_status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $transferIns = $pdo->prepare('INSERT INTO transfers (client_id, store_id, transaction_code, beneficiary, date_sent, amount_usd, fee, tax, company, transaction_type, source) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $markPushed = $pdo->prepare('UPDATE barri_transactions SET pushed_to_transfers = ' . sql_bool(true) . ', transfer_id = ? WHERE id = ?');
        $clientLookup = $pdo->prepare('SELECT id, name FROM clients WHERE LOWER(name) = LOWER(?) LIMIT 2');

        $matchedCount = 0;
        $unmatchedCount = 0;
        $createdCount = 0;
        $pushedCount = 0;
        foreach ($data['transactions'] as $txn) {
            $custName = trim($txn['customer_name'] ?? '');
            if (!$custName) continue;

            $txnDate = !empty($txn['transaction_date']) ? sanitize($txn['transaction_date']) : $dateFrom;

            $clientId = null;
            $isMatched = 0;
            $clientLookup->execute([$custName]);
            $matches = $clientLookup->fetchAll();
            if (count($matches) === 1) {
                $clientId = (int)$matches[0]['id'];
                $isMatched = 1;
                $matchedCount++;
            } elseif (count($matches) === 0) {
                $pdo->prepare('INSERT INTO clients (name, phone, monthly_limit, notes) VALUES (?,?,3000,?)')
                    ->execute([sanitize($custName), '', 'Auto-created from ' . $company . ' report']);
                $clientId = sql_last_insert_id($pdo, 'clients');
                $isMatched = 1;
                $matchedCount++;
                $createdCount++;
            } else {
                $unmatchedCount++;
            }

            $typeMap = ['giros' => 'giros', 'money order' => 'money_order', 'bill payment' => 'bill_payment', 'nueva_orden' => 'nueva_orden', 'cheque_escaneado' => 'cheque_escaneado'];
            $rawType = strtolower(trim($txn['transaction_type'] ?? $txn['type'] ?? 'giros'));
            $txnType = $typeMap[$rawType] ?? $rawType;

            $txnTime = $txn['transaction_time'] ?? $txn['time'] ?? '00:00';
            if (strlen($txnTime) === 5) $txnTime .= ':00';

            $principal = (float)($txn['principal'] ?? 0);
            $txnFee = (float)($txn['fee'] ?? 0);
            $txnTax = (float)($txn['tax'] ?? 0);
            $txnTotal = (float)($txn['total'] ?? 0);
            $refNum = sanitize($txn['reference_number'] ?? $txn['reference'] ?? '');

            $beneficiaryName = sanitize($txn['beneficiary_name'] ?? $txn['beneficiary'] ?? '');
            $txnDescription = sanitize($txn['description'] ?? '');

            $amountReceived = (float)($txn['amount_received'] ?? 0);
            $receivedCurrency = sanitize($txn['received_currency'] ?? '');
            $payingBank = sanitize($txn['paying_bank'] ?? '');
            $destCountry = sanitize($txn['destination_country'] ?? '');
            $destState = sanitize($txn['destination_state'] ?? '');
            $destCity = sanitize($txn['destination_city'] ?? '');
            $paymentDate = !empty($txn['payment_date']) ? sanitize($txn['payment_date']) : null;
            $txnStatus = sanitize($txn['transaction_status'] ?? $txn['status'] ?? '');

            $txnIns->execute([
                $reportId, $storeId, $clientId,
                $txnTime, $txnDate, $txnType,
                $refNum, sanitize($custName),
                $beneficiaryName, $txnDescription,
                sanitize($txn['operator'] ?? ''), (int)($txn['quantity'] ?? $txn['qty'] ?? 1),
                $principal, $txnFee, $txnTax, $txnTotal,
                (float)($txn['running_balance'] ?? $txn['balance'] ?? 0),
                (float)($txn['ag_commission'] ?? $txn['agcomm'] ?? 0),
                (float)($txn['variable_fee'] ?? $txn['var_fee'] ?? 0),
                (float)($txn['variable_fx'] ?? $txn['var_fx'] ?? 0),
                db_bool($isMatched === 1),
                $amountReceived, $receivedCurrency, $payingBank,
                $destCountry, $destState, $destCity,
                $paymentDate, $txnStatus
            ]);
            $barriTxnId = sql_last_insert_id($pdo, 'barri_transactions');

            if ($isMatched && $clientId) {
                $typeLabel = str_replace('_', ' ', $txnType);
                $dateSent = $txnDate . ' ' . $txnTime;
                $transferBeneficiary = $beneficiaryName ?: sanitize($custName);
                $transferCompany = $company ?: 'Barri';
                $transferIns->execute([
                    $clientId, $storeId, $refNum,
                    $transferBeneficiary, $dateSent, $principal,
                    $txnFee, $txnTax, $transferCompany, $typeLabel, 'report_import'
                ]);
                $transferId = sql_last_insert_id($pdo, 'transfers');
                $markPushed->execute([$transferId, $barriTxnId]);
                $pushedCount++;
            }
        }

        $pdo->prepare('UPDATE barri_reports SET status = ? WHERE id = ?')->execute(['processed', $reportId]);
        $pdo->commit();

        return [
            'status' => 'success',
            'label' => $sourceLabel,
            'report_id' => $reportId,
            'store_id' => $storeId,
            'unassigned' => $unassigned,
            'auto_matched' => !$unassigned,
            'total_transactions' => $matchedCount + $unmatchedCount,
            'matched_count' => $matchedCount,
            'unmatched_count' => $unmatchedCount,
            'clients_created' => $createdCount,
            'pushed_to_transfers' => $pushedCount,
        ];
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return [
            'status' => 'failed',
            'label' => $sourceLabel,
            'report_id' => null,
            'store_id' => null,
            'unassigned' => false,
            'auto_matched' => false,
            'error' => $e->getMessage(),
        ];
    }
}

function barri_bulk_import_reports(PDO $pdo, array $user, array $reports, array $options = []): array {
    $skipDuplicates = $options['skip_duplicates'] ?? true;
    $defaultExplicitStore = $options['explicit_store'] ?? null;
    $pdfFiles = $options['pdf_files'] ?? [];

    $summary = [
        'success' => 0,
        'failed' => 0,
        'skipped' => 0,
        'unassigned' => 0,
        'results' => [],
    ];

    foreach ($reports as $index => $reportData) {
        if (!is_array($reportData)) {
            $summary['failed']++;
            $summary['results'][] = [
                'index' => $index,
                'status' => 'failed',
                'label' => 'Item ' . ($index + 1),
                'error' => 'Invalid report payload',
            ];
            continue;
        }

        $explicitStore = $defaultExplicitStore;
        if (!empty($reportData['store_id'])) {
            $explicitStore = (int)$reportData['store_id'];
        }

        $pdfFile = null;
        if (isset($pdfFiles[$index])) {
            $pdfFile = $pdfFiles[$index];
        }

        $sourceLabel = $reportData['source_label'] ?? $reportData['original_name'] ?? barri_report_label($reportData);
        $result = barri_import_report($pdo, $user, $reportData, [
            'explicit_store' => $explicitStore,
            'pdf_file' => $pdfFile,
            'skip_duplicates' => $skipDuplicates,
            'source_label' => $sourceLabel,
        ]);
        $result['index'] = $index;

        if ($result['status'] === 'success') {
            $summary['success']++;
            if (!empty($result['unassigned'])) {
                $summary['unassigned']++;
            }
        } elseif ($result['status'] === 'duplicate') {
            $summary['skipped']++;
        } else {
            $summary['failed']++;
        }

        $summary['results'][] = $result;
    }

    return $summary;
}

function barri_collect_uploaded_pdf_files(): array {
    $files = [];
    if (empty($_FILES)) {
        return $files;
    }

    if (!empty($_FILES['pdf_files'])) {
        $raw = $_FILES['pdf_files'];
        if (is_array($raw['name'])) {
            foreach ($raw['name'] as $i => $name) {
                $files[] = [
                    'name' => $name,
                    'type' => $raw['type'][$i] ?? '',
                    'tmp_name' => $raw['tmp_name'][$i] ?? '',
                    'error' => $raw['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                    'size' => $raw['size'][$i] ?? 0,
                ];
            }
        } else {
            $files[] = $raw;
        }
    }

    foreach ($_FILES as $key => $raw) {
        if (!str_starts_with($key, 'pdf_file_')) continue;
        if (is_array($raw['name'])) {
            foreach ($raw['name'] as $i => $name) {
                $files[] = [
                    'name' => $name,
                    'type' => $raw['type'][$i] ?? '',
                    'tmp_name' => $raw['tmp_name'][$i] ?? '',
                    'error' => $raw['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                    'size' => $raw['size'][$i] ?? 0,
                ];
            }
        } else {
            $files[] = $raw;
        }
    }

    return $files;
}

if ($method === 'GET') {
    // Download original PDF
    if (!empty($_GET['download'])) {
        $stmt = $pdo->prepare('SELECT store_id, filename, original_name FROM barri_reports WHERE id = ?');
        $stmt->execute([(int)$_GET['download']]);
        $r = $stmt->fetch();
        if (!$r || !$r['filename']) json_error('PDF not available for this report', 404);
        auth_require_store_access((int)$r['store_id']);
        storage_serve($r['filename'], [
            'content_type' => 'application/pdf',
            'download_name' => $r['original_name'] ?: 'report.pdf',
        ]);
    }

    $id = $_GET['id'] ?? null;

    if ($id) {
        $stmt = $pdo->prepare('SELECT *, report_date_from as date_from, report_date_to as date_to FROM barri_reports WHERE id = ?');
        $stmt->execute([(int)$id]);
        $report = $stmt->fetch();
        if (!$report) json_error('Report not found', 404);
        auth_require_store_access((int)$report['store_id']);

        $txns = $pdo->prepare('SELECT bt.*, bt.transaction_time as time, bt.transaction_type as type, bt.reference_number as reference, bt.ag_commission as agcomm, bt.variable_fee as var_fee, bt.variable_fx as var_fx, bt.amount_received, bt.received_currency, bt.paying_bank, bt.destination_country, bt.destination_state, bt.destination_city, bt.payment_date, bt.transaction_status, c.name as client_name, c.phone as client_phone FROM barri_transactions bt LEFT JOIN clients c ON c.id = bt.client_id WHERE bt.report_id = ? ORDER BY bt.transaction_date, bt.transaction_time');
        $txns->execute([(int)$id]);
        $transactions = $txns->fetchAll();

        $total = count($transactions);
        $matched = 0;
        $pushed = 0;
        foreach ($transactions as $t) {
            if ($t['matched']) $matched++;
            if ($t['pushed_to_transfers']) $pushed++;
        }

        json_response([
            'report' => $report,
            'transactions' => $transactions,
            'match_stats' => [
                'total' => $total,
                'matched' => $matched,
                'unmatched' => $total - $matched,
                'pushed' => $pushed,
            ],
        ]);
    }

    $requestedStore = !empty($_GET['store_id']) ? (int)$_GET['store_id'] : null;
    if (auth_is_admin() && !$requestedStore) {
        $stmt = $pdo->query('SELECT br.*, br.report_date_from as date_from, br.report_date_to as date_to, br.company, br.report_type, u.name as user_name, (SELECT COUNT(*) FROM barri_transactions bt WHERE bt.report_id = br.id AND ' . sql_is_true('bt.matched') . ') as matched_count, (SELECT COUNT(*) FROM barri_transactions bt WHERE bt.report_id = br.id AND ' . sql_is_true('bt.pushed_to_transfers') . ') as pushed_count FROM barri_reports br LEFT JOIN users u ON u.id = br.user_id ORDER BY br.report_date_from DESC LIMIT 200');
        json_response(['reports' => $stmt->fetchAll()]);
    }
    $storeId = resolve_store_id($requestedStore);
    $stmt = $pdo->prepare('SELECT br.*, br.report_date_from as date_from, br.report_date_to as date_to, br.company, br.report_type, u.name as user_name, (SELECT COUNT(*) FROM barri_transactions bt WHERE bt.report_id = br.id AND ' . sql_is_true('bt.matched') . ') as matched_count, (SELECT COUNT(*) FROM barri_transactions bt WHERE bt.report_id = br.id AND ' . sql_is_true('bt.pushed_to_transfers') . ') as pushed_count FROM barri_reports br LEFT JOIN users u ON u.id = br.user_id WHERE br.store_id = ? ORDER BY br.report_date_from DESC LIMIT 100');
    $stmt->execute([$storeId]);
    json_response(['reports' => $stmt->fetchAll()]);
}

if ($method === 'POST') {
    csrf_verify();

    // Handle multipart import (PDF file + JSON data)
    if (!empty($_POST['action']) && $_POST['action'] === 'import') {
        set_time_limit(120);
        $data = json_decode($_POST['data'] ?? '{}', true) ?: [];
        if (empty($data)) json_error('No report data received. The payload may be too large.');

        $explicitStore = null;
        if (!empty($_POST['store_id'])) {
            $explicitStore = (int)$_POST['store_id'];
        }
        if (!empty($data['store_id'])) {
            $explicitStore = (int)$data['store_id'];
        }
        if (!auth_is_admin()) {
            $explicitStore = null;
        }

        $pdfFile = (!empty($_FILES['pdf_file']) && $_FILES['pdf_file']['error'] === UPLOAD_ERR_OK) ? $_FILES['pdf_file'] : null;
        $result = barri_import_report($pdo, $user, $data, [
            'explicit_store' => $explicitStore,
            'pdf_file' => $pdfFile,
            'skip_duplicates' => true,
        ]);

        if ($result['status'] === 'duplicate') {
            json_error($result['error'] ?? 'A report for this agency and date range already exists', 409);
        }
        if ($result['status'] === 'failed') {
            json_error($result['error'] ?? 'Import failed', 400);
        }

        json_response([
            'success' => true,
            'report_id' => $result['report_id'],
            'total_transactions' => $result['total_transactions'],
            'matched_count' => $result['matched_count'],
            'unmatched_count' => $result['unmatched_count'],
            'clients_created' => $result['clients_created'],
            'pushed_to_transfers' => $result['pushed_to_transfers'],
            'unassigned' => $result['unassigned'],
        ], 201);
    }

    if (!empty($_POST['action']) && $_POST['action'] === 'bulk_import') {
        auth_require_admin();
        set_time_limit(600);

        $reports = json_decode($_POST['data'] ?? $_POST['reports'] ?? '[]', true);
        if (!is_array($reports) || count($reports) === 0) {
            json_error('No report payloads received for bulk import', 400);
        }

        $explicitStore = !empty($_POST['store_id']) ? (int)$_POST['store_id'] : null;
        $skipDuplicates = !isset($_POST['skip_duplicates']) || $_POST['skip_duplicates'] !== '0';

        $summary = barri_bulk_import_reports($pdo, $user, $reports, [
            'explicit_store' => $explicitStore,
            'skip_duplicates' => $skipDuplicates,
            'pdf_files' => barri_collect_uploaded_pdf_files(),
        ]);

        json_response($summary);
    }

    // All other POST actions use JSON body
    $data = get_json_body();
    $act = $data['action'] ?? '';

    if ($act === 'bulk_import') {
        auth_require_admin();
        set_time_limit(600);

        $reports = $data['reports'] ?? [];
        if (!is_array($reports) || count($reports) === 0) {
            json_error('reports array is required for bulk import', 400);
        }

        $explicitStore = !empty($data['store_id']) ? (int)$data['store_id'] : null;
        $skipDuplicates = !array_key_exists('skip_duplicates', $data) || (bool)$data['skip_duplicates'];

        $summary = barri_bulk_import_reports($pdo, $user, $reports, [
            'explicit_store' => $explicitStore,
            'skip_duplicates' => $skipDuplicates,
        ]);

        json_response($summary);
    }

    $requestedStore = !empty($data['store_id']) ? (int)$data['store_id'] : null;
    $storeId = resolve_store_id($requestedStore);

    if ($act === 'match') {
        validate_required($data, ['transaction_id', 'client_id']);
        $pdo->prepare('UPDATE barri_transactions SET client_id = ?, matched = ' . sql_bool(true) . ' WHERE id = ? AND store_id = ?')
            ->execute([(int)$data['client_id'], (int)$data['transaction_id'], $storeId]);
        json_response(['success' => true]);
    }

    if ($act === 'unmatch') {
        validate_required($data, ['transaction_id']);
        $pdo->prepare('UPDATE barri_transactions SET client_id = NULL, matched = ' . sql_bool(false) . ' WHERE id = ? AND store_id = ?')
            ->execute([(int)$data['transaction_id'], $storeId]);
        json_response(['success' => true]);
    }

    if ($act === 'create_client_and_match') {
        validate_required($data, ['transaction_id']);
        $txn = $pdo->prepare('SELECT * FROM barri_transactions WHERE id = ? AND store_id = ?');
        $txn->execute([(int)$data['transaction_id'], $storeId]);
        $txnRow = $txn->fetch();
        if (!$txnRow) json_error('Transaction not found', 404);

        $pdo->prepare('INSERT INTO clients (name, phone, monthly_limit) VALUES (?,?,3000)')
            ->execute([sanitize($txnRow['customer_name']), sanitize($data['phone'] ?? '')]);
        $newClientId = sql_last_insert_id($pdo, 'clients');

        $pdo->prepare('UPDATE barri_transactions SET client_id = ?, matched = ' . sql_bool(true) . ' WHERE id = ?')
            ->execute([$newClientId, (int)$data['transaction_id']]);

        json_response(['success' => true, 'client_id' => $newClientId]);
    }

    if ($act === 'search_clients') {
        $q = '%' . ($data['query'] ?? '') . '%';
        $stmt = $pdo->prepare(
            'SELECT DISTINCT c.id, c.name, c.phone FROM clients c
             INNER JOIN transfers t ON t.client_id = c.id AND t.store_id = ?
             WHERE c.name LIKE ? ORDER BY c.name LIMIT 20'
        );
        $stmt->execute([$storeId, $q]);
        json_response(['clients' => $stmt->fetchAll()]);
    }

    if ($act === 'push_to_transfers') {
        validate_required($data, ['report_id']);
        $reportId = (int)$data['report_id'];

        $report = $pdo->prepare('SELECT * FROM barri_reports WHERE id = ? AND store_id = ?');
        $report->execute([$reportId, $storeId]);
        $reportRow = $report->fetch();
        if (!$reportRow) json_error('Report not found', 404);
        $reportCompany = $reportRow['company'] ?? 'Barri';

        $txns = $pdo->prepare('SELECT * FROM barri_transactions WHERE report_id = ? AND ' . sql_is_true('matched') . ' AND ' . sql_is_false('pushed_to_transfers'));
        $txns->execute([$reportId]);
        $rows = $txns->fetchAll();

        $transferIns = $pdo->prepare('INSERT INTO transfers (client_id, store_id, transaction_code, beneficiary, date_sent, amount_usd, fee, tax, company, transaction_type, source) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $markPushed = $pdo->prepare('UPDATE barri_transactions SET pushed_to_transfers = ' . sql_bool(true) . ', transfer_id = ? WHERE id = ?');

        $pushedCount = 0;
        foreach ($rows as $row) {
            $typeLabel = str_replace('_', ' ', $row['transaction_type']);
            $dateSent = $row['transaction_date'] . ' ' . $row['transaction_time'];
            $beneficiary = $row['beneficiary_name'] ?: $row['customer_name'];
            $transferIns->execute([
                $row['client_id'], $storeId, $row['reference_number'],
                $beneficiary, $dateSent, $row['principal'],
                $row['fee'], $row['tax'], $reportCompany, $typeLabel, 'report_import'
            ]);
            $transferId = sql_last_insert_id($pdo, 'transfers');
            $markPushed->execute([$transferId, $row['id']]);
            $pushedCount++;
        }

        json_response(['success' => true, 'pushed_count' => $pushedCount]);
    }

    if ($act === 'delete') {
        validate_required($data, ['report_id']);
        $pdo->prepare('DELETE FROM barri_reports WHERE id = ? AND store_id = ?')
            ->execute([(int)$data['report_id'], $storeId]);
        json_response(['success' => true]);
    }

    json_error('Unknown action');
}

json_error('Method not allowed', 405);
