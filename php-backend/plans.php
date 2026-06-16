<?php
/**
 * Arch3 — GET /api/plans.php
 * Expõe os planos para o frontend (preços já formatados).
 */
require_once __DIR__ . '/lib/plans.php';
require_once __DIR__ . '/lib/helpers.php';

$out = [];
foreach (arch3_plans() as $id => $p) {
    $out[] = [
        'id'           => $p['id'],
        'name'         => $p['name'],
        'label'        => $p['label'],
        'type'         => $p['type'],
        'credits'      => $p['credits'],
        'price_cents'  => $p['price'],
        'price_label'  => $p['price'] > 0 ? arch3_price_label($p['price']) : 'Free',
        'interval'     => $p['interval'] ?? null,
        'cta'          => $p['cta'] ?? null,
        'features'     => $p['features'],
    ];
}
json_out(['plans' => $out]);
