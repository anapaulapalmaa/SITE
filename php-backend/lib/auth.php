<?php
/**
 * Arch3 — sessão, usuário atual e guardas de acesso.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/plans.php';
require_once __DIR__ . '/helpers.php';

function session_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name(cfg('SESSION_NAME', 'arch3_session'));
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 30,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function is_admin_email(string $email): bool
{
    $admin = strtolower(trim((string) cfg('ADMIN_EMAIL', '')));
    return $admin !== '' && strtolower(trim($email)) === $admin;
}

/**
 * Assinatura Pro: reset automático mensal sem cron.
 * Se o usuário é Pro e a data de renovação passou, recarrega 100 créditos
 * e empurra a renovação para o próximo mês (em loop, caso tenha pulado meses).
 */
function apply_pro_reset(PDO $pdo, array &$user): void
{
    if (($user['subscription_plan'] ?? '') !== 'pro') {
        return;
    }
    if (($user['subscription_status'] ?? '') !== 'active') {
        return;
    }
    $renews = $user['subscription_renews_at'] ?? null;
    if (!$renews) {
        return;
    }
    $now = time();
    $renewTs = strtotime($renews);
    if ($renewTs > $now) {
        return;
    }
    $pro = arch3_plan('pro');
    $next = $renewTs;
    while ($next <= $now) {
        $next = strtotime('+1 month', $next);
    }
    $nextStr = date('Y-m-d H:i:s', $next);
    $stmt = $pdo->prepare(
        'UPDATE users SET credits_remaining = :c, subscription_renews_at = :r WHERE id = :id'
    );
    $stmt->execute([':c' => $pro['credits'], ':r' => $nextStr, ':id' => $user['id']]);
    $user['credits_remaining'] = $pro['credits'];
    $user['subscription_renews_at'] = $nextStr;
}

function current_user(): ?array
{
    session_boot();
    $uid = $_SESSION['user_id'] ?? null;
    if (!$uid) {
        return null;
    }
    $pdo = arch3_db();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $uid]);
    $user = $stmt->fetch();
    if (!$user) {
        unset($_SESSION['user_id']);
        return null;
    }
    apply_pro_reset($pdo, $user);
    return $user;
}

function require_user(): array
{
    $user = current_user();
    if (!$user) {
        json_fail('Authentication required.', 401);
    }
    return $user;
}

function require_admin(): array
{
    $user = require_user();
    if ((int) ($user['is_admin'] ?? 0) !== 1 && !is_admin_email($user['email'])) {
        json_fail('Admin access required.', 403);
    }
    return $user;
}

/** Representação segura do usuário para o frontend (sem hash de senha). */
function user_public(array $user): array
{
    $planId = $user['subscription_plan'] ?: 'free';
    $plan = arch3_plan($planId) ?? arch3_plan('free');
    return [
        'id'                => (int) $user['id'],
        'name'              => $user['name'],
        'email'             => $user['email'],
        'credits_remaining' => (int) $user['credits_remaining'],
        'generations_used'  => (int) $user['generations_used'],
        'plan'              => $planId,
        'plan_label'        => $plan['label'],
        'plan_name'         => $plan['name'],
        'subscription_status' => $user['subscription_status'] ?? 'inactive',
        'subscription_renews_at' => $user['subscription_renews_at'] ?? null,
        'is_admin'          => ((int) ($user['is_admin'] ?? 0) === 1) || is_admin_email($user['email']),
        'email_verified'    => (int) ($user['email_verified'] ?? 0) === 1,
    ];
}
