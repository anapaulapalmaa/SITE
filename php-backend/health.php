<?php
// Health check for the Arch3 PHP backend (HostGator).
header('Content-Type: application/json');

$cfgPath = __DIR__ . '/../../arch3-config.php';
$cfg = is_file($cfgPath) ? include $cfgPath : [];

echo json_encode([
    'ok' => true,
    'hasOpenAiKey' => !empty($cfg['OPENAI_API_KEY']),
    'imagick' => extension_loaded('imagick'),
    'curl' => extension_loaded('curl'),
    'backend' => 'php-hostgator',
]);
