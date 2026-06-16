<?php
// Health check for the Arch3 PHP backend (HostGator).
require_once __DIR__ . '/lib/config.php';
header('Content-Type: application/json');

$dbOk = false;
try {
    require_once __DIR__ . '/lib/db.php';
    arch3_db();
    $dbOk = true;
} catch (Throwable $e) {
    $dbOk = false;
}

echo json_encode([
    'ok' => true,
    'hasOpenAiKey' => cfg('OPENAI_API_KEY', '') !== '',
    'imagick' => extension_loaded('imagick'),
    'curl' => extension_loaded('curl'),
    'pdo_mysql' => extension_loaded('pdo_mysql'),
    'db' => $dbOk,
    'stripe' => cfg('STRIPE_SECRET_KEY', '') !== '',
    'backend' => 'php-hostgator',
]);
