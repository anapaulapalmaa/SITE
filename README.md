# Arch3 — arch3.net

Site da Arch3 + ferramenta **Try It** (redesign de ambientes em 360° com IA),
**login/cadastro**, **créditos/planos**, captura de **leads** e painel
**admin** — rodando no próprio servidor de **arch3.net** (HostGator / cPanel),
com backend em **PHP** e banco **MySQL**.

## Estrutura

```
index.html            # landing
try-it.html           # gerador 360 (login obrigatório, créditos, planos)
admin.html            # painel admin (métricas, leads, Export CSV)  -> arch3.net/admin
.htaccess             # rota amigável /admin -> admin.html
assets/               # logos e imagens
php-backend/          # backend PHP (publicado em arch3.net/api)
├── health.php
├── auth-register.php auth-login.php auth-logout.php me.php
├── plans.php buy-credits.php
├── generate-redesign.php      # geração de imagem (OpenAI) + panorama sem blur
├── admin-stats.php admin-leads.php admin-export.php
├── stripe-webhook.php         # opcional (Stripe pronto, desligado por padrão)
├── arch3-config.example.php   # modelo de configuração (vai para a HOME)
└── lib/ (config, db, auth, billing, plans, helpers)
```

## Publicação em arch3.net (cPanel)

No HostGator, o conteúdo público fica em `public_html/`:

| Local no repositório         | Destino em arch3.net (cPanel)        |
|------------------------------|--------------------------------------|
| `index.html`, `try-it.html`, `admin.html`, `.htaccess` | `public_html/` |
| `assets/`                    | `public_html/assets/`                |
| `php-backend/*.php`          | `public_html/api/`                   |
| `php-backend/lib/*.php`      | `public_html/api/lib/`               |
| `php-backend/arch3-config.example.php` → `arch3-config.php` | `/home/SEU_USUARIO/` (FORA do public_html) |

O pacote pronto para upload é gerado em `dist/arch3-hostgator-upload.zip`
(veja `dist/arch3-hostgator/README_UPLOAD.txt` para o passo a passo).

## Configuração (arch3-config.php, na HOME, fora do public_html)

- `OPENAI_API_KEY` — chave da OpenAI (geração de imagem).
- `DB_HOST` / `DB_NAME` / `DB_USER` / `DB_PASS` — banco MySQL do cPanel.
- `ADMIN_EMAIL` — quem logar com este e-mail vê `arch3.net/admin` e a lista de leads.

As tabelas (`users`, `generations`, `purchases`) são criadas automaticamente no
primeiro acesso — não é preciso importar `.sql`.

## URLs

- `https://arch3.net/` — landing
- `https://arch3.net/try-it.html` — Try It (cadastro/login → geração 360)
- `https://arch3.net/admin` — painel admin (login com `ADMIN_EMAIL`) → leads + Export CSV
- `https://arch3.net/api/health.php` — diagnóstico do backend
