<?php
/**
 * Arch3 — POST /api/auth-login.php
 * Body JSON: { email, password }
 */
require_once __DIR__ . '/lib/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_fail('Method not allowed.', 405);
}

$in = read_input();
$email = strtolower(trim((string) ($in['email'] ?? '')));
$pass  = (string) ($in['password'] ?? '');

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $pass === '') {
    json_fail('Enter your email and password.');
}

$pdo = arch3_db();
$stmt = $pdo->prepare('SELECT * FROM users WHERE email = :e LIMIT 1');
$stmt->execute([':e' => $email]);
$user = $stmt->fetch();

if (!$user || !password_verify($pass, $user['password_hash'])) {
    json_fail('Invalid email or password.', 401);
}

// E-mail precisa estar verificado antes de liberar login (exceto admin).
if ((int) ($user['email_verified'] ?? 0) !== 1 && !is_admin_email($email)) {
    json_fail('Please verify your email before logging in.', 403, [
        'code'  => 'email_unverified',
        'email' => $email,
    ]);
}

// Promove a admin se o e-mail bater com ADMIN_EMAIL (caso tenha mudado).
if (is_admin_email($email) && (int) $user['is_admin'] !== 1) {
    $pdo->prepare('UPDATE users SET is_admin = 1 WHERE id = :id')->execute([':id' => $user['id']]);
    $user['is_admin'] = 1;
}

$pdo->prepare('UPDATE users SET last_login = :t WHERE id = :id')
    ->execute([':t' => now_str(), ':id' => $user['id']]);

session_boot();
session_regenerate_id(true);
$_SESSION['user_id'] = (int) $user['id'];

apply_pro_reset($pdo, $user);
json_out(['user' => user_public($user)]);
