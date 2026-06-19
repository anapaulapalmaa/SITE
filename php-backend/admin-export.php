<?php
/**
 * Arch3 — GET /api/admin-export.php  (protegido: admin)
 * Download CSV: name,email,signup_date,country,user_type,environment,plan,credits_remaining,generations_used
 */
require_once __DIR__ . '/lib/auth.php';

require_admin();
$pdo = arch3_db();

$rows = $pdo->query(
    'SELECT name, email, created_at, country, user_type, primary_environment,
            email_verified, subscription_plan, credits_remaining, generations_used
       FROM users
   ORDER BY created_at DESC'
)->fetchAll();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="arch3-leads.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, [
    'name', 'email', 'signup_date', 'country', 'user_type', 'environment',
    'email_verified', 'plan', 'credits_remaining', 'generations_used',
]);
foreach ($rows as $r) {
    $plan = arch3_plan($r['subscription_plan'] ?: 'free') ?? arch3_plan('free');
    fputcsv($out, [
        $r['name'],
        $r['email'],
        $r['created_at'],
        $r['country'] ?: 'Unknown',
        $r['user_type'] ?: '',
        $r['primary_environment'] ?: '',
        ((int) $r['email_verified'] === 1) ? 'Yes' : 'No',
        $plan['label'],
        (int) $r['credits_remaining'],
        (int) $r['generations_used'],
    ]);
}
fclose($out);
exit;
