<?php
/**
 * Arch3 — POST /api/auth-register.php
 * Body JSON: { name, email, password }
 * Cria a conta (1 geração grátis), inicia sessão e notifica o admin.
 */
require_once __DIR__ . '/lib/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_fail('Method not allowed.', 405);
}

$in = read_input();
$name  = trim((string) ($in['name'] ?? ''));
$email = strtolower(trim((string) ($in['email'] ?? '')));
$pass  = (string) ($in['password'] ?? '');

if ($name === '' || mb_strlen($name) < 2) {
    json_fail('Please enter your name.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_fail('Please enter a valid email address.');
}
if (mb_strlen($pass) < 6) {
    json_fail('Password must be at least 6 characters.');
}

$pdo = arch3_db();

$stmt = $pdo->prepare('SELECT id FROM users WHERE email = :e LIMIT 1');
$stmt->execute([':e' => $email]);
if ($stmt->fetch()) {
    json_fail('An account with this email already exists. Try logging in.', 409);
}

$free = arch3_plan('free');
$now = now_str();
$country = detect_country();
$token = bin2hex(random_bytes(16)); // estrutura pronta para confirmação de e-mail futura
$isAdmin = is_admin_email($email) ? 1 : 0;

$stmt = $pdo->prepare(
    'INSERT INTO users
        (name, email, password_hash, created_at, credits_remaining, generations_used,
         subscription_plan, subscription_status, country, email_verified, email_verify_token, is_admin)
     VALUES
        (:name, :email, :hash, :created, :credits, 0,
         :plan, :status, :country, 0, :token, :admin)'
);
$stmt->execute([
    ':name'    => $name,
    ':email'   => $email,
    ':hash'    => password_hash($pass, PASSWORD_DEFAULT),
    ':created' => $now,
    ':credits' => $free['credits'],
    ':plan'    => 'free',
    ':status'  => 'inactive',
    ':country' => $country,
    ':token'   => $token,
    ':admin'   => $isAdmin,
]);
$id = (int) $pdo->lastInsertId();

// Inicia a sessão.
session_boot();
session_regenerate_id(true);
$_SESSION['user_id'] = $id;

// Notifica o administrador (best-effort).
notify_admin_signup([
    'name'       => $name,
    'email'      => $email,
    'country'    => $country,
    'created_at' => $now,
]);

$stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
$stmt->execute([':id' => $id]);
json_out(['user' => user_public($stmt->fetch())]);
