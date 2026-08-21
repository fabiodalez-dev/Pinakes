// @ts-check
//
// E2E — Multiple physical copies per borrower (#238, PR feature/multiple-copy-loans)
//
// Exercises the opt-in `loans.allow_multiple_loans_same_book` setting through
// the REAL admin desk flow (browser + Apache + the loan controller + the
// per-copy DB triggers), not just the backend policy. Five scenarios:
//   1. the settings toggle persists from the UI
//   2. ON  → one borrower may take two DISTINCT copies of the same title
//   3. ON  → the SAME copy cannot be lent twice to the same borrower
//   4. OFF → the historical borrower/title uniqueness rule still rejects a
//            second copy (backward compatibility)
//   5. ON  → "Salva e registra un'altra copia" keeps the title selected and
//            lets the operator scan the next copy (desk batch workflow)
//
// Self-provisions its own admin, borrowers, book and copies (tokenised), and
// cleans everything up in afterAll. Requires a real installed DB — the runner
// (/tmp/run-e2e.sh) supplies E2E_DB_* and the base URL.

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const crypto = require('crypto');

const BASE = process.env.E2E_BASE_URL || process.env.APP_URL || 'http://localhost:8081';
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS || '';
const DB_HOST = process.env.E2E_DB_HOST || '';
const DB_PORT = process.env.E2E_DB_PORT || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';
const DB_NAME = process.env.E2E_DB_NAME || '';

test.skip(
  !DB_USER || !DB_NAME,
  'E2E DB credentials not configured (set E2E_DB_USER, E2E_DB_NAME via /tmp/run-e2e.sh)',
);

// Serial: the tests share one book + copy pool and reset it between runs.
test.describe.configure({ mode: 'serial' });

/**
 * Run a MySQL statement and return trimmed stdout.
 * Connection precedence: TCP (-h/-P) → Unix socket (-S) → defaults.
 * Password is passed via MYSQL_PWD so it never appears in argv.
 */
function dbQuery(sql) {
  const args = [];
  if (DB_HOST) {
    args.push('-h', DB_HOST);
    if (DB_PORT) args.push('-P', DB_PORT);
  } else if (DB_SOCKET) {
    args.push('-S', DB_SOCKET);
  }
  args.push('-u', DB_USER, DB_NAME, '-N', '-B', '-e', sql);
  return execFileSync('mysql', args, {
    encoding: 'utf-8',
    timeout: 15000,
    env: { ...process.env, MYSQL_PWD: DB_PASS },
  }).trim();
}

