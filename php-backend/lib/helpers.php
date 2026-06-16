<?php
/**
 * Arch3 — utilidades compartilhadas: respostas JSON, leitura de input,
 * detecção de país e e-mail de notificação.
 */

require_once __DIR__ . '/config.php';

function json_out($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function json_fail(string $message, int $status = 400, array $extra = []): void
{
    json_out(array_merge(['error' => $message], $extra), $status);
}

/** Lê o corpo da requisição como JSON (ou cai para $_POST). */
function read_input(): array
{
    $raw = file_get_contents('php://input');
    if ($raw) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return $_POST ?? [];
}

function now_str(): string
{
    return date('Y-m-d H:i:s');
}

function client_ip(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = trim(explode(',', $_SERVER[$k])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return '';
}

/**
 * Best-effort: país do usuário a partir do IP.
 * Usa header do Cloudflare se presente; senão consulta ip-api.com com timeout
 * curto. Falha silenciosamente para 'Unknown' — nunca bloqueia o cadastro.
 */
function detect_country(): string
{
    if (!empty($_SERVER['HTTP_CF_IPCOUNTRY']) && $_SERVER['HTTP_CF_IPCOUNTRY'] !== 'XX') {
        return strtoupper($_SERVER['HTTP_CF_IPCOUNTRY']);
    }
    $ip = client_ip();
    if ($ip === '' || strpos($ip, '127.') === 0 || $ip === '::1') {
        return 'Unknown';
    }
    $ctx = stream_context_create(['http' => ['timeout' => 2]]);
    $resp = @file_get_contents("http://ip-api.com/json/{$ip}?fields=country", false, $ctx);
    if ($resp) {
        $data = json_decode($resp, true);
        if (!empty($data['country'])) {
            return $data['country'];
        }
    }
    return 'Unknown';
}

/** Notifica o administrador sobre um novo cadastro (PHP mail, best-effort). */
function notify_admin_signup(array $user): void
{
    if (!cfg('NOTIFY_SIGNUPS', true)) {
        return;
    }
    $admin = cfg('ADMIN_EMAIL', '');
    if (!$admin) {
        return;
    }
    $from = cfg('MAIL_FROM', 'no-reply@arch3.net');
    $subject = 'New Arch3 Signup';
    $body =
        "Name: {$user['name']}\n" .
        "Email: {$user['email']}\n" .
        "Country: " . ($user['country'] ?? 'Unknown') . "\n" .
        "Date: {$user['created_at']}\n";
    $headers = "From: Arch3 <{$from}>\r\nContent-Type: text/plain; charset=utf-8\r\n";
    @mail($admin, $subject, $body, $headers);
}
