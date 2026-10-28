// @ts-check
/**
 * Optional 0.9.0 modules (features.* in system_settings, App\Support\Features).
 *
 * Two ways in:
 *  - enableFeatures()/restoreFeatures(): setup only, for the specs of a module
 *    that is off by default on some installs (an upgrade, or a fresh install
 *    for the visitor log and fines). They write the rows directly and flush
 *    the app cache, then put back exactly what was there.
 *  - setModuleInUi(): what the feature-toggle specs test, the administrator
 *    flipping the switch in Settings → Modules (or → Loans for the three
 *    circulation modules) and saving the form.
 */
const { execFileSync } = require('child_process');
const { flushCache } = require('./flush-cache');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';
const DB_HOST = process.env.E2E_DB_HOST || '';
const DB_NAME = process.env.E2E_DB_NAME || '';

/** Same order and keys as Features::DEFAULTS. */
const FEATURE_KEYS = ['stocktake', 'marc', 'visitor_log', 'catalogue_cards', 'suppliers', 'rda_types', 'patron_cards', 'user_import', 'circulation_rules', 'closures_calendar', 'fines'];
/** Saved from Settings → Loans; every other key from Settings → Modules. */
const LOANS_TAB_KEYS = ['circulation_rules', 'closures_calendar', 'fines'];

function dbQuery(sql) {
  const args = [];
  if (DB_HOST) args.push('-h', DB_HOST); else if (DB_SOCKET) args.push('-S', DB_SOCKET);
  args.push('-u', DB_USER, DB_NAME, '-N', '-B', '-e', sql);
  return execFileSync('mysql', args, { encoding: 'utf-8', timeout: 15000, env: { ...process.env, MYSQL_PWD: DB_PASS } }).trim();
}

function assertKey(key) {
  if (!FEATURE_KEYS.includes(key)) throw new Error(`Unknown feature: ${key}`);
}

/** The stored rows: key → '1' | '0' | null (no row = upgrade default). */
function readFeatures(keys = FEATURE_KEYS) {
  /** @type {Record<string, string|null>} */
  const out = {};
  for (const key of keys) {
    assertKey(key);
    const n = dbQuery(`SELECT COUNT(*) FROM system_settings WHERE category='features' AND setting_key='${key}'`);
    out[key] = n === '1'
      ? dbQuery(`SELECT setting_value FROM system_settings WHERE category='features' AND setting_key='${key}'`)
      : null;
  }
  return out;
}

/** Write key → '1'/'0'/true/false (null deletes the row), then flush the app cache. */
async function writeFeatures(values) {
  for (const [key, value] of Object.entries(values)) {
    assertKey(key);
    if (value === null || value === undefined) {
      dbQuery(`DELETE FROM system_settings WHERE category='features' AND setting_key='${key}'`);
    } else {
      const v = value === true || value === '1' ? '1' : '0';
      dbQuery(`INSERT INTO system_settings (category, setting_key, setting_value) VALUES ('features', '${key}', '${v}') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)`);
    }
  }
  await flushCache();
}

/** Switch $keys on for a spec's setup; returns the snapshot restoreFeatures() puts back. */
async function enableFeatures(keys) {
  const snapshot = readFeatures(keys);
  await writeFeatures(Object.fromEntries(keys.map((k) => [k, '1'])));
  return snapshot;
}

/** Switch $keys off for a spec's setup; returns the snapshot. */
async function disableFeatures(keys) {
  const snapshot = readFeatures(keys);
  await writeFeatures(Object.fromEntries(keys.map((k) => [k, '0'])));
  return snapshot;
}

async function restoreFeatures(snapshot) {
  if (snapshot) await writeFeatures(snapshot);
}

/**
 * Flip one module like an administrator: open the settings tab that holds
 * it, click the switch when it is not already in the wanted state, save.
 * The page must be signed in as an administrator.
 *
 * @param {import('@playwright/test').Page} page
 */
