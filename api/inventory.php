<?php
$user = auth_require();
$method = get_method();
$pdo = db();

if ($method === 'GET') {
    $storeId = resolve_store_filter(!empty($_GET['store_id']) ? (int)$_GET['store_id'] : null);
    $search = $_GET['search'] ?? '';
    $storeSql = store_filter_sql('i.store_id', $storeId);
    $where = 'WHERE 1=1' . $storeSql;
    $params = [];
    if ($storeId) $params[] = $storeId;
    if ($search) { $where .= ' AND i.product_name LIKE ?'; $params[] = '%' . $search . '%'; }

    $stmt = $pdo->prepare("SELECT i.*, s.name as store_name FROM inventory i LEFT JOIN stores s ON s.id = i.store_id {$where} ORDER BY i.product_name LIMIT 500");
    $stmt->execute($params);

    $lowWhere = 'WHERE 1=1' . store_filter_sql('store_id', $storeId) . ' AND quantity <= low_stock_threshold';
    $lowParams = $storeId ? [$storeId] : [];
    $lowStock = $pdo->prepare("SELECT COUNT(*) as cnt FROM inventory {$lowWhere}");
    $lowStock->execute($lowParams);

    json_response([
        'products'  => $stmt->fetchAll(),
        'low_stock' => (int)$lowStock->fetch()['cnt'],
        'scope'     => $storeId ? 'store' : 'all',
    ]);
}

if ($method === 'POST') {
    csrf_verify();
    $data = get_json_body();
    $storeId = resolve_store_id(!empty($data['store_id']) ? (int)$data['store_id'] : null);
    $act = $data['action'] ?? '';

    if ($act === 'create') {
        validate_required($data, ['product_name']);
        $stmt = $pdo->prepare('INSERT INTO inventory (store_id, product_name, quantity, description, cost_price, retail_price, low_stock_threshold) VALUES (?,?,?,?,?,?,?)');
        $stmt->execute([$storeId, sanitize($data['product_name']), (int)($data['quantity'] ?? 0), sanitize($data['description'] ?? ''), (float)($data['cost_price'] ?? 0), (float)($data['retail_price'] ?? 0), (int)($data['low_stock_threshold'] ?? 5)]);
        json_response(['success' => true, 'id' => sql_last_insert_id($pdo, 'inventory')], 201);
    }

    if ($act === 'update') {
        validate_required($data, ['id', 'product_name']);
        $stmt = $pdo->prepare('UPDATE inventory SET product_name = ?, quantity = ?, description = ?, cost_price = ?, retail_price = ?, low_stock_threshold = ? WHERE id = ? AND store_id = ?');
        $stmt->execute([sanitize($data['product_name']), (int)($data['quantity'] ?? 0), sanitize($data['description'] ?? ''), (float)($data['cost_price'] ?? 0), (float)($data['retail_price'] ?? 0), (int)($data['low_stock_threshold'] ?? 5), (int)$data['id'], $storeId]);
        json_response(['success' => true]);
    }

    if ($act === 'delete') {
        validate_required($data, ['id']);
        $pdo->prepare('DELETE FROM inventory WHERE id = ? AND store_id = ?')->execute([(int)$data['id'], $storeId]);
        json_response(['success' => true]);
    }

    json_error('Unknown action');
}

json_error('Method not allowed', 405);
