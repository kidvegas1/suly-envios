<?php
/**
 * Pre-deploy gate: suly_ledger must have status/paid_at/paid_by_user_id columns.
 * Run: php scripts/check-ledger-schema.php
 */
declare(strict_types=1);

require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';

$pdo = db();
$required = ['status', 'paid_at', 'paid_by_user_id'];
$placeholders = implode(',', array_fill(0, count($required), '?'));
$stmt = $pdo->prepare(
    "SELECT column_name FROM information_schema.columns
     WHERE table_schema = 'public' AND table_name = 'suly_ledger' AND column_name IN ($placeholders)"
);
$stmt->execute($required);
$found = $stmt->fetchAll(PDO::FETCH_COLUMN);
$missing = array_values(array_diff($required, $found));

if ($missing === []) {
    echo "OK: suly_ledger settlement columns present\n";
    exit(0);
}

fwrite(STDERR, "MISSING columns on suly_ledger: " . implode(', ', $missing) . "\n");
fwrite(STDERR, "Run as table owner: psql \"\$DATABASE_URL\" -f migrate-suly-ledger-status.sql\n");
exit(1);
