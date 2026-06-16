/**
 * Arch3 — definição central dos planos (fonte única para frontend e backend).
 * Preços em centavos de USD.
 *   pack         -> compra única, adiciona créditos.
 *   subscription -> recorrente mensal, reseta créditos todo mês.
 */
export const PLANS = {
  free: {
    id: 'free', name: 'Free Plan', label: 'Free', type: 'free',
    credits: 1, price: 0, cta: null, features: ['1 free generation'],
  },
  starter: {
    id: 'starter', name: 'Starter', label: 'Starter', type: 'pack',
    credits: 5, price: 499, cta: 'Buy Credits', features: ['5 generations'],
  },
  plus: {
    id: 'plus', name: 'Plus', label: 'Plus', type: 'pack',
    credits: 10, price: 899, cta: 'Buy Credits', features: ['10 generations'],
  },
  professional: {
    id: 'professional', name: 'Professional', label: 'Professional', type: 'pack',
    credits: 25, price: 1999, cta: 'Buy Credits', features: ['25 generations'],
  },
  pro: {
    id: 'pro', name: 'Pro', label: 'Pro', type: 'subscription',
    credits: 100, price: 5999, interval: 'month', cta: 'Upgrade to Pro',
    features: [
      '100 generations / month',
      'Priority processing',
      'Faster rendering queue',
      'Early access features',
      'Higher image quality presets',
    ],
  },
};

export function plan(id) {
  return PLANS[id] || null;
}

export function priceLabel(cents) {
  return 'US$' + (cents / 100).toFixed(2);
}
