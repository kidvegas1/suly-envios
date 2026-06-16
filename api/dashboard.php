<?php
auth_require();

if (get_method() !== 'GET') json_error('Method not allowed', 405);

$pdo = db();
$storeId = resolve_store_filter(!empty($_GET['store_id']) ? (int)$_GET['store_id'] : null);

$storeSql = $storeId ? ' AND store_id = ?' : '';
$storeParams = $storeId ? [$storeId] : [];

$todaySessions = $pdo->prepare('SELECT COUNT(*) as cnt, COALESCE(SUM(closing_balance),0) as total FROM caja_sessions WHERE session_date = ' . sql_curdate() . $storeSql);
$todaySessions->execute($storeParams);
$caja = $todaySessions->fetch();

$clientCount = $pdo->prepare('SELECT COUNT(DISTINCT c.id) as cnt FROM clients c JOIN transfers t ON t.client_id = c.id WHERE 1=1' . ($storeId ? ' AND t.store_id = ?' : ''));
$clientCount->execute($storeParams);
$clients = $clientCount->fetch();

$monthTransfers = $pdo->prepare('SELECT COUNT(*) as cnt, COALESCE(SUM(amount_usd),0) as total FROM transfers WHERE ' . sql_same_month_year('date_sent') . $storeSql);
$monthTransfers->execute($storeParams);
$transfers = $monthTransfers->fetch();

$clockedIn = $pdo->prepare('SELECT COUNT(*) as cnt FROM clock_ins WHERE ' . sql_date_eq_today('clock_in_time') . " AND status = 'clocked_in'" . $storeSql);
$clockedIn->execute($storeParams);
$clocked = $clockedIn->fetch();

$upcomingEvents = $pdo->prepare('SELECT client_name, event_date, deposit, balance, status FROM events WHERE event_date >= ' . sql_curdate() . $storeSql . ' ORDER BY event_date LIMIT 5');
$upcomingEvents->execute($storeParams);
$events = $upcomingEvents->fetchAll();

$ledgerBalance = $pdo->prepare('SELECT COALESCE(SUM(owed_to_suly),0) as owed_to_suly, COALESCE(SUM(suly_owes),0) as suly_owes FROM suly_ledger WHERE 1=1' . $storeSql);
$ledgerBalance->execute($storeParams);
$ledger = $ledgerBalance->fetch();

json_response([
    'store_id'            => $storeId,
    'scope'               => $storeId ? 'store' : 'all',
    'caja_sessions_today' => (int)$caja['cnt'],
    'caja_total_today'    => (float)$caja['total'],
    'total_clients'       => (int)$clients['cnt'],
    'month_transfers'     => (int)$transfers['cnt'],
    'month_transfer_usd'  => (float)$transfers['total'],
    'clocked_in_today'    => (int)$clocked['cnt'],
    'upcoming_events'     => $events,
    'ledger_owed_to_suly' => (float)$ledger['owed_to_suly'],
    'ledger_suly_owes'    => (float)$ledger['suly_owes'],
]);
