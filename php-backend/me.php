<?php
/**
 * Arch3 — GET /api/me.php
 * Retorna o usuário logado (ou { user: null }).
 */
require_once __DIR__ . '/lib/auth.php';

$user = current_user();
json_out(['user' => $user ? user_public($user) : null]);
