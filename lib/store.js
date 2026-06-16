/**
 * Arch3 — armazenamento simples em arquivo JSON (sem banco).
 *
 * Tudo (usuários, gerações, compras) vive em data/db.json. Carregado em memória
 * e persistido com escrita atômica (tmp + rename). Suficiente para o MVP no
 * Render; trocar por SQLite/Postgres depois é direto.
 *
 * ATENÇÃO: no plano free do Render o disco é EFÊMERO — os dados se perdem a cada
 * deploy/restart. Para persistir, monte um Render Disk e aponte DATA_DIR para
 * o caminho do disco (ex.: /var/data).
 */
import fs from 'fs';
import path from 'path';

const DATA_DIR = process.env.DATA_DIR || path.join(process.cwd(), 'data');
const DB_FILE = path.join(DATA_DIR, 'db.json');
const EMPTY = { users: [], generations: [], purchases: [] };

let db = null;

function ensure() {
  if (!fs.existsSync(DATA_DIR)) fs.mkdirSync(DATA_DIR, { recursive: true });
  if (!fs.existsSync(DB_FILE)) fs.writeFileSync(DB_FILE, JSON.stringify(EMPTY, null, 2));
}

export function getDB() {
  if (db) return db;
  ensure();
  try {
    db = JSON.parse(fs.readFileSync(DB_FILE, 'utf8'));
  } catch {
    db = { ...EMPTY };
  }
  db.users ||= [];
  db.generations ||= [];
  db.purchases ||= [];
  return db;
}

export function saveDB() {
  ensure();
  const tmp = DB_FILE + '.tmp';
  fs.writeFileSync(tmp, JSON.stringify(getDB(), null, 2));
  fs.renameSync(tmp, DB_FILE);
}

export function nextId(arr) {
  return arr.reduce((max, item) => Math.max(max, item.id || 0), 0) + 1;
}

export function dataDir() {
  return DATA_DIR;
}
