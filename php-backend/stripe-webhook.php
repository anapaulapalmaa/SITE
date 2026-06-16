<?php
/**
 * Arch3 — POST /api/stripe-webhook.php
 * Endpoint pronto para receber eventos do Stripe (checkout.session.completed
 * e invoice.paid para renovação Pro). Verifica a assinatura se o
 * STRIPE_WEBHOOK_SECRET estiver configurado.
 *
 * Enquanto o Stripe não está conectado, este arquivo fica inerte — só passa a
 * creditar quando os eventos reais chegarem.
 */
require_once __DIR__ . '/lib/billing.php';
require_once __DIR__ . '/lib/helpers.php';

$payload = file_get_contents('php://input');
$secret = (string) cfg('STRIPE_WEBHOOK_SECRET', '');

// Verificação da assinatura do Stripe (HMAC-SHA256) quando configurada.
if ($secret !== '') {
    $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
    if (!stripe_verify_signature($payload, $sigHeader, $secret)) {
        json_fail('Invalid signature.', 400);
    }
}

$event = json_decode($payload, true);
if (!is_array($event) || empty($event['type'])) {
    json_fail('Invalid payload.', 400);
}

$pdo = arch3_db();

try {
    switch ($event['type']) {
        case 'checkout.session.completed':
            $obj = $event['data']['object'] ?? [];
            $userId = (int) ($obj['metadata']['user_id'] ?? $obj['client_reference_id'] ?? 0);
            $planId = (string) ($obj['metadata']['plan'] ?? '');
            $ref = (string) ($obj['id'] ?? '');
            if ($userId && $planId) {
                apply_purchase($pdo, $userId, $planId, 'stripe', $ref);
            }
            break;

        case 'invoice.paid':
            // Renovação mensal Pro: o reset on-access já cobre os créditos,
            // mas registramos a receita recorrente aqui.
            $obj = $event['data']['object'] ?? [];
            $email = strtolower((string) ($obj['customer_email'] ?? ''));
            $ref = (string) ($obj['id'] ?? '');
            if ($email) {
                $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :e LIMIT 1');
                $stmt->execute([':e' => $email]);
                $row = $stmt->fetch();
                if ($row) {
                    apply_purchase($pdo, (int) $row['id'], 'pro', 'stripe', $ref);
                }
            }
            break;
    }
} catch (Throwable $e) {
    // Não vaza detalhes; Stripe re-tenta em erro 5xx.
    json_fail('Webhook handling failed.', 500);
}

json_out(['received' => true]);

function stripe_verify_signature(string $payload, string $header, string $secret): bool
{
    $parts = [];
    foreach (explode(',', $header) as $kv) {
        $p = explode('=', $kv, 2);
        if (count($p) === 2) {
            $parts[trim($p[0])] = trim($p[1]);
        }
    }
    if (empty($parts['t']) || empty($parts['v1'])) {
        return false;
    }
    $signed = $parts['t'] . '.' . $payload;
    $expected = hash_hmac('sha256', $signed, $secret);
    return hash_equals($expected, $parts['v1']);
}
