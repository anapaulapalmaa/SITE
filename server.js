/**
 * Arch3 — servidor único no Render (Node/Express).
 * Serve o frontend estático (public/) e toda a API (/api/*): contas, créditos,
 * planos, leads/admin e geração de imagem via OpenAI. Sem PHP, sem MySQL.
 */
import express from 'express';
import cookieSession from 'cookie-session';
import multer from 'multer';
import dotenv from 'dotenv';
import path from 'path';
import { fileURLToPath } from 'url';

import { getDB, saveDB, nextId } from './lib/store.js';
import { PLANS, plan, priceLabel } from './lib/plans.js';
import {
  hashPassword, verifyPassword, findUserByEmail,
  currentUser, isAdminUser, userPublic, isAdminEmail, applyProReset,
} from './lib/auth.js';
import { generateRedesign } from './lib/images.js';

dotenv.config();

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const PUBLIC_DIR = path.join(__dirname, 'public');
const app = express();
const port = process.env.PORT || 3000;

app.set('trust proxy', 1); // atrás do proxy HTTPS do Render
app.use(express.json({ limit: '2mb' }));
app.use(express.urlencoded({ extended: true }));
app.use(cookieSession({
  name: 'arch3_session',
  keys: [process.env.SESSION_SECRET || 'arch3-dev-secret-change-me'],
  maxAge: 30 * 24 * 60 * 60 * 1000,
  httpOnly: true,
  sameSite: 'lax',
}));

// Compat: o frontend existente chama /api/<rota>.php — removemos o sufixo.
app.use((req, _res, next) => {
  if (req.url.startsWith('/api/')) req.url = req.url.replace(/\.php(\?|$)/, '$1');
  next();
});

const upload = multer({ storage: multer.memoryStorage(), limits: { fileSize: 20 * 1024 * 1024 } });

const fail = (res, status, message, extra = {}) => res.status(status).json({ error: message, ...extra });
const detectCountry = (req) => {
  const cf = req.headers['cf-ipcountry'];
  return cf && cf !== 'XX' ? String(cf).toUpperCase() : 'Unknown';
};

// ---------------------------------------------------------------- health ----
app.get('/api/health', (_req, res) => {
  res.json({
    ok: true,
    hasOpenAiKey: Boolean(process.env.OPENAI_API_KEY),
    adminConfigured: Boolean(process.env.ADMIN_EMAIL),
    backend: 'render-node',
  });
});

// ------------------------------------------------------------------ auth ----
app.post('/api/auth-register', (req, res) => {
  const name = String(req.body?.name || '').trim();
  const email = String(req.body?.email || '').toLowerCase().trim();
  const password = String(req.body?.password || '');

  if (name.length < 2) return fail(res, 400, 'Please enter your name.');
  if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) return fail(res, 400, 'Please enter a valid email address.');
  if (password.length < 6) return fail(res, 400, 'Password must be at least 6 characters.');

  const db = getDB();
  if (findUserByEmail(email)) return fail(res, 409, 'An account with this email already exists. Try logging in.');

  const now = new Date().toISOString();
  const user = {
    id: nextId(db.users),
    name,
    email,
    password_hash: hashPassword(password),
    created_at: now,
    last_login: now,
    generations_used: 0,
    credits_remaining: plan('free').credits,
    subscription_plan: 'free',
    subscription_status: 'inactive',
    subscription_renews_at: null,
    country: detectCountry(req),
    is_admin: isAdminEmail(email),
    email_verified: false,
  };
  db.users.push(user);
  saveDB();

  req.session.userId = user.id;
  res.json({ user: userPublic(user) });
});

app.post('/api/auth-login', (req, res) => {
  const email = String(req.body?.email || '').toLowerCase().trim();
  const password = String(req.body?.password || '');
  if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email) || password === '') {
    return fail(res, 400, 'Enter your email and password.');
  }

  const user = findUserByEmail(email);
  if (!user || !verifyPassword(password, user.password_hash)) {
    return fail(res, 401, 'Invalid email or password.');
  }

  if (isAdminEmail(email)) user.is_admin = true;
  user.last_login = new Date().toISOString();
  applyProReset(user);
  saveDB();

  req.session.userId = user.id;
  res.json({ user: userPublic(user) });
});

