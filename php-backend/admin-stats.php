<?php
/**
 * Arch3 — GET /api/admin-stats.php  (protegido: admin)
 * Métricas do painel: usuários, gerações, receita do mês e segmentação.
 */
require_once __DIR__ . '/lib/auth.php';

require_admin();
$pdo = arch3_db();

$totalUsers = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$totalGenerations = (int) $pdo->query('SELECT COUNT(*) FROM generations')->fetchColumn();

// Receita do mês corrente (centavos -> dólares).
$monthStart = date('Y-m-01 00:00:00');
$stmt = $pdo->prepare('SELECT COALESCE(SUM(amount_cents),0) FROM purchases WHERE created_at >= :s');
$stmt->execute([':s' => $monthStart]);
$monthlyRevenueCents = (int) $stmt->fetchColumn();

$freeUsers = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE subscription_plan = 'free'")->fetchColumn();
$proSubscribers = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE subscription_plan = 'pro' AND subscription_status = 'active'")->fetchColumn();
$paidUsers = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE subscription_plan <> 'free'")->fetchColumn();

json_out([
    'total_users'         => $totalUsers,
    'total_generations'   => $totalGenerations,
    'monthly_revenue'     => 'US$' . number_format($monthlyRevenueCents / 100, 2),
    'monthly_revenue_cents' => $monthlyRevenueCents,
    'free_users'          => $freeUsers,
    'paid_users'          => $paidUsers,
    'pro_subscribers'     => $proSubscribers,
]);
