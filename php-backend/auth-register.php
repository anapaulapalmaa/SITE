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

// Captura de lead: tipo de usuário e ambiente principal (listas controladas).
$USER_TYPES = ['Homeowner', 'Architect', 'Interior Designer', 'Real Estate', 'Student', 'Other'];
$ENVIRONMENTS = ['Living Room', 'Bedroom', 'Kitchen', 'Office', 'Bathroom', 'Entire Home', 'Other'];
$userType = trim((string) ($in['user_type'] ?? ''));
$primaryEnv = trim((string) ($in['primary_environment'] ?? ''));
$userType = in_array($userType, $USER_TYPES, true) ? $userType : null;
$primaryEnv = in_array($primaryEnv, $ENVIRONMENTS, true) ? $primaryEnv : null;

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

$now = now_str();
$country = detect_country();
$token = bin2hex(random_bytes(16));
$isAdmin = is_admin_email($email) ? 1 : 0;

// Verificação de e-mail obrigatória: crédito grátis só após confirmar o código.
// A conta nasce com 0 crédito e email_verified=0; não inicia sessão aqui.
$code = arch3_generate_code();
$codeHash = password_hash($code, PASSWORD_DEFAULT);
$expires = date('Y-m-d H:i:s', time() + 15 * 60);

$stmt = $pdo->prepare(
    'INSERT INTO users
        (name, email, password_hash, created_at, credits_remaining, generations_used,
         subscription_plan, subscription_status, country, user_type, primary_environment,
         email_verified, email_verify_token, is_admin,
         verification_code_hash, verification_code_expires_at, verification_attempts,
         verification_resend_count, last_verification_sent_at)
     VALUES
        (:name, :email, :hash, :created, 0, 0,
         :plan, :status, :country, :utype, :penv, 0, :token, :admin,
         :chash, :cexp, 0, 0, :csent)'
);
$stmt->execute([
    ':name'    => $name,
    ':email'   => $email,
    ':hash'    => password_hash($pass, PASSWORD_DEFAULT),
    ':created' => $now,
    ':plan'    => 'free',
    ':status'  => 'inactive',
    ':country' => $country,
    ':utype'   => $userType,
    ':penv'    => $primaryEnv,
    ':token'   => $token,
    ':admin'   => $isAdmin,
    ':chash'   => $codeHash,
    ':cexp'    => $expires,
    ':csent'   => $now,
]);
$id = (int) $pdo->lastInsertId();

// Envia o código por e-mail (best-effort) e notifica o admin do novo cadastro.
$sent = send_verification_email($email, $code);
notify_admin_signup([
    'name'       => $name,
    'email'      => $email,
    'country'    => $country,
    'created_at' => $now,
]);

// NÃO loga o usuário ainda — ele precisa inserir o código primeiro.
json_out([
    'status'     => 'verify',
    'email'      => $email,
    'email_sent' => (bool) $sent,
    'message'    => 'We sent a verification code to your email.',
]);