/** Single-quote + escape a value for inlining as a SQL string literal. */
const S = (v) => "'" + String(v).replace(/'/g, "''") + "'";

/** Compute a bcrypt hash the app's password_verify() will accept. */
function phpPasswordHash(pw) {
  return execFileSync('php', ['-r', `echo password_hash(${JSON.stringify(pw)}, PASSWORD_DEFAULT);`], {
    encoding: 'utf-8',
    timeout: 15000,
  }).trim();
}

const RUN = crypto.randomBytes(4).toString('hex');
const DOMAIN = '@238mc.test.local';
const ADMIN_EMAIL = `mc-admin-${RUN}${DOMAIN}`;
const ADMIN_PASS = 'Mc238Pass!';

/** @type {{adminId:number, bookId:number, copies:Record<string,{id:number,inv:string}>, origSetting:{exists:boolean, valueHex:string|null}}} */
const fx = { adminId: 0, bookId: 0, copies: {}, origSetting: { exists: false, valueHex: null } };

let borrowerSeq = 0;

/** Provision a fresh standard borrower with a searchable, unique surname. */
function makeBorrower(tag) {
  borrowerSeq++;
  const surname = `Mc${tag}${RUN}`;
  const email = `mc-b-${tag}-${RUN}${DOMAIN}`;
  const card = `MC238${RUN.toUpperCase()}${borrowerSeq}`;
  const hash = phpPasswordHash('Borrow238!');
  dbQuery(
    `INSERT INTO utenti (codice_tessera, nome, cognome, email, password, tipo_utente, stato, email_verificata, privacy_accettata, locale)
     VALUES (${S(card)}, 'Borrower', ${S(surname)}, ${S(email)}, ${S(hash)}, 'standard', 'attivo', 1, 1, 'it_IT')`,
  );
  const id = Number(dbQuery(`SELECT id FROM utenti WHERE email=${S(email)} LIMIT 1`));
  return { id, surname, email };
}

/** Flip the opt-in setting directly in the DB (ConfigStore re-reads per request). */
function setMultiplicity(on) {
  dbQuery(
    `INSERT INTO system_settings (category, setting_key, setting_value)
     VALUES ('loans', 'allow_multiple_loans_same_book', ${on ? "'1'" : "'0'"})
     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)`,
  );
}

/** Wipe the test book's loans and free its copies so each test starts clean. */
function resetLoanState() {
  dbQuery(`DELETE FROM admin_notifications WHERE type='general' AND related_id IN (SELECT id FROM prestiti WHERE libro_id=${fx.bookId})`);
  dbQuery(`DELETE FROM prestiti WHERE libro_id=${fx.bookId}`);
  dbQuery(`UPDATE copie SET stato='disponibile' WHERE libro_id=${fx.bookId}`);
  dbQuery(`UPDATE libri SET copie_disponibili=copie_totali WHERE id=${fx.bookId}`);
}

/** Reproduce full-test's resilient autocomplete picker. */
async function fillAutocomplete(page, inputSelector, suggestSelector, query, apiUrlFragment) {
  for (let attempt = 1; attempt <= 3; attempt++) {
    await page.fill(inputSelector, '');
    const responsePromise = page.waitForResponse(
      (resp) => resp.url().includes(apiUrlFragment) && resp.status() === 200,
      { timeout: 15000 },
    );
    await page.locator(inputSelector).pressSequentially(query, { delay: 50 });
    await responsePromise;
    const suggestion = page.locator(`${suggestSelector} .suggestion-item`).first();
    if (await suggestion.isVisible({ timeout: 3000 }).catch(() => false)) {
      await suggestion.click();
      return;
    }
    if (attempt < 3) {
      await page.fill(inputSelector, '');
      await page.waitForTimeout(300);
    }
  }
  await page.locator(`${suggestSelector} .suggestion-item`).first().click({ timeout: 5000 });
}

async function loginAdmin(page) {
  await page.goto(`${BASE}/accedi`);
  await page.fill('input[name="email"]', ADMIN_EMAIL);
  await page.fill('input[name="password"]', ADMIN_PASS);
  await page.locator('form button[type="submit"], button[type="submit"]').first().click();
  await page.waitForURL((url) => !url.toString().includes('/accedi'), { timeout: 15000 });
}

/** Scan a copy code on the create form: the resolver auto-identifies the book. */
async function scanCopy(page, inv) {
  const resolved = page.waitForResponse(
    (r) => r.url().includes('/admin/copies/by-code') && r.status() === 200,
    { timeout: 15000 },
  );
  await page.fill('#copy_code', '');
  await page.locator('#copy_code').pressSequentially(inv, { delay: 30 });
  await resolved;
  await page.waitForTimeout(300); // let the resolver populate #libro_id
}

/** Wait for the create form to settle after a submit (success, batch, or error). */
async function waitLoanOutcome(page) {
  await page.waitForFunction(
    () => {
      const u = new URL(window.location.href);
      return (
        u.searchParams.has('created') ||
        u.searchParams.has('error') ||
        (u.pathname.startsWith('/admin/loans') && !u.pathname.endsWith('/create'))
      );
    },
    null,
    { timeout: 30000 },
  );
  return page.url();
}

/** Full desk create: pick borrower, scan a copy, submit. Returns the result URL. */
async function deskCreate(page, borrower, inv, { saveAndNew = false } = {}) {
  await page.goto(`${BASE}/admin/loans/create`);
  await page.waitForLoadState('domcontentloaded');
  await fillAutocomplete(page, '#utente_search', '#utente_suggest', borrower.surname, '/api/search/utenti');
  await scanCopy(page, inv);
  const btn = saveAndNew
    ? 'button[name="save_and_new"]'
    : 'button[type="submit"]:not([name="save_and_new"])';
  await page.locator(btn).click();
  return waitLoanOutcome(page);
}

/** Distinct active copy_ids currently committed by a borrower for the book. */
function activeCopyIds(userId) {
  const out = dbQuery(
    `SELECT copia_id FROM prestiti
     WHERE utente_id=${userId} AND libro_id=${fx.bookId} AND attivo=1
       AND stato IN ('prenotato','da_ritirare','in_corso','in_ritardo')
     ORDER BY copia_id`,
  );
  return out.split('\n').map((s) => s.trim()).filter(Boolean);
}

test.beforeAll(() => {
  // Preserve the current setting so afterAll can restore it EXACTLY.
  // dbQuery() trims stdout, so an existing empty-string value is
  // indistinguishable from a missing row when read directly: detect row
  // existence separately and serialize the value losslessly via HEX()
  // (NULL stays NULL — mysql -N -B prints it as the literal "NULL").
  const exists = dbQuery(
    `SELECT COUNT(*) FROM system_settings WHERE category='loans' AND setting_key='allow_multiple_loans_same_book'`,
  ) !== '0';
  const hex = exists
    ? dbQuery(
        `SELECT HEX(setting_value) FROM system_settings WHERE category='loans' AND setting_key='allow_multiple_loans_same_book' LIMIT 1`,
      )
    : null;
  fx.origSetting = { exists, valueHex: hex };

  // Admin for browser login.
  const adminHash = phpPasswordHash(ADMIN_PASS);
  dbQuery(
    `INSERT INTO utenti (codice_tessera, nome, cognome, email, password, tipo_utente, stato, email_verificata, privacy_accettata, locale)
     VALUES (${S('MCADM' + RUN.toUpperCase())}, 'Admin', ${S('Mc' + RUN)}, ${S(ADMIN_EMAIL)}, ${S(adminHash)}, 'admin', 'attivo', 1, 1, 'it_IT')`,
  );
  fx.adminId = Number(dbQuery(`SELECT id FROM utenti WHERE email=${S(ADMIN_EMAIL)} LIMIT 1`));

  // Book with three copies.
  dbQuery(`INSERT INTO libri (titolo, copie_totali, copie_disponibili) VALUES (${S('ZZ238MC ' + RUN + ' Multi Copy Book')}, 3, 3)`);
  fx.bookId = Number(dbQuery(`SELECT id FROM libri WHERE titolo=${S('ZZ238MC ' + RUN + ' Multi Copy Book')} ORDER BY id DESC LIMIT 1`));

  for (const tag of ['A', 'B', 'C']) {
    const inv = `MC238-${RUN}-${tag}`;
    dbQuery(`INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (${fx.bookId}, ${S(inv)}, 'disponibile')`);
    const id = Number(dbQuery(`SELECT id FROM copie WHERE numero_inventario=${S(inv)} LIMIT 1`));
    fx.copies[tag] = { id, inv };
  }
});

test.afterAll(() => {
  if (fx.bookId) {
    dbQuery(`DELETE FROM admin_notifications WHERE type='general' AND related_id IN (SELECT id FROM prestiti WHERE libro_id=${fx.bookId})`);
    dbQuery(`DELETE FROM prestiti WHERE libro_id=${fx.bookId}`);
    dbQuery(`DELETE FROM copie WHERE libro_id=${fx.bookId}`);
    dbQuery(`DELETE FROM libri WHERE id=${fx.bookId}`);
  }
  dbQuery(`DELETE FROM user_sessions WHERE utente_id IN (SELECT id FROM utenti WHERE email LIKE ${S('%' + RUN + DOMAIN)})`);
  dbQuery(`DELETE FROM utenti WHERE email LIKE ${S('%' + RUN + DOMAIN)}`);

  // Restore the original setting exactly (byte-for-byte via UNHEX; a HEX()
  // of NULL surfaces as the literal "NULL" and is restored as SQL NULL).
  if (fx.origSetting.exists) {
    const hex = fx.origSetting.valueHex;
    const valueExpr = hex === 'NULL'
      ? 'NULL'
      : `UNHEX('${String(hex).replace(/[^0-9A-Fa-f]/g, '')}')`;
    dbQuery(
      `INSERT INTO system_settings (category, setting_key, setting_value)
       VALUES ('loans', 'allow_multiple_loans_same_book', ${valueExpr})
       ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)`,
    );
  } else {
    dbQuery(`DELETE FROM system_settings WHERE category='loans' AND setting_key='allow_multiple_loans_same_book'`);
  }
});

test.beforeEach(() => {
  resetLoanState();
});

test('1. il toggle "più copie" persiste dalla UI', async ({ page }) => {
  setMultiplicity(false);
  await loginAdmin(page);

  await page.goto(`${BASE}/admin/settings?tab=loans`);
  await page.locator('[data-settings-tab="loans"]').click();

  // The real checkbox is `sr-only`; the styled toggle-bg div overlays it, so
  // force the state change instead of a pointer click.
  const toggle = page.locator('#allow_multiple_loans_same_book');
  await expect(toggle).not.toBeChecked();
  await toggle.check({ force: true });

  await page.locator('section[data-settings-panel="loans"] button[type="submit"]').first().click();
  await page.waitForLoadState('domcontentloaded');

  // The controller persisted it.
  expect(
    dbQuery(`SELECT setting_value FROM system_settings WHERE category='loans' AND setting_key='allow_multiple_loans_same_book'`),
  ).toBe('1');

  // And the reloaded UI reflects it.
  await page.goto(`${BASE}/admin/settings?tab=loans`);
  await page.locator('[data-settings-tab="loans"]').click();
  await expect(page.locator('#allow_multiple_loans_same_book')).toBeChecked();
});

test('2. ON → lo stesso utente ottiene due copie fisiche distinte', async ({ page }) => {
  setMultiplicity(true);
  const borrower = makeBorrower('T2');
  await loginAdmin(page);

  const url1 = await deskCreate(page, borrower, fx.copies.A.inv);
  expect(url1).not.toContain('error=');

  const url2 = await deskCreate(page, borrower, fx.copies.B.inv);
  expect(url2).not.toContain('error=');

  const copies = activeCopyIds(borrower.id);
  expect(copies.length).toBe(2);
  expect(new Set(copies).size).toBe(2); // distinct physical items
  expect(copies).toEqual(expect.arrayContaining([String(fx.copies.A.id), String(fx.copies.B.id)]));
});

test('3. ON → la stessa copia non può essere prestata due volte allo stesso utente', async ({ page }) => {
  setMultiplicity(true);
  const borrower = makeBorrower('T3');
  await loginAdmin(page);

  const url1 = await deskCreate(page, borrower, fx.copies.A.inv);
  expect(url1).not.toContain('error=');

  // Scanning the very same copy again must be refused.
  const url2 = await deskCreate(page, borrower, fx.copies.A.inv);
  expect(url2).toContain('error=');

  const cnt = Number(
    dbQuery(`SELECT COUNT(*) FROM prestiti WHERE utente_id=${borrower.id} AND libro_id=${fx.bookId} AND copia_id=${fx.copies.A.id} AND attivo=1`),
  );
  expect(cnt).toBe(1);
});

test('4. OFF → un secondo prestito dello stesso titolo è rifiutato (comportamento storico)', async ({ page }) => {
  setMultiplicity(false);
  const borrower = makeBorrower('T4');
  await loginAdmin(page);

  const url1 = await deskCreate(page, borrower, fx.copies.A.inv);
  expect(url1).not.toContain('error=');

  // Different copy, but strict mode keeps the borrower/title rule.
  const url2 = await deskCreate(page, borrower, fx.copies.B.inv);
  expect(url2).toContain('error=duplicate_reservation');

  const cnt = Number(
    dbQuery(`SELECT COUNT(*) FROM prestiti WHERE utente_id=${borrower.id} AND libro_id=${fx.bookId} AND attivo=1`),
  );
  expect(cnt).toBe(1);
});

test('5. ON → "Salva e registra un\'altra copia" mantiene il titolo e permette la scansione successiva', async ({ page }) => {
  setMultiplicity(true);
  const borrower = makeBorrower('T5');
  await loginAdmin(page);

  await page.goto(`${BASE}/admin/loans/create`);
  await page.waitForLoadState('domcontentloaded');
  await fillAutocomplete(page, '#utente_search', '#utente_suggest', borrower.surname, '/api/search/utenti');
  await scanCopy(page, fx.copies.A.inv);
  await page.locator('button[name="save_and_new"]').click();

  // Batch mode: back on the create form with the title + borrower retained.
  await page.waitForURL(
    (url) => url.pathname.endsWith('/admin/loans/create') && new URL(url.toString()).searchParams.get('created') === '1',
    { timeout: 30000 },
  );
  await expect(page.locator('#libro_id')).toHaveValue(String(fx.bookId)); // retained (the feature)
  await expect(page.locator('#utente_id')).toHaveValue(String(borrower.id));
  await expect(page.locator('#copy_code')).toHaveValue('');

  // Scan the next copy and finish the batch.
  await scanCopy(page, fx.copies.B.inv);
  await page.locator('button[type="submit"]:not([name="save_and_new"])').click();
  await waitLoanOutcome(page);

  const copies = activeCopyIds(borrower.id);
  expect(copies.length).toBe(2);
  expect(new Set(copies).size).toBe(2);
});
