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

/**
 * Codifica um cabeçalho com possíveis caracteres não-ASCII (RFC 2047).
 */
function arch3_mime_header(string $text): string
{
    if (preg_match('/[^\x20-\x7E]/', $text)) {
        return '=?UTF-8?B?' . base64_encode($text) . '?=';
    }
    return $text;
}

/**
 * Envia um e-mail. Tenta SMTP autenticado (se configurado) e cai para mail()
 * caso falhe. Retorna true se algum método aceitou a mensagem.
 */
function arch3_send_mail(string $to, string $subject, string $body): bool
{
    if (arch3_smtp_send($to, $subject, $body)) {
        return true;
    }
    // Fallback: PHP mail() (best-effort).
    $from = cfg('MAIL_FROM', 'no-reply@arch3.net');
    $fromName = (string) cfg('MAIL_FROM_NAME', 'Arch3');
    $headers = 'From: ' . arch3_mime_header($fromName) . " <{$from}>\r\n" .
        "Reply-To: {$from}\r\n" .
        "MIME-Version: 1.0\r\n" .
        "Content-Type: text/plain; charset=utf-8\r\n";
    return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
}

/**
 * Cliente SMTP mínimo e sem dependências (AUTH LOGIN, SSL em 465 ou STARTTLS
 * em 587). Como o PHP roda no mesmo servidor do mailserver, conecta por padrão
 * em localhost (evita o DNS da Cloudflare) e não verifica o certificado.
 * Retorna true somente se o servidor aceitou a mensagem (250 após o DATA).
 */
function arch3_smtp_send(string $to, string $subject, string $body): bool
{
    $host = (string) cfg('SMTP_HOST', '');
    $user = (string) cfg('SMTP_USER', '');
    $pass = (string) cfg('SMTP_PASS', '');
    if ($host === '' || $user === '' || $pass === '') {
        return false; // SMTP não configurado
    }
    $port = (int) cfg('SMTP_PORT', 465);
    $secure = strtolower((string) cfg('SMTP_SECURE', 'ssl')); // 'ssl' | 'tls'
    $fromEmail = (string) cfg('MAIL_FROM', $user);
    $fromName = (string) cfg('MAIL_FROM_NAME', 'Arch3');
    $timeout = (int) cfg('SMTP_TIMEOUT', 20);
    $ehloName = 'arch3.net';

    $ctx = stream_context_create(['ssl' => [
        'verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true,
    ]]);
    $target = ($secure === 'ssl') ? "ssl://{$host}:{$port}" : "tcp://{$host}:{$port}";
    $fp = @stream_socket_client($target, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        error_log("[arch3] SMTP connect failed ({$target}): {$errno} {$errstr}");
        return false;
    }
    stream_set_timeout($fp, $timeout);

    $read = function () use ($fp) {
        $data = '';
        while (($line = fgets($fp, 600)) !== false) {
            $data .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') break;
        }
        return $data;
    };
    $put = function ($c) use ($fp) { fwrite($fp, $c . "\r\n"); };
    $code = function ($resp) { return (int) substr((string) $resp, 0, 3); };

    $ok = false;
    try {
        if ($code($read()) !== 220) { fclose($fp); return false; }
        $put("EHLO {$ehloName}");
        if ($code($read()) !== 250) { fclose($fp); return false; }

        if ($secure === 'tls') {
            $put('STARTTLS');
            if ($code($read()) !== 220) { fclose($fp); return false; }
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT)) {
                fclose($fp); return false;
            }
            $put("EHLO {$ehloName}");
            if ($code($read()) !== 250) { fclose($fp); return false; }
        }

        $put('AUTH LOGIN');
        if ($code($read()) !== 334) { fclose($fp); return false; }
        $put(base64_encode($user));
        if ($code($read()) !== 334) { fclose($fp); return false; }
        $put(base64_encode($pass));
        if ($code($read()) !== 235) { error_log('[arch3] SMTP auth failed'); fclose($fp); return false; }

        $put("MAIL FROM:<{$fromEmail}>");
        if ($code($read()) !== 250) { fclose($fp); return false; }
        $put("RCPT TO:<{$to}>");
        $rcpt = $code($read());
        if ($rcpt !== 250 && $rcpt !== 251) { fclose($fp); return false; }
        $put('DATA');
        if ($code($read()) !== 354) { fclose($fp); return false; }

        $headers =
            'From: ' . arch3_mime_header($fromName) . " <{$fromEmail}>\r\n" .
            "To: <{$to}>\r\n" .
            'Subject: ' . arch3_mime_header($subject) . "\r\n" .
            'Date: ' . date('r') . "\r\n" .
            "MIME-Version: 1.0\r\n" .
            "Content-Type: text/plain; charset=utf-8\r\n" .
            "Content-Transfer-Encoding: 8bit\r\n";
        // Normaliza quebras de linha e aplica dot-stuffing.
        $bodyN = str_replace(["\r\n", "\r"], "\n", $body);
        $bodyN = str_replace("\n", "\r\n", $bodyN);
        $bodyN = preg_replace('/^\./m', '..', $bodyN);
        $put($headers . "\r\n" . $bodyN . "\r\n.");
        $ok = ($code($read()) === 250);
        $put('QUIT');
    } catch (Throwable $e) {
        error_log('[arch3] SMTP error: ' . $e->getMessage());
        $ok = false;
    }
    if (is_resource($fp)) { fclose($fp); }
    return $ok;
}

/** Notifica o administrador sobre um novo cadastro (SMTP com fallback). */
function notify_admin_signup(array $user): void
{
    if (!cfg('NOTIFY_SIGNUPS', true)) {
        return;
    }
    $admin = cfg('ADMIN_EMAIL', '');
    if (!$admin) {
        return;
    }
    $subject = 'New Arch3 Signup';
    $body =
        "Name: {$user['name']}\n" .
        "Email: {$user['email']}\n" .
        "Country: " . ($user['country'] ?? 'Unknown') . "\n" .
        "Date: {$user['created_at']}\n";
    arch3_send_mail($admin, $subject, $body);
}

/** Gera um código numérico de verificação (6 dígitos). */
function arch3_generate_code(): string
{
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

/**
 * Envia o código de verificação por e-mail (PHP mail, best-effort).
 * Retorna true se o mail() foi aceito pelo MTA local.
 */
function send_verification_email(string $email, string $code): bool
{
    $subject = 'Your Arch3 verification code';
    $body =
        "Welcome to Arch3.\n\n" .
        "Your verification code is:\n\n" .
        "{$code}\n\n" .
        "This code expires in 15 minutes.\n\n" .
        "If you did not create an Arch3 account, you can ignore this email.\n";
    return arch3_send_mail($email, $subject, $body);
}
