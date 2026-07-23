<?php
$user = auth_require_admin();
$method = get_method();
$pdo = db();
require_once __DIR__ . '/../includes/reconciliation.php';

function recon_parse_dates(): array {
    $dateFrom = $_GET['date_from'] ?? $_POST['date_from'] ?? date('Y-m-01');
    $dateTo = $_GET['date_to'] ?? $_POST['date_to'] ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        json_error('Invalid date range');
    }
    if ($dateFrom > $dateTo) {
        json_error('date_from must be before date_to');
    }
    return [$dateFrom, $dateTo];
}

if ($method === 'GET') {
    $storeId = resolve_store_id(!empty($_GET['store_id']) ? (int)$_GET['store_id'] : null);

    $action = $_GET['action'] ?? 'summary';
    [$dateFrom, $dateTo] = recon_parse_dates();

    if ($action === 'summary') {
        $summary = recon_summary_with_cents($pdo, $storeId, $dateFrom, $dateTo);
        $totalCents = round(array_sum(array_column($summary, 'cents_lost')), 2);
        json_response([
            'store_id'    => $storeId,
            'date_from'   => $dateFrom,
            'date_to'     => $dateTo,
            'summary'     => $summary,
            'total_cents' => $totalCents,
        ]);
    }

    if ($action === 'detail') {
        $company = !empty($_GET['company']) ? sanitize($_GET['company']) : null;
        $rows = recon_cambio_detail($pdo, $storeId, $dateFrom, $dateTo, $company);
        json_response([
            'store_id'  => $storeId,
            'date_from' => $dateFrom,
            'date_to'   => $dateTo,
            'company'   => $company,
            'rows'      => $rows,
            'count'     => count($rows),
        ]);
    }

    if ($action === 'variances') {
        $status = $_GET['status'] ?? 'open';
        if (!in_array($status, ['open', 'reviewed', 'all'], true)) {
            json_error('Invalid status');
        }
        $variances = recon_list_variances($pdo, $storeId, $status);
        json_response([
            'store_id'  => $storeId,
            'status'    => $status,
            'variances' => $variances,
        ]);
    }

    json_error('Unknown action');
}

if ($method === 'POST') {
    csrf_verify();
    $data = get_json_body();
    $storeId = resolve_store_id(!empty($data['store_id']) ? (int)$data['store_id'] : null);
    $act = $data['action'] ?? '';

    if ($act === 'post_cents') {
        validate_required($data, ['company', 'period_month', 'date_from', 'date_to']);
        $result = recon_post_cents_to_ledger(
            $pdo,
            $storeId,
            sanitize($data['company']),
            sanitize($data['period_month']),
            $data['date_from'],
            $data['date_to']
        );
        if (empty($result['success'])) {
            json_error($result['error'] ?? 'Failed to post cents');
        }
        json_response($result);
    }

    if ($act === 'dismiss_variance') {
        validate_required($data, ['id']);
        $ok = recon_dismiss_variance($pdo, (int)$data['id'], $storeId, (int)$user['id']);
        if (!$ok) {
            json_error('Variance not found or already reviewed', 404);
        }
        json_response(['success' => true]);
    }

    if ($act === 'refresh_variances') {
        $sessionDate = $data['session_date'] ?? date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sessionDate)) {
            json_error('Invalid session_date');
        }
        $variances = recon_compare_caja_to_reports($pdo, $storeId, $sessionDate, !empty($data['import_id']) ? (int)$data['import_id'] : null);
        json_response(['success' => true, 'variances' => $variances, 'count' => count($variances)]);
    }

    json_error('Unknown action');
}

json_error('Method not allowed', 405);
