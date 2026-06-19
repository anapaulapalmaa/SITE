<?php
// Modelo de configuração do backend PHP do Arch3.
// No servidor, a versão real fica em /home1/<user>/arch3-config.php
// (FORA do diretório público arch3.net/), nunca versionada.
return [
    // ---- OpenAI (geração de imagem) ----
    'OPENAI_API_KEY'       => 'sk-proj-...',   // chave secreta (só no servidor)
    'OPENAI_IMAGE_MODEL'   => 'gpt-image-1.5',
    'OPENAI_IMAGE_SIZE'    => '1536x1024',
    'OPENAI_IMAGE_QUALITY' => 'medium',        // low | medium | high

    // Preset de qualidade aplicado a assinantes Pro (Higher image quality presets).
    'OPENAI_IMAGE_QUALITY_PRO' => 'high',

    // ---- Banco de dados MySQL (HostGator: cPanel → MySQL Databases) ----
    'DB_HOST' => 'localhost',
    'DB_NAME' => 'usuario_arch3',
    'DB_USER' => 'usuario_arch3',
    'DB_PASS' => 'senha-do-banco',
    'DB_PORT' => 3306,

    // ---- Conta / sessão ----
    // E-mail do administrador. Quem logar com este e-mail vê /admin
    // e recebe a notificação de novos cadastros.
    'ADMIN_EMAIL' => 'gutecla@gmail.com',
    'SESSION_NAME' => 'arch3_session',

    // ---- E-mail (verificação de cadastro + notificação de novos cadastros) ----
    // SMTP autenticado do cPanel. DICA: use 'localhost' como host — o PHP roda
    // no mesmo servidor do mailserver, evitando o DNS da Cloudflare (arch3.net).
    // Com SMTP_* preenchido, os códigos de verificação saem por SMTP; caso
    // contrário cai no fallback mail() (best-effort, costuma ir para spam).
    'SMTP_HOST'      => 'localhost',        // recomendado; alternativa: 'arch3.net'
    'SMTP_PORT'      => 465,                 // 465 = SSL | 587 = STARTTLS
    'SMTP_SECURE'    => 'ssl',               // 'ssl' (465) | 'tls' (587)
    'SMTP_USER'      => 'sac@arch3.net',     // caixa de e-mail do cPanel
    'SMTP_PASS'      => 'senha-da-caixa',    // senha da caixa (só no servidor)
    'MAIL_FROM'      => 'sac@arch3.net',     // remetente
    'MAIL_FROM_NAME' => 'Arch3',
    'NOTIFY_SIGNUPS' => true,

    // ---- Stripe (pronto para integrar; deixe vazio enquanto não usa) ----
    // Com as chaves vazias, "Buy Credits" credita direto (modo dev/teste).
    // Com STRIPE_SECRET_KEY preenchida, o backend cria uma Checkout Session real.
    'STRIPE_SECRET_KEY'      => '',
    'STRIPE_PUBLISHABLE_KEY' => '',
    'STRIPE_WEBHOOK_SECRET'  => '',
    // Price IDs do Stripe (criados no dashboard). Mapeados por plano.
    'STRIPE_PRICES' => [
        'starter'      => '',   // price_...
        'plus'         => '',
        'professional' => '',
        'pro'          => '',   // assinatura recorrente
    ],
    // Para onde o Stripe redireciona após o checkout.
    'CHECKOUT_SUCCESS_URL' => 'https://arch3.net/try-it.html?purchase=success',
    'CHECKOUT_CANCEL_URL'  => 'https://arch3.net/try-it.html?purchase=cancel',
];
