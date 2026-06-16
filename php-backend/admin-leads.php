<?php
/**
 * Arch3 — GET /api/admin-leads.php  (protegido: admin)
 * Lista de leads/usuários para a tabela do painel.
 */
require_once __DIR__ . '/lib/auth.php';

require_admin();
$pdo = arch3_db();

$rows = $pdo->query(
    'SELECT id, name, email, created_at, country, generations_used,
            subscription_plan, credits_remaining
       FROM users
   ORDER BY created_at DESC'
)->fetchAll();

$leads = array_map(function ($r) {
    $plan = arch3_plan($r['subscription_plan'] ?: 'free') ?? arch3_plan('free');
    return [
        'id'                => (int) $r['id'],
        'name'              => $r['name'],
        'email'             => $r['email'],
        'signup_date'       => $r['created_at'],
        'country'           => $r['country'] ?: 'Unknown',
        'generations_used'  => (int) $r['generations_used'],
        'plan'              => $plan['label'],
        'credits_remaining' => (int) $r['credits_remaining'],
    ];
}, $rows);

json_out(['leads' => $leads]);
