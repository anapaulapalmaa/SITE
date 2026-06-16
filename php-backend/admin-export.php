<?php
/**
 * Arch3 — GET /api/admin-export.php  (protegido: admin)
 * Download CSV: name,email,created_at,plan,generations_used
 */
require_once __DIR__ . '/lib/auth.php';

require_admin();
$pdo = arch3_db();

$rows = $pdo->query(
    'SELECT name, email, created_at, subscription_plan, generations_used
       FROM users
   ORDER BY created_at DESC'
)->fetchAll();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="arch3-leads.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, ['name', 'email', 'created_at', 'plan', 'generations_used']);
foreach ($rows as $r) {
    $plan = arch3_plan($r['subscription_plan'] ?: 'free') ?? arch3_plan('free');
    fputcsv($out, [
        $r['name'],
        $r['email'],
        $r['created_at'],
        $plan['label'],
        (int) $r['generations_used'],
    ]);
}
fclose($out);
exit;
