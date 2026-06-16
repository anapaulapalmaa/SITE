<?php
/**
 * Arch3 — POST /api/buy-credits.php
 * Body JSON: { plan: "starter" | "plus" | "professional" | "pro" }
 *
 * Stripe pronto: se STRIPE_SECRET_KEY + price id estiverem configurados,
 * cria uma Checkout Session e devolve { checkout_url } para redirecionar.
 * Sem Stripe (modo dev/teste), credita imediatamente e devolve { user }.
 */
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/billing.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_fail('Method not allowed.', 405);
}

$user = require_user();
$in = read_input();
$planId = (string) ($in['plan'] ?? '');

$plan = arch3_plan($planId);
if (!$plan || $plan['type'] === 'free') {
    json_fail('Choose a valid plan.');
}

$prices = (array) cfg('STRIPE_PRICES', []);
$stripeReady = cfg('STRIPE_SECRET_KEY', '') !== '' && !empty($prices[$planId] ?? '');

if ($stripeReady) {
    try {
        $url = stripe_create_checkout($plan, (int) $user['id'], $user['email']);
        json_out(['mode' => 'stripe', 'checkout_url' => $url]);
    } catch (Throwable $e) {
        json_fail($e->getMessage(), 502);
    }
}

// ---- Modo dev/teste: credita direto (Stripe ainda não integrado) ----
$pdo = arch3_db();
$updated = apply_purchase($pdo, (int) $user['id'], $planId, 'dev', null);
json_out([
    'mode' => 'dev',
    'message' => 'Credits added (test mode — Stripe not yet connected).',
    'user' => user_public($updated),
]);
