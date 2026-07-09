<?php

/**
 * Manual admin-managed company risk flags (global, not session-scoped).
 */

require_once __DIR__ . '/sql.php';
require_once __DIR__ . '/security.php';

function company_flag_normalize_key(string $label): string {
    $label = trim($label);
    if ($label === '') {
        return '';
    }
    $label = preg_replace('/\s+/u', ' ', $label) ?? $label;
    return mb_strtoupper($label, 'UTF-8');
}

function company_flags_table_exists(PDO $pdo): bool {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'pgsql') {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM information_schema.tables
             WHERE table_schema = 'public' AND table_name = 'company_flags'"
        );
        $stmt->execute();
        return (bool)$stmt->fetchColumn();
    }

    $stmt = $pdo->prepare(
        "SELECT 1 FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = 'company_flags'"
    );
    $stmt->execute();
    return (bool)$stmt->fetchColumn();
}

function company_flags_list_active(PDO $pdo): array {
    if (!company_flags_table_exists($pdo)) {
        return [];
    }

    $stmt = $pdo->query(
        "SELECT cf.*, u.name AS flagged_by_name
         FROM company_flags cf
         LEFT JOIN users u ON u.id = cf.flagged_by_user_id
         WHERE cf.is_active = " . sql_bool(true) . "
         ORDER BY cf.flagged_at DESC, cf.company_label ASC"
    );
    return $stmt->fetchAll() ?: [];
}

function company_flag_get_active(PDO $pdo, string $companyKey): ?array {
    if ($companyKey === '' || !company_flags_table_exists($pdo)) {
        return null;
    }

    $stmt = $pdo->prepare(
        "SELECT cf.*, u.name AS flagged_by_name
         FROM company_flags cf
         LEFT JOIN users u ON u.id = cf.flagged_by_user_id
         WHERE cf.company_key = ? AND cf.is_active = " . sql_bool(true) . "
         LIMIT 1"
    );
    $stmt->execute([$companyKey]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function company_flags_map_for_labels(PDO $pdo, array $labels): array {
    if (!company_flags_table_exists($pdo) || $labels === []) {
        return [];
    }

    $keys = [];
    foreach ($labels as $label) {
        $key = company_flag_normalize_key((string)$label);
        if ($key !== '') {
            $keys[$key] = true;
        }
    }
    if ($keys === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $stmt = $pdo->prepare(
        "SELECT cf.*, u.name AS flagged_by_name
         FROM company_flags cf
         LEFT JOIN users u ON u.id = cf.flagged_by_user_id
         WHERE cf.is_active = " . sql_bool(true) . " AND cf.company_key IN ($placeholders)"
    );
    $stmt->execute(array_keys($keys));

    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[$row['company_key']] = $row;
    }
    return $map;
}

function company_flag_set(PDO $pdo, string $label, string $reason, int $userId): array {
    $label = trim($label);
    $reason = trim($reason);
    if ($label === '') {
        json_error('Company name is required', 400);
    }
    if ($reason === '') {
        json_error('Reason is required', 400);
    }
    if (!company_flags_table_exists($pdo)) {
        json_error('Company flags are not available yet', 503);
    }

    $key = company_flag_normalize_key($label);
    $existing = company_flag_get_active($pdo, $key);
    if ($existing) {
        $stmt = $pdo->prepare(
            'UPDATE company_flags
             SET company_label = ?, reason = ?, flagged_by_user_id = ?, flagged_at = ' . sql_now() . '
             WHERE id = ?'
        );
        $stmt->execute([$label, sanitize($reason), $userId, (int)$existing['id']]);
        if ($stmt->rowCount() < 1) {
            json_error('Flag not updated', 404);
        }
        return company_flag_get_active($pdo, $key) ?? $existing;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO company_flags (company_key, company_label, reason, flagged_by_user_id, is_active)
         VALUES (?, ?, ?, ?, ' . sql_bool(true) . ')'
    );
    $stmt->execute([$key, $label, sanitize($reason), $userId]);
    $created = company_flag_get_active($pdo, $key);
    if (!$created) {
        json_error('Flag not created', 500);
    }
    return $created;
}

function company_flag_clear(PDO $pdo, string $companyKey, int $userId): void {
    $companyKey = company_flag_normalize_key($companyKey);
    if ($companyKey === '') {
        json_error('Company key is required', 400);
    }
    if (!company_flags_table_exists($pdo)) {
        json_error('Company flags are not available yet', 503);
    }

    $stmt = $pdo->prepare(
        'UPDATE company_flags
         SET is_active = ' . sql_bool(false) . ', cleared_at = ' . sql_now() . ', cleared_by_user_id = ?
         WHERE company_key = ? AND is_active = ' . sql_bool(true)
    );
    $stmt->execute([$userId, $companyKey]);
    if ($stmt->rowCount() < 1) {
        json_error('Active flag not found', 404);
    }
}

function company_flag_require_admin(): void {
    require_once __DIR__ . '/auth.php';
    if (!auth_is_admin()) {
        json_error('Admin access required', 403);
    }
}
