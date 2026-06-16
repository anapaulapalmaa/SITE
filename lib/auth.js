/**
 * Arch3 — autenticação: hashing de senha, usuário atual (via sessão) e
 * representação pública. Reset mensal do plano Pro sem cron (on-access).
 */
import bcrypt from 'bcryptjs';
import { getDB, saveDB } from './store.js';
import { plan } from './plans.js';

export function isAdminEmail(email) {
  const admin = (process.env.ADMIN_EMAIL || '').toLowerCase().trim();
  return admin !== '' && String(email).toLowerCase().trim() === admin;
}

export function hashPassword(pw) {
  return bcrypt.hashSync(pw, 10);
}

export function verifyPassword(pw, hash) {
  try {
    return bcrypt.compareSync(pw, hash || '');
  } catch {
    return false;
  }
}

export function findUserByEmail(email) {
  const e = String(email).toLowerCase().trim();
  return getDB().users.find((u) => u.email === e) || null;
}

/** Pro: recarrega 100 créditos quando a renovação mensal vence (on-access). */
export function applyProReset(user) {
  if (!user || user.subscription_plan !== 'pro' || user.subscription_status !== 'active') return;
  if (!user.subscription_renews_at) return;
  const now = Date.now();
  let renew = Date.parse(user.subscription_renews_at);
  if (Number.isNaN(renew) || renew > now) return;

  const pro = plan('pro');
  let next = new Date(renew);
  while (next.getTime() <= now) next.setMonth(next.getMonth() + 1);
  user.credits_remaining = pro.credits;
  user.subscription_renews_at = next.toISOString();
  saveDB();
}

export function currentUser(req) {
  const id = req.session && req.session.userId;
  if (!id) return null;
  const user = getDB().users.find((u) => u.id === id);
  if (!user) return null;
  applyProReset(user);
  return user;
}

export function isAdminUser(user) {
  return !!user && (user.is_admin === true || isAdminEmail(user.email));
}

/** Versão segura para o frontend (sem hash de senha). */
export function userPublic(user) {
  const id = user.subscription_plan || 'free';
  const p = plan(id) || plan('free');
  return {
    id: user.id,
    name: user.name,
    email: user.email,
    credits_remaining: Number(user.credits_remaining || 0),
    generations_used: Number(user.generations_used || 0),
    plan: id,
    plan_label: p.label,
    plan_name: p.name,
    subscription_status: user.subscription_status || 'inactive',
    subscription_renews_at: user.subscription_renews_at || null,
    is_admin: isAdminUser(user),
    email_verified: !!user.email_verified,
  };
}
