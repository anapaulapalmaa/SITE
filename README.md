# Arch3 — Spatial AI (Render / Node)

Site da Arch3 + ferramenta **Try It** (redesign de ambientes em 360° com IA),
contas/login, captura de **leads** e painel **/admin** — tudo num **único**
serviço Node/Express hospedado no **Render**. Sem PHP, sem cPanel, sem MySQL.

## Arquitetura

```
server.js              # Express: serve public/ + toda a API /api/*
lib/
├── store.js           # armazenamento em JSON (data/db.json) — users/leads/gerações
├── auth.js            # sessão (cookie), bcrypt, usuário atual, admin
├── plans.js           # planos e preços (Free/Starter/Plus/Professional/Pro)
└── images.js          # OpenAI Images (edits) + panorama sem blur/stretch
public/                # frontend estático
├── index.html         # landing
├── try-it.html        # gerador 360 (login obrigatório, créditos, planos)
├── admin.html         # painel de leads (login admin + Export CSV)
└── assets/
data/                  # criado em runtime (gitignored)
```

## Endpoints (`/api`)

`health`, `auth-register`, `auth-login`, `auth-logout`, `me`, `plans`,
`buy-credits`, `generate-redesign`, `admin-stats`, `admin-leads`,
`admin-export`. (O frontend pode chamar com ou sem sufixo `.php`.)

## Variáveis de ambiente

Ver `.env.example`. Essenciais no Render:

- `OPENAI_API_KEY` — chave da OpenAI (secret).
- `ADMIN_EMAIL` — quem logar com este e-mail vê `/admin` e a lista de leads.
- `SESSION_SECRET` — segredo do cookie de sessão (o Render gera automaticamente).

## Rodar localmente

```bash
npm install
cp .env.example .env   # preencha OPENAI_API_KEY e ADMIN_EMAIL
npm start              # http://localhost:3000
```

## Deploy (Render)

`render.yaml` já está configurado (Blueprint). Com o repositório conectado ao
Render e `autoDeploy: true`, **cada push na branch principal redeploya
automaticamente**. Defina `OPENAI_API_KEY` e `ADMIN_EMAIL` no painel do Render.

> **Persistência:** no plano *free* o disco é efêmero — os dados em `data/`
> se perdem a cada deploy/restart. Para manter leads/contas, crie um **Render
> Disk** (instância paga), monte em `/var/data` e defina `DATA_DIR=/var/data`.
> Depois é direto migrar o `store.js` para SQLite/Postgres.