app.post('/api/auth-logout', (req, res) => {
  req.session = null;
  res.json({ ok: true });
});

app.get('/api/me', (req, res) => {
  const user = currentUser(req);
  res.json({ user: user ? userPublic(user) : null });
});

// ----------------------------------------------------------------- plans ----
app.get('/api/plans', (_req, res) => {
  const plans = Object.values(PLANS).map((p) => ({
    id: p.id, name: p.name, label: p.label, type: p.type,
    credits: p.credits, price_cents: p.price,
    price_label: p.price > 0 ? priceLabel(p.price) : 'Free',
    interval: p.interval || null, cta: p.cta || null, features: p.features,
  }));
  res.json({ plans });
});

// Sem Stripe: credita imediatamente (modo dev/teste).
app.post('/api/buy-credits', (req, res) => {
  const user = currentUser(req);
  if (!user) return fail(res, 401, 'Authentication required.');

  const p = plan(String(req.body?.plan || ''));
  if (!p || p.type === 'free') return fail(res, 400, 'Choose a valid plan.');

  const db = getDB();
  if (p.type === 'subscription') {
    const renews = new Date();
    renews.setMonth(renews.getMonth() + 1);
    user.subscription_plan = p.id;
    user.subscription_status = 'active';
    user.subscription_renews_at = renews.toISOString();
    user.credits_remaining = p.credits;
  } else {
    user.credits_remaining = Number(user.credits_remaining || 0) + p.credits;
    user.subscription_plan = p.id;
  }
  db.purchases.push({
    id: nextId(db.purchases), user_id: user.id, plan: p.id,
    amount_cents: p.price, credits: p.credits, provider: 'dev',
    created_at: new Date().toISOString(),
  });
  saveDB();

  res.json({
    mode: 'dev',
    message: 'Credits added (test mode — Stripe not connected).',
    user: userPublic(user),
  });
});

// -------------------------------------------------------------- generate ----
app.post('/api/generate-redesign', upload.single('image'), async (req, res) => {
  const user = currentUser(req);
  if (!user) return fail(res, 401, 'Please sign in to generate.', { code: 'auth_required' });
  if (Number(user.credits_remaining || 0) <= 0) {
    return fail(res, 402, "You've used your free generation.\n\nChoose a plan to continue transforming spaces with Arch3.", {
      code: 'no_credits', credits_remaining: 0, plan: user.subscription_plan || 'free',
    });
  }

  const prompt = String(req.body?.prompt || '').trim();
  if (!req.file) return fail(res, 400, 'Envie uma imagem panorâmica no campo image.');
  if (!prompt) return fail(res, 400, 'Escreva uma frase descrevendo a transformação desejada.');

  const allowed = new Set(['image/jpeg', 'image/png', 'image/webp']);
  if (!allowed.has(req.file.mimetype)) return fail(res, 400, 'Formato inválido. Use JPG, JPEG, PNG ou WEBP.');

  const highQuality = user.subscription_plan === 'pro' && user.subscription_status === 'active';

  try {
    const result = await generateRedesign({
      buffer: req.file.buffer,
      mimeType: req.file.mimetype,
      filename: req.file.originalname,
      prompt,
      highQuality,
    });

    // Consome 1 crédito após sucesso.
    const db = getDB();
    user.credits_remaining = Math.max(0, Number(user.credits_remaining || 0) - 1);
    user.generations_used = Number(user.generations_used || 0) + 1;
    db.generations.push({
      id: nextId(db.generations), user_id: user.id,
      created_at: new Date().toISOString(), prompt: prompt.slice(0, 1000),
    });
    saveDB();

    res.json({
      imageUrl: 'data:image/png;base64,' + result.buffer.toString('base64'),
      mimeType: 'image/png',
      fileName: 'arch3-panorama.png',
      width: result.width,
      height: result.height,
      aspect: result.aspect,
      panoramic: result.panoramic,
      notice: result.panoramic ? null : 'For best 360° results, upload a complete panoramic photo.',
      provider: 'openai',
      credits_remaining: user.credits_remaining,
    });
  } catch (error) {
    console.error('generate-redesign failed:', error);
    fail(res, 502, error instanceof Error ? error.message : 'Falha ao gerar a imagem.');
  }
});