async function setModuleInUi(page, key, on) {
  assertKey(key);
  const tab = LOANS_TAB_KEYS.includes(key) ? 'loans' : 'modules';
  await page.goto(`${BASE}/admin/settings?tab=${tab}`);
  const box = page.locator(`#feature_${key}`);
  await box.waitFor({ state: 'attached', timeout: 15000 });
  if ((await box.isChecked()) !== on) {
    await page.locator(`label:has(#feature_${key})`).click();
  }
  const form = tab === 'loans' ? 'form[action$="/admin/settings/loans"]' : '#modules-settings-form';
  await page.locator(`${form} button[type="submit"]`).click();
  await page.waitForURL(new RegExp(`tab=${tab}`), { timeout: 20000 });
}

const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';
const configured = Boolean(ADMIN_EMAIL && ADMIN_PASS && DB_USER && DB_NAME);

/** SQL string literal. */
function sqlString(value) {
  return `'${String(value).replace(/\\/g, '\\\\').replace(/'/g, "''")}'`;
}

/**
 * Sign in through the login form (whatever the localized slug).
 * @param {import('@playwright/test').Page} page
 */
async function loginAs(page, email, password) {
  for (const slug of ['accedi', 'login', 'anmelden', 'connexion', 'log-ind']) {
    const resp = await page.goto(`${BASE}/${slug}`).catch(() => null);
    if (resp && resp.status() === 200 && (await page.locator('input[name="email"]').count()) > 0) break;
  }
  await page.fill('input[name="email"]', email);
  await page.fill('input[name="password"]', password);
  await page.locator('button[type="submit"]').first().click();
  await page.locator('input[name="password"]').waitFor({ state: 'detached', timeout: 45000 });
}

/** @param {import('@playwright/test').Page} page */
async function loginAdmin(page) {
  await page.goto(`${BASE}/admin/dashboard`);
  if (/\/admin\//.test(page.url()) && (await page.locator('input[name="password"]').count()) === 0) return;
  await loginAs(page, ADMIN_EMAIL, ADMIN_PASS);
  if (!/\/admin\//.test(page.url())) await page.goto(`${BASE}/admin/dashboard`);
}

function phpHash(password) {
  return execFileSync('php', ['-r', `echo password_hash(${JSON.stringify(password)}, PASSWORD_DEFAULT);`], { encoding: 'utf-8', timeout: 5000 }).trim();
}

/**
 * A throwaway account (tipo_utente staff/standard, optional custom role).
 * Returns its id; removeUsers() deletes it.
 */
function createUser(email, password, tipo = 'standard', ruoloId = null) {
  const card = `ZZT${Date.now().toString(36)}${Math.floor(Math.random() * 1000)}`.slice(0, 20);
  dbQuery(`INSERT INTO utenti (codice_tessera, nome, cognome, email, password, stato, email_verificata, tipo_utente, ruolo_id, privacy_accettata, locale) VALUES (${sqlString(card)}, 'Zz', 'Toggle', ${sqlString(email)}, ${sqlString(phpHash(password))}, 'attivo', 1, ${sqlString(tipo)}, ${ruoloId === null ? 'NULL' : Number(ruoloId)}, 1, 'it_IT')`);
  return Number(dbQuery(`SELECT id FROM utenti WHERE email=${sqlString(email)}`));
}

function removeUsers(emailLike) {
  const ids = `SELECT id FROM utenti WHERE email LIKE ${sqlString(emailLike)}`;
  for (const sql of [
    `DELETE FROM user_sessions WHERE utente_id IN (${ids})`,
    `DELETE FROM log_modifiche WHERE tabella='utenti' AND record_id IN (${ids})`,
  ]) {
    try { dbQuery(sql); } catch (_) { /* optional table */ }
  }
  dbQuery(`DELETE FROM utenti WHERE email LIKE ${sqlString(emailLike)}`);
}

/**
 * The CSRF token of the signed-in session, read from a page that has one.
 * @param {import('@playwright/test').Page} page
 */
async function csrfToken(page) {
  await page.goto(`${BASE}/admin/settings?tab=loans`);
  return page.locator('input[name="csrf_token"]').first().inputValue();
}

module.exports = {
  BASE,
  configured,
  dbQuery,
  sqlString,
  loginAs,
  loginAdmin,
  createUser,
  removeUsers,
  csrfToken,
  FEATURE_KEYS,
  LOANS_TAB_KEYS,
  readFeatures,
  writeFeatures,
  enableFeatures,
  disableFeatures,
  restoreFeatures,
  setModuleInUi,
};
