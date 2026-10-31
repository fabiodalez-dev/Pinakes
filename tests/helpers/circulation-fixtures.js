// @ts-check
/**
 * Shared fixtures for the circulation-policy E2E suites (patron types, rules
 * matrix, reservation limits, card duration). DB is used only for setup and
 * verification; every behaviour under test is driven through the UI or the
 * public endpoints a real client uses.
 */
const { expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const { appTodayISO, appDateOffsetISO } = require('./app-date');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS || '';
const DB_HOST = process.env.E2E_DB_HOST || '';
const DB_PORT = process.env.E2E_DB_PORT || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';
const DB_NAME = process.env.E2E_DB_NAME || '';
const CONFIGURED = Boolean(ADMIN_EMAIL && ADMIN_PASS && DB_USER && DB_NAME);

function dbQuery(sql) {
  const args = ['--default-character-set=utf8mb4', '-N', '-B', '-e', sql];
  if (DB_HOST) args.push('-h', DB_HOST);
  if (DB_PORT) args.push('-P', DB_PORT);
  if (!DB_HOST && DB_SOCKET) args.push('-S', DB_SOCKET);
  args.push('-u', DB_USER, DB_NAME);
  return execFileSync('mysql', args, { encoding: 'utf-8', timeout: 15000, env: { ...process.env, MYSQL_PWD: DB_PASS } }).trim();
}
const dbInt = (sql) => parseInt(dbQuery(sql) || '0', 10);
const sqlStr = (v) => "'" + String(v).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";

function clearRateLimits() {
  try {
    execFileSync('php', ['-r', 'foreach ([sys_get_temp_dir(), "/var/tmp", "/tmp"] as $d) { array_map("unlink", glob($d."/pinakes_ratelimit/*.json") ?: []); }'], { encoding: 'utf-8', timeout: 5000 });
  } catch { /* ignore */ }
}

function phpHash(password) {
  return execFileSync('php', ['-r', `echo password_hash(${JSON.stringify(password)}, PASSWORD_DEFAULT);`], { encoding: 'utf-8', timeout: 5000 }).trim();
}

/** Create a verified, active patron; returns its id. */
function createPatron(tag, { email, password = 'Circ1234!', typeId = null, cardExpiry = null, role = 'standard' } = {}) {
  const mail = email || `${tag.toLowerCase()}@circ.test`;
  dbQuery(`DELETE FROM prestiti WHERE utente_id IN (SELECT id FROM utenti WHERE email=${sqlStr(mail)})`);
  dbQuery(`DELETE FROM prenotazioni WHERE utente_id IN (SELECT id FROM utenti WHERE email=${sqlStr(mail)})`);
  dbQuery(`DELETE FROM utenti WHERE email=${sqlStr(mail)}`);
  const card = `${tag}${String(Date.now()).slice(-7)}`.slice(0, 20);
  dbQuery(`INSERT INTO utenti (nome, cognome, email, password, telefono, indirizzo, tipo_utente, stato, email_verificata, codice_tessera, privacy_accettata, data_scadenza_tessera, tipologia_id, created_at)
           VALUES ('Circ', ${sqlStr(tag)}, ${sqlStr(mail)}, ${sqlStr(phpHash(password))}, '0000000', 'Via E2E 1', ${sqlStr(role)}, 'attivo', 1, ${sqlStr(card)}, 1,
                   ${cardExpiry ? sqlStr(cardExpiry) : 'NULL'}, ${typeId ? Number(typeId) : 'NULL'}, NOW())`);
  return { id: dbInt(`SELECT id FROM utenti WHERE email=${sqlStr(mail)}`), email: mail, password, surname: tag };
}

/** Create a title of the given material with N available copies; returns {id, title, copyIds}. */
/** Inventory code of a copy. */
const copyCodeOf = (copyId) => dbQuery(`SELECT numero_inventario FROM copie WHERE id=${Number(copyId)}`);

function createTitle(tag, media = 'libro', copies = 1) {
  const title = `CIRC ${tag} ${media}`;
  dbQuery(`INSERT INTO libri (titolo, search_index, tipo_media, copie_totali, copie_disponibili, stato, created_at, updated_at)
           VALUES (${sqlStr(title)}, ${sqlStr(title)}, ${sqlStr(media)}, ${copies}, ${copies}, 'disponibile', NOW(), NOW())`);
  const id = dbInt(`SELECT id FROM libri WHERE titolo=${sqlStr(title)} AND deleted_at IS NULL ORDER BY id DESC LIMIT 1`);
  const copyIds = [];
  for (let i = 0; i < copies; i++) {
    const inv = `CIRC-${tag}-${i}-${String(Date.now()).slice(-6)}`;
    dbQuery(`INSERT INTO copie (libro_id, numero_inventario, stato, created_at) VALUES (${id}, ${sqlStr(inv)}, 'disponibile', NOW())`);
    copyIds.push(dbInt(`SELECT id FROM copie WHERE numero_inventario=${sqlStr(inv)}`));
  }
  return { id, title, copyIds };
}

function deleteTitles(ids) {
  for (const id of ids.filter(Boolean)) {
    try {
      dbQuery(`DELETE FROM prestiti WHERE libro_id=${id}`);
      dbQuery(`DELETE FROM prenotazioni WHERE libro_id=${id}`);
      dbQuery(`DELETE FROM copie WHERE libro_id=${id}`);
      dbQuery(`DELETE FROM libri WHERE id=${id}`);
    } catch { /* best effort */ }
  }
}

function deletePatrons(ids) {
  for (const id of ids.filter(Boolean)) {
    // Ledger rows (0.9.0) reference the patron; the table may not exist on an older database.
    try { dbQuery(`DELETE FROM movimenti_lettore WHERE utente_id=${id}`); } catch { /* no ledger table */ }
    try {
      dbQuery(`DELETE FROM prestiti WHERE utente_id=${id}`);
      dbQuery(`DELETE FROM prenotazioni WHERE utente_id=${id}`);
      dbQuery(`DELETE FROM user_sessions WHERE utente_id=${id}`);
      dbQuery(`DELETE FROM utenti WHERE id=${id}`);
    } catch { /* best effort */ }
  }
}

/** Remove the patron types (and their rules) whose code starts with $prefix. */
function deleteTypes(prefix) {
  try {
    dbQuery(`UPDATE utenti SET tipologia_id=NULL WHERE tipologia_id IN (SELECT id FROM (SELECT id FROM tipologie_lettore WHERE codice LIKE ${sqlStr(prefix + '%')}) t)`);
    dbQuery(`DELETE FROM regole_prestito WHERE tipologia_id IN (SELECT id FROM tipologie_lettore WHERE codice LIKE ${sqlStr(prefix + '%')})`);
    dbQuery(`DELETE FROM tipologie_lettore WHERE codice LIKE ${sqlStr(prefix + '%')} AND predefinita=0`);
  } catch { /* best effort */ }
}

async function loginAs(page, email, password) {
  for (let attempt = 0; attempt < 2; attempt++) {
    clearRateLimits();
    await page.goto(`${BASE}/accedi`);
    await page.fill('input[name="email"]', email);
    await page.fill('input[name="password"]', password);
    await page.locator('button[type="submit"]').click();
    try {
      await page.waitForURL((u) => !u.toString().includes('/accedi'), { timeout: 20000 });
      return;
    } catch {
      if (attempt === 1) throw new Error(`login failed for ${email}`);
    }
  }
}

const loginAsAdmin = (page) => loginAs(page, ADMIN_EMAIL, ADMIN_PASS);

/** Open the circulation tab of the settings page. */
async function openCirculationTab(page) {
  await page.goto(`${BASE}/admin/settings/circulation`);
  await page.waitForURL((u) => u.searchParams.get('tab') === 'circulation');
  await expect(page.locator('[data-settings-panel="circulation"]')).toBeVisible();
}

/**
 * Create a patron type through the "Nuova tipologia" form.
 * @param {Record<string, string|number>} fields form fields (nome, codice, max_prestiti, ...)
 * @param {{reservations?: boolean}} [opts]
 */
async function createTypeViaUi(page, fields, opts = {}) {
  await openCirculationTab(page);
  await page.locator('#circulation-new-type summary').click();
  const form = page.locator('[data-testid="circulation-type-create"]');
  for (const [name, value] of Object.entries(fields)) {
    await form.locator(`[name="${name}"]:not([type="hidden"])`).fill(String(value));
  }
  if (opts.reservations === false) {
    await form.locator('input[type="checkbox"][name="prenotazioni_abilitate"]').uncheck();
  }
  await form.locator('button[type="submit"]').click();
  await page.waitForURL((u) => u.searchParams.get('tab') === 'circulation');
  const id = dbInt(`SELECT id FROM tipologie_lettore WHERE codice=${sqlStr(String(fields.codice))}`);
  expect(id, `patron type ${fields.codice} created`).toBeGreaterThan(0);
  return id;
}

/** Fill one matrix cell and save the matrix. */
async function setMatrixCell(page, typeId, media, { loanable, max, duration, renewals, renewalDays } = {}) {
  await openCirculationTab(page);
  const prefix = `#rule-${typeId}-${media}`;
  if (loanable !== undefined) await page.locator(`${prefix}-loanable`).selectOption(String(loanable));
  if (max !== undefined) await page.locator(`${prefix}-max`).fill(String(max));
  if (duration !== undefined) await page.locator(`${prefix}-duration`).fill(String(duration));
  if (renewals !== undefined) await page.locator(`${prefix}-renewals`).fill(String(renewals));
  if (renewalDays !== undefined) await page.locator(`${prefix}-renewal_days`).fill(String(renewalDays));
  await page.locator('[data-testid="circulation-matrix-form"] button[type="submit"]').click();
  await page.waitForURL((u) => u.searchParams.get('tab') === 'circulation');
}

/** Pick a value in one of the admin autocompletes (user/book). */
async function fillAutocomplete(page, inputSelector, suggestSelector, query, apiUrlFragment) {
  for (let attempt = 1; attempt <= 3; attempt++) {
    await page.fill(inputSelector, '');
    const responsePromise = page.waitForResponse((r) => r.url().includes(apiUrlFragment) && r.status() === 200, { timeout: 15000 });
    await page.locator(inputSelector).pressSequentially(query, { delay: 40 });
    await responsePromise;
    const item = page.locator(`${suggestSelector} .suggestion-item`).first();
    if (await item.isVisible({ timeout: 3000 }).catch(() => false)) {
      await item.click();
      return;
    }
  }
  await page.locator(`${suggestSelector} .suggestion-item`).first().click({ timeout: 5000 });
}

/**
 * Lend $book to $patron from the staff desk form; returns the redirect URL
 * (…/admin/loans?… on success, …/admin/loans/create?error=… on refusal).
 */
async function staffLoan(page, patron, book, { copyCode = '' } = {}) {
  await page.goto(`${BASE}/admin/loans/create`);
  await page.waitForLoadState('domcontentloaded');
  await fillAutocomplete(page, '#utente_search', '#utente_suggest', patron.surname, '/api/search/utenti');
  expect(Number(await page.locator('#utente_id').inputValue())).toBe(patron.id);
  const availability = page.waitForResponse((r) => r.url().includes('/availability') && r.url().includes(`for_user=${patron.id}`), { timeout: 15000 }).catch(() => null);
  if (copyCode) {
    const resolved = page.waitForResponse((r) => r.url().includes('/admin/copies/by-code'));
    await page.locator('#copy_code').fill(copyCode);
    await page.locator('#copy_code').dispatchEvent('change');
    await resolved;
    await expect(page.locator('#libro_id')).toHaveValue(String(book.id));
  } else {
    await fillAutocomplete(page, '#libro_search', '#libro_suggest', book.title, '/api/search/libri');
  }
  expect(Number(await page.locator('#libro_id').inputValue())).toBe(book.id);
  // The borrower/book availability answer sets the due date of this patron
  // type and material: wait for it before submitting.
  await availability;
  await page.evaluate(() => new Promise((r) => requestAnimationFrame(() => r(null))));
  await page.evaluate(() => document.querySelectorAll('.flatpickr-calendar.open').forEach((c) => c.classList.remove('open')));
  await page.locator('button[type="submit"]:not([name="save_and_new"])').click();
  await page.waitForFunction(() => !window.location.pathname.endsWith('/create') || window.location.search.includes('error='), null, { timeout: 30000 });
  return page.url();
}

/** Open the book page as the logged-in patron and submit a request from the calendar popup. */
async function requestFromBookPage(page, bookId, startISO) {
  await page.goto(`${BASE}/libro/${bookId}`);
  await page.locator('#btn-request-loan').click();
  await page.waitForSelector('.swal2-popup', { timeout: 8000 });
  await page.waitForFunction(() => { const el = document.querySelector('#swal-date-start'); return el && /** @type {any} */ (el)._flatpickr; }, null, { timeout: 8000 });
  await page.evaluate((iso) => { /** @type {any} */ (document.querySelector('#swal-date-start'))._flatpickr.setDate(iso, true); }, startISO);
  await page.locator('.swal2-confirm').click();
  await page.waitForFunction(() => !!document.querySelector('.swal2-icon-success, .swal2-icon-error'), null, { timeout: 15000 });
  const ok = await page.locator('.swal2-icon-success').isVisible().catch(() => false);
  const text = (await page.locator('.swal2-html-container').textContent().catch(() => '')) || '';
  await page.locator('.swal2-confirm').click().catch(() => {});
  return { ok, text };
}

/** Whether the named plugin is active (false when it is not registered). */
function pluginActive(name) {
  return dbQuery(`SELECT is_active FROM plugins WHERE name=${sqlStr(name)} LIMIT 1`) === '1';
}

/**
 * Activate or deactivate a plugin through the admin endpoint, as the admin
 * plugin page does. `page` must be logged in as an administrator. A no-op
 * when the plugin is already in the requested state.
 */
async function setPluginActive(page, name, active) {
  const id = dbInt(`SELECT id FROM plugins WHERE name=${sqlStr(name)} LIMIT 1`);
  if (id <= 0) throw new Error(`${name} plugin is not registered`);
  if (pluginActive(name) === active) return;
  await page.goto(`${BASE}/admin/plugins`);
  const status = await page.evaluate(async ({ pid, action }) => {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const r = await fetch(`${window.location.origin}${window.BASE_PATH || ''}/admin/plugins/${pid}/${action}`, { method: 'POST', headers: { 'X-CSRF-Token': csrf } });
    return r.status;
  }, { pid: id, action: active ? 'activate' : 'deactivate' });
  if (status >= 400) throw new Error(`${name} ${active ? 'activate' : 'deactivate'} failed: HTTP ${status}`);
  if (pluginActive(name) !== active) throw new Error(`${name} is still ${active ? 'inactive' : 'active'}`);
}

/**
 * Snapshot the rows of a table (optionally filtered by `where`) so they can be
 * put back verbatim. Every value travels hex-encoded, so quotes, backslashes
 * and non-ASCII text survive the mysql batch output unchanged.
 */
function stashRows(table, where = '') {
  const cols = dbQuery(`SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=${sqlStr(table)}`);
  if (!cols) throw new Error(`table ${table} has no columns`);
  const select = cols.split(',')
    .map((c) => `IFNULL(CONCAT('CONVERT(X''', HEX(CAST(\`${c}\` AS CHAR)), ''' USING utf8mb4)'), 'NULL')`)
    .join(", ',', ");
  const filter = where ? ` WHERE ${where}` : '';
  const rows = dbQuery(`SELECT CONCAT(${select}) FROM \`${table}\`${filter}`).split('\n').filter(Boolean);
  return { table, filter, cols: cols.split(',').map((c) => `\`${c}\``).join(','), rows };
}

/** Put back a stashRows() snapshot in one transaction: all of it or nothing. */
function unstashRows(s) {
  if (!s) return;
  const sql = ['START TRANSACTION', `DELETE FROM \`${s.table}\`${s.filter}`];
  for (const values of s.rows) sql.push(`INSERT INTO \`${s.table}\` (${s.cols}) VALUES (${values})`);
  sql.push('COMMIT');
  dbQuery(sql.join(';\n'));
}

/** Run every cleanup step even when an earlier one throws; rethrow the first error. */
async function runAll(steps) {
  let first = null;
  for (const step of steps) {
    try { await step(); } catch (err) { if (!first) first = err; }
  }
  if (first) throw first;
}

module.exports = {
  BASE, ADMIN_EMAIL, ADMIN_PASS, CONFIGURED,
  dbQuery, dbInt, sqlStr, clearRateLimits, phpHash,
  createPatron, createTitle, deleteTitles, deletePatrons, deleteTypes,
  loginAs, loginAsAdmin, openCirculationTab, createTypeViaUi, setMatrixCell,
  fillAutocomplete, staffLoan, requestFromBookPage, copyCodeOf,
  appTodayISO, appDateOffsetISO,
  pluginActive, setPluginActive, stashRows, unstashRows, runAll,
};