// ----------------------------------------------------------------- admin ----
function requireAdmin(req, res) {
  const user = currentUser(req);
  if (!user) { fail(res, 401, 'Authentication required.'); return null; }
  if (!isAdminUser(user)) { fail(res, 403, 'Admin access required.'); return null; }
  return user;
}

app.get('/api/admin-stats', (req, res) => {
  if (!requireAdmin(req, res)) return;
  const db = getDB();
  const monthStart = new Date();
  monthStart.setDate(1); monthStart.setHours(0, 0, 0, 0);
  const revenueCents = db.purchases
    .filter((p) => Date.parse(p.created_at) >= monthStart.getTime())
    .reduce((sum, p) => sum + Number(p.amount_cents || 0), 0);

  res.json({
    total_users: db.users.length,
    total_generations: db.generations.length,
    monthly_revenue: 'US$' + (revenueCents / 100).toFixed(2),
    monthly_revenue_cents: revenueCents,
    free_users: db.users.filter((u) => (u.subscription_plan || 'free') === 'free').length,
    paid_users: db.users.filter((u) => (u.subscription_plan || 'free') !== 'free').length,
    pro_subscribers: db.users.filter((u) => u.subscription_plan === 'pro' && u.subscription_status === 'active').length,
  });
});

app.get('/api/admin-leads', (req, res) => {
  if (!requireAdmin(req, res)) return;
  const leads = [...getDB().users]
    .sort((a, b) => String(b.created_at).localeCompare(String(a.created_at)))
    .map((u) => {
      const p = plan(u.subscription_plan || 'free') || plan('free');
      return {
        id: u.id, name: u.name, email: u.email,
        signup_date: u.created_at, country: u.country || 'Unknown',
        generations_used: Number(u.generations_used || 0),
        plan: p.label, credits_remaining: Number(u.credits_remaining || 0),
      };
    });
  res.json({ leads });
});

app.get('/api/admin-export', (req, res) => {
  if (!requireAdmin(req, res)) return;
  const rows = [...getDB().users].sort((a, b) => String(b.created_at).localeCompare(String(a.created_at)));
  const esc = (v) => {
    const s = String(v ?? '');
    return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
  };
  let csv = 'name,email,created_at,plan,generations_used\n';
  for (const u of rows) {
    const p = plan(u.subscription_plan || 'free') || plan('free');
    csv += [esc(u.name), esc(u.email), esc(u.created_at), esc(p.label), Number(u.generations_used || 0)].join(',') + '\n';
  }
  res.setHeader('Content-Type', 'text/csv; charset=utf-8');
  res.setHeader('Content-Disposition', 'attachment; filename="arch3-leads.csv"');
  res.send(csv);
});

// --------------------------------------------------------- static + pages ---
app.use(express.static(PUBLIC_DIR));
app.get('/admin', (_req, res) => res.sendFile(path.join(PUBLIC_DIR, 'admin.html')));
app.get(['/', '/try-it', '/try-it.html'], (req, res) => {
  const file = req.path.includes('try-it') ? 'try-it.html' : 'index.html';
  res.sendFile(path.join(PUBLIC_DIR, file));
});

app.use((error, _req, res, _next) => {
  console.error(error);
  const tooLarge = error?.code === 'LIMIT_FILE_SIZE';
  res.status(tooLarge ? 413 : 500).json({
    error: tooLarge ? 'A imagem é grande demais. Use um arquivo menor que 20 MB.' : 'Não foi possível processar a solicitação.',
  });
});

app.listen(port, () => console.log(`Arch3 running on http://localhost:${port}`));
