<?php
/**
 * Arch3 — definição central dos planos.
 *
 * Fonte única da verdade para frontend (via /api/plans.php) e backend
 * (crédito + Stripe). Preços em centavos de USD.
 *
 * Tipos:
 *   - 'pack'         : compra única, adiciona créditos.
 *   - 'subscription' : recorrente mensal, reseta créditos todo mês.
 */
function arch3_plans(): array
{
    return [
        'free' => [
            'id'       => 'free',
            'name'     => 'Free Plan',
            'label'    => 'Free',
            'type'     => 'free',
            'credits'  => 1,
            'price'    => 0,
            'cta'      => null,
            'features' => ['1 free generation'],
        ],
        'starter' => [
            'id'       => 'starter',
            'name'     => 'Starter',
            'label'    => 'Starter',
            'type'     => 'pack',
            'credits'  => 5,
            'price'    => 499,
            'cta'      => 'Buy Credits',
            'features' => ['5 generations'],
        ],
        'plus' => [
            'id'       => 'plus',
            'name'     => 'Plus',
            'label'    => 'Plus',
            'type'     => 'pack',
            'credits'  => 10,
            'price'    => 899,
            'cta'      => 'Buy Credits',
            'features' => ['10 generations'],
        ],
        'professional' => [
            'id'       => 'professional',
            'name'     => 'Professional',
            'label'    => 'Professional',
            'type'     => 'pack',
            'credits'  => 25,
            'price'    => 1999,
            'cta'      => 'Buy Credits',
            'features' => ['25 generations'],
        ],
        'pro' => [
            'id'        => 'pro',
            'name'      => 'Pro',
            'label'     => 'Pro',
            'type'      => 'subscription',
            'credits'   => 100,
            'price'     => 5999,
            'interval'  => 'month',
            'cta'       => 'Upgrade to Pro',
            'features'  => [
                '100 generations / month',
                'Priority processing',
                'Faster rendering queue',
                'Early access features',
                'Higher image quality presets',
            ],
        ],
    ];
}

function arch3_plan(string $id): ?array
{
    $plans = arch3_plans();
    return $plans[$id] ?? null;
}

/** Formata centavos como "US$4.99". */
function arch3_price_label(int $cents): string
{
    return 'US$' . number_format($cents / 100, 2);
}
