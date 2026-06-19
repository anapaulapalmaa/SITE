<?php
/**
 * Arch3 — POST /api/auth-resend.php
 * Body JSON: { email }
 *
 * Gera um NOVO código (invalida o anterior) e reenvia por e-mail.
 * Limite: 3 reenvios por hora por e-mail. Resposta neutra para não revelar
 * se a conta existe.
 */
require_once __DIR__ . '/lib/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_fail('Method not allowed.', 405);
}

$MAX_PER_HOUR = 3;
$NEUTRAL = ['status' => 'ok', 'message' => 'If the account exists and is not verified, a new code has been sent.'];

$in = read_input();
$email = strtolower(trim((string) ($in['email'] ?? '')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_fail('Please enter a valid email address.', 400);
}

$pdo = arch3_db();
$stmt = $pdo->prepare('SELECT * FROM users WHERE email = :e LIMIT 1');
$stmt->execute([':e' => $email]);
$user = $stmt->fetch();

// Conta inexistente ou já verificada -> resposta neutra (anti-enumeração).
if (!$user || (int) $user['email_verified'] === 1) {
    json_out($NEUTRAL);
}

// Janela de 1h: zera o contador se o último envio foi há mais de uma hora.
$count = (int) $user['verification_resend_count'];
$lastSent = $user['last_verification_sent_at'] ? strtotime($user['last_verification_sent_at']) : 0;
$withinHour = $lastSent && (time() - $lastSent) < 3600;
if (!$withinHour) {
    $count = 0;
}
if ($count >= $MAX_PER_HOUR) {
    json_fail('You have requested too many codes. Please wait a while before trying again.', 429, ['code' => 'rate_limited']);
}

// Gera novo código (invalida o anterior), reseta tentativas, atualiza janela.
$code = arch3_generate_code();
$codeHash = password_hash($code, PASSWORD_DEFAULT);
$expires = date('Y-m-d H:i:s', time() + 15 * 60);
$now = now_str();

$pdo->prepare(
    'UPDATE users
        SET verification_code_hash = :h,
            verification_code_expires_at = :exp,
            verification_attempts = 0,
            verification_resend_count = :count,
            last_verification_sent_at = :now
      WHERE id = :id'
)->execute([
    ':h'     => $codeHash,
    ':exp'   => $expires,
    ':count' => $count + 1,
    ':now'   => $now,
    ':id'    => $user['id'],
]);

send_verification_email($email, $code);
json_out($NEUTRAL);
