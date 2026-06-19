<?php
/**
 * Arch3 — POST /api/auth-verify.php
 * Body JSON: { email, code }
 *
 * Confirma o código de verificação. Em caso de sucesso: marca email_verified=1,
 * concede 1 crédito grátis, inicia a sessão e devolve o usuário.
 *
 * Segurança: não revela se o e-mail existe; limita tentativas (anti brute force).
 */
require_once __DIR__ . '/lib/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_fail('Method not allowed.', 405);
}

$MAX_ATTEMPTS = 5;

$in = read_input();
$email = strtolower(trim((string) ($in['email'] ?? '')));
$code  = preg_replace('/\D+/', '', (string) ($in['code'] ?? ''));

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $code === '') {
    json_fail('Invalid verification code. Please try again.', 400, ['code' => 'invalid']);
}

$pdo = arch3_db();
$stmt = $pdo->prepare('SELECT * FROM users WHERE email = :e LIMIT 1');
$stmt->execute([':e' => $email]);
$user = $stmt->fetch();

// Não revela existência da conta.
if (!$user) {
    json_fail('Invalid verification code. Please try again.', 400, ['code' => 'invalid']);
}

if ((int) $user['email_verified'] === 1) {
    json_fail('This account is already verified. Please log in.', 409, ['code' => 'already_verified']);
}

// Sem código ativo -> precisa reenviar.
if (empty($user['verification_code_hash']) || empty($user['verification_code_expires_at'])) {
    json_fail('This verification code has expired. Please request a new one.', 410, ['code' => 'expired']);
}

// Bloqueio por excesso de tentativas.
if ((int) $user['verification_attempts'] >= $MAX_ATTEMPTS) {
    json_fail('Too many attempts. Please request a new code.', 429, ['code' => 'locked']);
}

// Expiração (15 min).
if (strtotime($user['verification_code_expires_at']) < time()) {
    json_fail('This verification code has expired. Please request a new one.', 410, ['code' => 'expired']);
}

// Código incorreto.
if (!password_verify($code, $user['verification_code_hash'])) {
    $pdo->prepare('UPDATE users SET verification_attempts = verification_attempts + 1 WHERE id = :id')
        ->execute([':id' => $user['id']]);
    json_fail('Invalid verification code. Please try again.', 400, ['code' => 'invalid']);
}

// Sucesso: ativa a conta e concede o crédito grátis (somente agora).
$free = arch3_plan('free');
$pdo->prepare(
    'UPDATE users
        SET email_verified = 1,
            credits_remaining = :credits,
            verification_code_hash = NULL,
            verification_code_expires_at = NULL,
            verification_attempts = 0,
            last_login = :t
      WHERE id = :id'
)->execute([':credits' => $free['credits'], ':t' => now_str(), ':id' => $user['id']]);

// Inicia a sessão (login liberado).
session_boot();
session_regenerate_id(true);
$_SESSION['user_id'] = (int) $user['id'];

$stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
$stmt->execute([':id' => $user['id']]);
json_out([
    'status' => 'ok',
    'user'   => user_public($stmt->fetch()),
]);
