<?php
/**
 * Arch3 — lógica de crédito/compra, compartilhada entre o checkout em modo
 * dev e o webhook do Stripe. Fonte única de "o que acontece quando um plano
 * é pago".
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/plans.php';
require_once __DIR__ . '/helpers.php';

/**
 * Aplica a compra de um plano a um usuário: credita, registra a receita e
 * ajusta o plano atual / assinatura. Idempotência por 'reference' (ex.: id da
 * sessão do Stripe) evita creditar duas vezes no replay de webhook.
 *
 * @return array usuário atualizado (linha completa)
 */
function apply_purchase(PDO $pdo, int $userId, string $planId, string $provider = 'dev', ?string $reference = null): array
{
    $plan = arch3_plan($planId);
    if (!$plan || $plan['type'] === 'free') {
        throw new InvalidArgumentException('Invalid plan.');
    }

    // Idempotência: se já registramos esta referência, não credita de novo.
    if ($reference) {
        $chk = $pdo->prepare('SELECT id FROM purchases WHERE reference = :r LIMIT 1');
        $chk->execute([':r' => $reference]);
        if ($chk->fetch()) {
            $u = $pdo->prepare('SELECT * FROM users WHERE id = :id');
            $u->execute([':id' => $userId]);
            return $u->fetch();
        }
    }

    $now = now_str();

    if ($plan['type'] === 'subscription') {
        // Pro: assinatura mensal — define 100 créditos e a próxima renovação.
        $renews = date('Y-m-d H:i:s', strtotime('+1 month'));
        $stmt = $pdo->prepare(
            'UPDATE users
                SET subscription_plan = :plan,
                    subscription_status = :status,
                    subscription_renews_at = :renews,
                    credits_remaining = :credits
              WHERE id = :id'
        );
        $stmt->execute([
            ':plan'    => $plan['id'],
            ':status'  => 'active',
            ':renews'  => $renews,
            ':credits' => $plan['credits'],
            ':id'      => $userId,
        ]);
    } else {
        // Pacote de créditos (compra única): soma créditos e marca o plano atual.
        $stmt = $pdo->prepare(
            'UPDATE users
                SET credits_remaining = credits_remaining + :credits,
                    subscription_plan = :plan
              WHERE id = :id'
        );
        $stmt->execute([
            ':credits' => $plan['credits'],
            ':plan'    => $plan['id'],
            ':id'      => $userId,
        ]);
    }

    $ins = $pdo->prepare(
        'INSERT INTO purchases (user_id, plan, amount_cents, credits, provider, reference, created_at)
         VALUES (:uid, :plan, :amount, :credits, :provider, :ref, :created)'
    );
    $ins->execute([
        ':uid'      => $userId,
        ':plan'     => $plan['id'],
        ':amount'   => $plan['price'],
        ':credits'  => $plan['credits'],
        ':provider' => $provider,
        ':ref'      => $reference,
        ':created'  => $now,
    ]);

    $u = $pdo->prepare('SELECT * FROM users WHERE id = :id');
    $u->execute([':id' => $userId]);
    return $u->fetch();
}

/**
 * Cria uma Stripe Checkout Session via API REST (sem SDK — só cURL).
 * Retorna a URL de checkout. Lança RuntimeException em erro.
 */
function stripe_create_checkout(array $plan, int $userId, string $customerEmail): string
{
    $secret = (string) cfg('STRIPE_SECRET_KEY', '');
    $prices = (array) cfg('STRIPE_PRICES', []);
    $priceId = $prices[$plan['id']] ?? '';
    if ($secret === '' || $priceId === '') {
        throw new RuntimeException('Stripe is not configured for this plan.');
    }

    $mode = $plan['type'] === 'subscription' ? 'subscription' : 'payment';
    $fields = [
        'mode'                          => $mode,
        'line_items[0][price]'          => $priceId,
        'line_items[0][quantity]'       => 1,
        'success_url'                   => cfg('CHECKOUT_SUCCESS_URL', 'https://arch3.net/try-it.html?purchase=success'),
        'cancel_url'                    => cfg('CHECKOUT_CANCEL_URL', 'https://arch3.net/try-it.html?purchase=cancel'),
        'client_reference_id'           => (string) $userId,
        'customer_email'                => $customerEmail,
        'metadata[user_id]'             => (string) $userId,
        'metadata[plan]'                => $plan['id'],
    ];

    $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $secret],
        CURLOPT_TIMEOUT        => 20,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode((string) $resp, true);
    if ($code !== 200 || empty($data['url'])) {
        $msg = $data['error']['message'] ?? 'Stripe checkout failed.';
        throw new RuntimeException($msg);
    }
    return $data['url'];
}
