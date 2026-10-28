// @ts-check
/**
 * Fines and patron ledger (plan §7), driven like a librarian and a reader would:
 * settings form, return screen, the reader record's "Movimenti" section, the
 * statistics page and the reader's own loans page. The database is used only to
 * create the overdue loans (due dates in the application's timezone) and to
 * verify what was written.
 *
 * Run: /tmp/run-e2e.sh tests/fines-ledger.spec.js --config=tests/playwright.config.js --workers=1
 */
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const { appTodayISO, appDateOffsetISO } = require('./helpers/app-date');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';
const DB_HOST = process.env.E2E_DB_HOST || '';
const DB_NAME = process.env.E2E_DB_NAME || '';
const MAILPIT_API = process.env.MAILPIT_API || 'http://localhost:8025/api/v1';

test.skip(!ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME, 'E2E credentials not configured');

const RUN = Date.now().toString(36);
const PATRON_EMAIL = `zz-fines-${RUN}@test.local`;
const PATRON_PASS = 'Test1234!';
const TITLE_PREFIX = `ZZ_FINES_${RUN}`;
const SETTING_KEYS = ['daily_fine', 'fine_grace_days', 'fine_max_per_loan', 'debt_block_threshold', 'fine_charged_email', 'max_renewals'];
const EMAIL_KEYS = ['type', 'driver_mode', 'smtp_host', 'smtp_port', 'smtp_security', 'smtp_username', 'smtp_password', 'from_email', 'from_name'];

function dbQuery(sql) {
  const args = [];
  if (DB_HOST) args.push('-h', DB_HOST); else if (DB_SOCKET) args.push('-S', DB_SOCKET);
  args.push('-u', DB_USER, DB_NAME, '-N', '-B', '-e', sql);
  return execFileSync('mysql', args, { encoding: 'utf-8', timeout: 15000, env: { ...process.env, MYSQL_PWD: DB_PASS } }).trim();
}
const esc = (s) => String(s).replace(/\\/g, '\\\\').replace(/'/g, "''");
function phpHash(password) {
  return execFileSync('php', ['-r', `echo password_hash(${JSON.stringify(password)}, PASSWORD_DEFAULT);`], { encoding: 'utf-8', timeout: 5000 }).trim();
}
/** Same rendering as FineParameters::format() for it_IT / EUR. */
function euro(cents) {
  const abs = Math.abs(cents);
  const units = Math.floor(abs / 100).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  return `${cents < 0 ? '-' : ''}${units},${String(abs % 100).padStart(2, '0')} €`;
}
function clearConfigCache() {
  const cacheDir = path.join(__dirname, '..', 'storage', 'cache');
  if (!fs.existsSync(cacheDir)) return;
  for (const name of fs.readdirSync(cacheDir)) {
    if (name.startsWith('pinakes_config_settings_raw_')) {
      try { fs.unlinkSync(path.join(cacheDir, name)); } catch { /* best effort */ }
    }
  }
}

const saved = { loans: {}, email: {} };
const state = { patronId: 0, books: [], loans: {} };

/** A book with one copy, out on an overdue loan to the patron, due $daysLate days ago. */
function overdueLoan(key, daysLate) {
  const title = `${TITLE_PREFIX}_${key}`;
  dbQuery(`INSERT INTO libri (titolo, copie_totali, copie_disponibili, stato) VALUES ('${esc(title)}', 1, 0, 'prestato')`);
  const bookId = Number(dbQuery(`SELECT id FROM libri WHERE titolo='${esc(title)}' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1`));
  dbQuery(`INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (${bookId}, '${esc(`ZZF-${RUN}-${key}`)}', 'prestato')`);
  const copyId = Number(dbQuery(`SELECT id FROM copie WHERE libro_id=${bookId} LIMIT 1`));
  const due = appDateOffsetISO(-daysLate);
  const start = appDateOffsetISO(-daysLate - 14);
  dbQuery(`INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo) VALUES (${bookId}, ${copyId}, ${state.patronId}, '${start}', '${due}', 'in_ritardo', 'diretto', 1)`);
  const loanId = Number(dbQuery(`SELECT id FROM prestiti WHERE libro_id=${bookId} ORDER BY id DESC LIMIT 1`));
  state.books.push(bookId);
  state.loans[key] = loanId;
  return loanId;
}
const balanceCents = () => Math.round(Number(dbQuery(`SELECT COALESCE(SUM(importo),0) FROM movimenti_lettore WHERE utente_id=${state.patronId}`)) * 100);

function cleanup() {
  dbQuery(`DELETE m FROM movimenti_lettore m JOIN utenti u ON u.id=m.utente_id WHERE u.email LIKE 'zz-fines-%@test.local'`);
  dbQuery(`DELETE p FROM prestiti p JOIN libri l ON l.id=p.libro_id WHERE l.titolo LIKE 'ZZ\\_FINES\\_%'`);
  dbQuery(`DELETE p FROM prestiti p JOIN utenti u ON u.id=p.utente_id WHERE u.email LIKE 'zz-fines-%@test.local'`);
  dbQuery(`DELETE c FROM copie c JOIN libri l ON l.id=c.libro_id WHERE l.titolo LIKE 'ZZ\\_FINES\\_%'`);
  dbQuery(`DELETE lm FROM log_modifiche lm JOIN libri l ON l.id=lm.record_id AND lm.tabella='libri' WHERE l.titolo LIKE 'ZZ\\_FINES\\_%'`);
  dbQuery(`DELETE FROM libri WHERE titolo LIKE 'ZZ\\_FINES\\_%'`);
  dbQuery(`DELETE FROM user_sessions WHERE utente_id IN (SELECT id FROM utenti WHERE email LIKE 'zz-fines-%@test.local')`);
  dbQuery(`DELETE FROM utenti WHERE email LIKE 'zz-fines-%@test.local'`);
  dbQuery(`DELETE FROM ruoli WHERE nome LIKE 'ZZ Fines %'`);
}
function readSetting(category, key) {
  const v = dbQuery(`SELECT setting_value FROM system_settings WHERE category='${category}' AND setting_key='${key}'`);
  const exists = dbQuery(`SELECT COUNT(*) FROM system_settings WHERE category='${category}' AND setting_key='${key}'`) === '1';
  return exists ? v : null;
}
function restoreSetting(category, key, value) {
  if (value === null) {
    dbQuery(`DELETE FROM system_settings WHERE category='${category}' AND setting_key='${key}'`);
  } else {
    dbQuery(`INSERT INTO system_settings (category, setting_key, setting_value) VALUES ('${category}', '${key}', '${esc(value)}') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)`);
  }
}

async function loginAdmin(page) {
  await page.goto(`${BASE}/admin/dashboard`);
  const email = page.locator('input[name="email"]');
  if (await email.isVisible({ timeout: 3000 }).catch(() => false)) {
    await email.fill(ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await page.click('button[type="submit"]');
    await page.waitForURL(/.*(?:dashboard|admin).*/, { timeout: 15000 });
  }
}

/** Fill the fines block of Settings → Loans and save, like an operator. */
async function saveFineSettings(page, { daily, grace, cap, threshold, email }) {
  await page.goto(`${BASE}/admin/settings?tab=loans`);
  await expect(page.locator('#daily_fine')).toBeVisible();
  if (daily !== undefined) await page.fill('#daily_fine', daily);
  if (grace !== undefined) await page.fill('#fine_grace_days', grace);
  if (cap !== undefined) await page.fill('#fine_max_per_loan', cap);
  if (threshold !== undefined) await page.fill('#debt_block_threshold', threshold);
  if (email !== undefined) {
    const box = page.locator('#fine_charged_email');
    if ((await box.isChecked()) !== email) {
      await page.locator('label:has(#fine_charged_email)').click();
    }
  }
  await page.locator('form[action$="/admin/settings/loans"] button[type="submit"]').click();
  await page.waitForURL(/tab=loans/, { timeout: 15000 });
}

/** Open the return screen and confirm, optionally waiving the late fine. */
async function returnFromDesk(page, loanId, waiveReason = null) {
  await page.goto(`${BASE}/admin/loans/returned/${loanId}`);
  if (waiveReason !== null) {
    await page.locator('#waive_fine').check();
    await expect(page.locator('#waive_reason')).toBeVisible();
    await page.fill('#waive_reason', waiveReason);
  }
  await page.getByRole('button', { name: 'Conferma restituzione' }).click();
  await page.waitForFunction(() => !window.location.pathname.includes('/admin/loans/returned/'), null, { timeout: 20000 });
}

test.describe.serial('Fines and patron ledger', () => {
  test.describe.configure({ timeout: 240000 });
  /** @type {import('@playwright/test').Page} */
  let page;

  test.beforeAll(async ({ browser }) => {
    for (const k of SETTING_KEYS) saved.loans[k] = readSetting('loans', k);
    for (const k of EMAIL_KEYS) saved.email[k] = readSetting('email', k);
    cleanup();
    const hash = phpHash(PATRON_PASS);
    const card = `ZZF${RUN}`.slice(0, 20);
    dbQuery(`INSERT INTO utenti (codice_tessera, nome, cognome, email, password, stato, email_verificata, tipo_utente, privacy_accettata, locale) VALUES ('${card}', 'Zz', 'Fines${RUN}', '${PATRON_EMAIL}', '${esc(hash)}', 'attivo', 1, 'standard', 1, 'it_IT')`);
    state.patronId = Number(dbQuery(`SELECT id FROM utenti WHERE email='${PATRON_EMAIL}'`));
    page = await browser.newPage();
    await loginAdmin(page);
  });

  test.afterAll(async () => {
    for (const k of SETTING_KEYS) restoreSetting('loans', k, saved.loans[k]);
    for (const k of EMAIL_KEYS) restoreSetting('email', k, saved.email[k]);
    clearConfigCache();
    cleanup();
    await page?.close();
  });

  test('1. the librarian turns fines on in Settings → Loans', async () => {
    await saveFineSettings(page, { daily: '0,50', grace: '2', cap: '', threshold: '', email: false });
    expect(readSetting('loans', 'daily_fine')).toBe('0.50');
    expect(readSetting('loans', 'fine_grace_days')).toBe('2');
    expect(readSetting('loans', 'fine_max_per_loan')).toBe('');
    // The form shows back what was saved.
    await expect(page.locator('#daily_fine')).toHaveValue('0.50');
  });

  test('2. the return screen shows the fine before the confirmation', async () => {
    const loanId = overdueLoan('A', 5);
    await page.goto(`${BASE}/admin/loans/returned/${loanId}`);
    const box = page.locator('#fine-preview');
    await expect(box).toBeVisible();
    await expect(box).toContainText('5 giorni di ritardo');
    await expect(box).toContainText(euro(250));
    // Choosing "lost" hides the late fine: a lost copy carries the assessed amount instead.
    await page.selectOption('#stato', 'perso');
    await expect(box).toBeHidden();
    await page.selectOption('#stato', 'restituito');
    await expect(box).toBeVisible();
  });

  test('3. confirming a late return charges the fine once and the reader record shows it', async () => {
    const loanId = state.loans.A;
    // The return form exactly as the browser would send it, kept for the replay below.
    await page.goto(`${BASE}/admin/loans/returned/${loanId}`);
    const form = page.locator(`form[action$="/admin/loans/returned/${loanId}"]`);
    const fields = await form.evaluate((f) => Object.fromEntries(new FormData(/** @type {HTMLFormElement} */ (f)).entries()));
    expect(String(fields.csrf_token || '')).not.toBe('');
    await returnFromDesk(page, loanId);
    expect(dbQuery(`SELECT CONCAT(stato,'|',attivo,'|',restituito_in_ritardo) FROM prestiti WHERE id=${loanId}`)).toBe('restituito|0|1');
    expect(dbQuery(`SELECT CONCAT(tipo,'|',importo,'|',chiave_idempotenza) FROM movimenti_lettore WHERE prestito_id=${loanId}`)).toBe(`multa_ritardo|2.50|late:${loanId}`);
    // A second POST of the same return (double click, back button, retried request) is refused and charges nothing.
    const replay = await page.request.post(`${BASE}/admin/loans/returned/${loanId}`, { form: /** @type {Record<string, string>} */ (fields), maxRedirects: 0 });
    expect(replay.status()).toBe(302);
    expect(String(replay.headers().location || '')).toContain('error=not_returnable');
    expect(dbQuery(`SELECT CONCAT(stato,'|',attivo) FROM prestiti WHERE id=${loanId}`)).toBe('restituito|0');
    expect(Number(dbQuery(`SELECT COUNT(*) FROM movimenti_lettore WHERE prestito_id=${loanId}`))).toBe(1);
    expect(balanceCents()).toBe(250);

    await page.goto(`${BASE}/admin/users/details/${state.patronId}`);
    const section = page.locator('#movimenti');
    await expect(section.locator('[data-ledger-balance]')).toHaveText(euro(250));
    await expect(section.locator('tr[data-movement-type="multa_ritardo"]')).toContainText('Multa per 5 giorni di ritardo');
  });

  test('4. registering a payment brings the balance to zero', async () => {
    await page.goto(`${BASE}/admin/users/details/${state.patronId}#movimenti`);
    await expect(page.locator('#ledger_pay_amount')).toHaveValue('2.50');
    await page.selectOption('#ledger_pay_method', 'contanti');
    await page.fill('#ledger_pay_note', 'Saldo al banco');
    await page.locator('#ledger-payment-form button[type="submit"]').click();
    await expect(page.locator('[data-ledger-flash="paid"]')).toContainText('Pagamento registrato.');
    await expect(page.locator('[data-ledger-balance]')).toHaveText(euro(0));
    expect(balanceCents()).toBe(0);
    expect(dbQuery(`SELECT CONCAT(importo,'|',metodo_pagamento,'|',descrizione) FROM movimenti_lettore WHERE utente_id=${state.patronId} AND tipo='pagamento'`)).toBe('-2.50|contanti|Saldo al banco');
  });

  test('5. the payment receipt downloads as a PDF', async () => {
    const link = page.locator('[data-ledger-receipt]');
    await expect(link).toBeVisible();
    const href = await link.getAttribute('href');
    expect(href).toMatch(/\/admin\/users\/\d+\/ledger\/receipt\/\d+$/);
    const res = await page.request.get(new URL(String(href), BASE).toString());
    expect(res.status()).toBe(200);
    expect(res.headers()['content-type']).toContain('application/pdf');
    const body = await res.body();
    expect(body.subarray(0, 4).toString()).toBe('%PDF');
    // Another reader's receipt id under this reader's URL is a 404, not a leak.
    const otherId = Number(dbQuery(`SELECT COALESCE(MAX(id),0)+1000 FROM movimenti_lettore`));
    expect((await page.request.get(`${BASE}/admin/users/${state.patronId}/ledger/receipt/${otherId}`)).status()).toBe(404);
  });

  test('6. a manual charge needs a description and raises the balance', async () => {
    await page.goto(`${BASE}/admin/users/details/${state.patronId}#movimenti`);
    await page.fill('#ledger_charge_amount', '7');
    await page.fill('#ledger_charge_description', 'Tessera sostitutiva');
    await page.locator('#ledger-charge-form button[type="submit"]').click();
    await expect(page.locator('[data-ledger-flash="charged"]')).toBeVisible();
    await expect(page.locator('[data-ledger-balance]')).toHaveText(euro(700));
    await expect(page.locator('tr[data-movement-type="addebito_manuale"]')).toContainText('Tessera sostitutiva');
    expect(balanceCents()).toBe(700);
  });

  test('7. a waiver from the reader record needs a reason', async () => {
    await page.goto(`${BASE}/admin/users/details/${state.patronId}#movimenti`);
    await page.fill('#ledger_waive_amount', '2');
    // Without a reason the browser refuses to send the form...
    await page.locator('#ledger-waive-form button[type="submit"]').click();
    expect(await page.locator('#ledger_waive_reason').evaluate((el) => /** @type {HTMLInputElement} */ (el).validity.valueMissing)).toBe(true);
    expect(Number(dbQuery(`SELECT COUNT(*) FROM movimenti_lettore WHERE utente_id=${state.patronId} AND tipo='condono'`))).toBe(0);
    // ...and the server refuses it too (a request built by hand).
    const csrf = await page.locator('#ledger-waive-form input[name="csrf_token"]').inputValue();
    const forged = await page.request.post(`${BASE}/admin/users/${state.patronId}/ledger/waive`, { form: { csrf_token: csrf, importo: '2', motivazione: '  ' }, maxRedirects: 0 });
    expect(forged.status()).toBe(302);
    expect(forged.headers().location).toContain('ledger_error=reason_required');
    // With a reason it is recorded.
    await page.fill('#ledger_waive_reason', 'Errore di registrazione');
    await page.locator('#ledger-waive-form button[type="submit"]').click();
    await expect(page.locator('[data-ledger-flash="waived"]')).toBeVisible();
    await expect(page.locator('[data-ledger-balance]')).toHaveText(euro(500));
    expect(dbQuery(`SELECT CONCAT(importo,'|',descrizione) FROM movimenti_lettore WHERE utente_id=${state.patronId} AND tipo='condono'`)).toBe('-2.00|Errore di registrazione');
  });

  test('8. a payment larger than the debt is refused with a message', async () => {
    await page.goto(`${BASE}/admin/users/details/${state.patronId}#movimenti`);
    await page.fill('#ledger_pay_amount', '50');
    await page.locator('#ledger-payment-form button[type="submit"]').click();
    await expect(page.locator('[data-ledger-error="amount_exceeds_balance"]')).toContainText('supera il saldo');
    expect(balanceCents()).toBe(500);
  });

  test('9. the late fine can be waived on the spot at the return desk', async () => {
    const loanId = overdueLoan('B', 4); // grace 2 → 4 days are all charged: 2,00
    await page.goto(`${BASE}/admin/loans/returned/${loanId}`);
    await expect(page.locator('#fine-preview')).toContainText(euro(200));
    await returnFromDesk(page, loanId, 'Primo ritardo, avvisato a voce');
    expect(dbQuery(`SELECT GROUP_CONCAT(CONCAT(tipo,':',importo) ORDER BY id) FROM movimenti_lettore WHERE prestito_id=${loanId}`)).toBe('multa_ritardo:2.00,condono:-2.00');
    expect(dbQuery(`SELECT descrizione FROM movimenti_lettore WHERE prestito_id=${loanId} AND tipo='condono'`)).toBe('Primo ritardo, avvisato a voce');
    expect(balanceCents()).toBe(500);
    // The loan page does not show a fine that was waived.
    await page.goto(`${BASE}/admin/loans/details/${loanId}`);
    await expect(page.locator('h1, h2').first()).toBeVisible();
    await expect(page.locator('[data-loan-fine-charged]')).toHaveCount(0);
  });

  test('10. a return within the grace days charges nothing', async () => {
    const loanId = overdueLoan('G', 2);
    await page.goto(`${BASE}/admin/loans/returned/${loanId}`);
    await expect(page.locator('#fine-preview')).toHaveCount(0);
    await returnFromDesk(page, loanId);
    expect(Number(dbQuery(`SELECT COUNT(*) FROM movimenti_lettore WHERE prestito_id=${loanId}`))).toBe(0);
  });

  test('11. the reader sees balance, movements and the fine accruing on an open loan', async ({ browser }) => {
    const openLoan = overdueLoan('C', 3); // still out: 1,50 accruing
    const reader = await browser.newPage();
    try {
      await reader.goto(`${BASE}/accedi`);
      await reader.fill('input[name="email"]', PATRON_EMAIL);
      await reader.fill('input[name="password"]', PATRON_PASS);
      await reader.locator('button[type="submit"]').click();
      await reader.waitForURL((u) => !u.toString().includes('/accedi'), { timeout: 30000 });
      await reader.goto(`${BASE}/prenotazioni`);
      await expect(reader.locator('#saldo')).toContainText('Saldo e movimenti');
      await expect(reader.locator('[data-patron-balance]')).toHaveAttribute('data-patron-balance', '500');
      await expect(reader.locator('[data-patron-balance]')).toContainText(euro(500));
      await expect(reader.locator('[data-patron-accrued]')).toHaveAttribute('data-patron-accrued', '150');
      await expect(reader.locator('[data-patron-movement="addebito_manuale"]')).toContainText('Addebito manuale');
      await expect(reader.locator('[data-patron-movement="pagamento"]')).toContainText(euro(-250));
    } finally {
      await reader.close();
    }
    // The librarian sees the same accruing fine on the loan and on the reader record.
    await page.goto(`${BASE}/admin/loans/details/${openLoan}`);
    await expect(page.locator('[data-loan-fine-accrued]')).toHaveText(euro(150));
    await page.goto(`${BASE}/admin/users/details/${state.patronId}`);
    await expect(page.locator('[data-ledger-accrued]')).toHaveAttribute('data-ledger-accrued', '150');
  });

  test('12. the statistics page and its CSV report charged, collected and waived', async () => {
    const today = appTodayISO();
    const day = `DATE(created_at)='${today}'`;
    const sum = (where) => Math.round(Number(dbQuery(`SELECT COALESCE(SUM(importo),0) FROM movimenti_lettore WHERE ${day} AND ${where}`)) * 100);
    const charged = sum("tipo IN ('multa_ritardo','addebito_perdita','addebito_danno','addebito_manuale')");
    const collected = -sum("tipo='pagamento'");
    const waived = -sum("tipo='condono'");
    expect(charged).toBeGreaterThanOrEqual(250 + 700 + 200);
    expect(collected).toBeGreaterThanOrEqual(250);
    expect(waived).toBeGreaterThanOrEqual(400);

    test.setTimeout(240000);
    await page.goto(`${BASE}/admin/statistics`, { waitUntil: 'domcontentloaded' });
    // The date fields are Flatpickr pickers (global date-input enhancement): set
    // them the way the calendar does, as the other date specs do.
    await page.waitForFunction(() => /** @type {any} */ (document.getElementById('fines_from'))?._flatpickr, null, { timeout: 15000 });
    await page.evaluate((iso) => {
      for (const id of ['fines_from', 'fines_to']) {
        /** @type {any} */ (document.getElementById(id))._flatpickr.setDate(iso, true);
      }
    }, today);
    await page.locator('#fines-report form button[type="submit"]').click();
    await page.waitForURL(/fines_from=/, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('[data-fines-total="addebitato"]')).toHaveText(euro(charged));
    await expect(page.locator('[data-fines-total="incassato"]')).toHaveText(euro(collected));
    await expect(page.locator('[data-fines-total="condonato"]')).toHaveText(euro(waived));

    const href = await page.locator('#fines-csv-link').getAttribute('href');
    const res = await page.request.get(new URL(String(href), BASE).toString());
    expect(res.status()).toBe(200);
    expect(res.headers()['content-type']).toContain('text/csv');
    const lines = (await res.text()).replace(/^﻿/, '').trim().split(/\r?\n/);
    expect(lines[0]).toContain('Mese');
    const total = lines[lines.length - 1].split(',');
    const dec = (c) => `${Math.floor(c / 100)}.${String(c % 100).padStart(2, '0')}`;
    expect(total).toEqual(['Totale', dec(charged), dec(collected), dec(waived), expect.any(String)]);
  });

  test('13. with the email option on, the reader receives "fine charged" (Mailpit)', async () => {
    let mailpit = false;
    try { mailpit = (await fetch(`${MAILPIT_API}/messages`, { signal: AbortSignal.timeout(3000) })).ok; } catch { mailpit = false; }
    test.skip(!mailpit, `Mailpit is not reachable at ${MAILPIT_API}`);

    dbQuery(`INSERT INTO system_settings (category, setting_key, setting_value) VALUES
      ('email','type','smtp'),('email','driver_mode','smtp'),('email','smtp_host','localhost'),('email','smtp_port','1025'),
      ('email','smtp_security',''),('email','smtp_username',''),('email','smtp_password',''),
      ('email','from_email','test@biblioteca.local'),('email','from_name','Pinakes Test')
      ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)`);
    clearConfigCache();
    // Saving the loans tab through the app also invalidates the settings cache in the web server.
    await saveFineSettings(page, { email: true });
    expect(readSetting('loans', 'fine_charged_email')).toBe('1');

    await returnFromDesk(page, state.loans.C);
    expect(dbQuery(`SELECT importo FROM movimenti_lettore WHERE prestito_id=${state.loans.C} AND tipo='multa_ritardo'`)).toBe('1.50');

    const query = `to:${PATRON_EMAIL} subject:"Multa per ritardo"`;
    let message = null;
    const deadline = Date.now() + 30000;
    while (!message && Date.now() < deadline) {
      const data = await (await fetch(`${MAILPIT_API}/search?query=${encodeURIComponent(query)}`)).json();
      message = data.messages && data.messages[0] ? data.messages[0] : null;
      if (!message) await new Promise((r) => setTimeout(r, 500));
    }
    expect(message, 'fine_charged email in Mailpit').not.toBeNull();
    const full = await (await fetch(`${MAILPIT_API}/message/${message.ID}`)).json();
    expect(full.Subject).toContain(euro(150));
    expect(full.HTML).toContain(`${TITLE_PREFIX}_C`);
    expect(full.HTML).toContain('3'); // days late
    expect(full.HTML).toContain(euro(650)); // balance: 5,00 + 1,50
  });

  test('13b. a fine waived at the desk: the return email has no fine line and no fine email is sent (Mailpit)', async () => {
    let mailpit = false;
    try { mailpit = (await fetch(`${MAILPIT_API}/messages`, { signal: AbortSignal.timeout(3000) })).ok; } catch { mailpit = false; }
    test.skip(!mailpit, `Mailpit is not reachable at ${MAILPIT_API}`);
    expect(readSetting('loans', 'fine_charged_email')).toBe('1');

    const loanId = overdueLoan('W', 4);
    await returnFromDesk(page, loanId, 'Condonata al banco');
    expect(dbQuery(`SELECT GROUP_CONCAT(tipo ORDER BY id) FROM movimenti_lettore WHERE prestito_id=${loanId}`)).toBe('multa_ritardo,condono');

    // Both emails are decided in the return request: once the return email
    // is in Mailpit, the fine email would be there too.
    const title = `${TITLE_PREFIX}_W`;
    const search = async (query) => (await (await fetch(`${MAILPIT_API}/search?query=${encodeURIComponent(query)}`)).json()).messages || [];
    let returned = null;
    const deadline = Date.now() + 30000;
    while (!returned && Date.now() < deadline) {
      for (const m of await search(`to:${PATRON_EMAIL} subject:"Restituzione"`)) {
        const full = await (await fetch(`${MAILPIT_API}/message/${m.ID}`)).json();
        if (full.HTML.includes(title)) { returned = full; break; }
      }
      if (!returned) await new Promise((r) => setTimeout(r, 500));
    }
    expect(returned, 'loan_returned email in Mailpit').not.toBeNull();
    expect(returned.HTML).not.toContain('Multa per ritardo');
    for (const m of await search(`to:${PATRON_EMAIL} subject:"Multa per ritardo"`)) {
      const full = await (await fetch(`${MAILPIT_API}/message/${m.ID}`)).json();
      expect(full.HTML, 'no fine email for the waived loan').not.toContain(title);
    }
  });

  test('14. with fines turned off again, a late return charges nothing (zero configuration)', async () => {
    await saveFineSettings(page, { daily: '', grace: '0', email: false });
    expect(readSetting('loans', 'daily_fine')).toBe('');
    const loanId = overdueLoan('Z', 30);
    await page.goto(`${BASE}/admin/loans/returned/${loanId}`);
    await expect(page.locator('#fine-preview')).toHaveCount(0);
    await returnFromDesk(page, loanId);
    expect(dbQuery(`SELECT stato FROM prestiti WHERE id=${loanId}`)).toBe('restituito');
    expect(Number(dbQuery(`SELECT COUNT(*) FROM movimenti_lettore WHERE prestito_id=${loanId}`))).toBe(0);
  });

  test('15. a desk form sent twice records one payment (double click, lost reply)', async () => {
    dbQuery(`INSERT INTO movimenti_lettore (utente_id, tipo, importo, descrizione, created_at) VALUES (${state.patronId}, 'addebito_manuale', 3.00, 'ZZ doppio invio', NOW())`);
    const before = balanceCents();
    await page.goto(`${BASE}/admin/users/details/${state.patronId}`);
    await page.fill('#ledger_pay_amount', '1,00');
    const form = page.locator('#ledger-payment-form');
    const fields = await form.evaluate((f) => Object.fromEntries(new FormData(/** @type {HTMLFormElement} */ (f)).entries()));
    expect(String(fields.request_key)).toMatch(/^[a-f0-9]{32}$/);
    const action = await form.getAttribute('action');
    // The same submission twice, as a browser retry would send it.
    for (let i = 0; i < 2; i++) {
      const r = await page.request.post(new URL(String(action), BASE).toString(), { form: /** @type {Record<string, string>} */ (fields), maxRedirects: 0 });
      expect(r.status()).toBe(302);
    }
    expect(balanceCents()).toBe(before - 100);
    expect(Number(dbQuery(`SELECT COUNT(*) FROM movimenti_lettore WHERE utente_id=${state.patronId} AND chiave_idempotenza='form:${fields.request_key}'`))).toBe(1);
    // A fresh form is a new payment.
    await page.goto(`${BASE}/admin/users/details/${state.patronId}`);
    await page.fill('#ledger_pay_amount', '1,00');
    await page.locator('#ledger-payment-form button[type="submit"]').click();
    await expect(page.locator('[data-ledger-flash="paid"]')).toBeVisible();
    expect(balanceCents()).toBe(before - 200);
  });

  test('16. a long ledger pages through older movements on the reader record', async () => {
    const existing = Number(dbQuery(`SELECT COUNT(*) FROM movimenti_lettore WHERE utente_id=${state.patronId}`));
    const rows = [];
    for (let i = 0; i < 205; i++) rows.push(`(${state.patronId}, 'addebito_manuale', 0.01, 'ZZ pagina ${i}', DATE_SUB(NOW(), INTERVAL ${400 + i} DAY))`);
    dbQuery(`INSERT INTO movimenti_lettore (utente_id, tipo, importo, descrizione, created_at) VALUES ${rows.join(',')}`);
    const total = existing + 205;
    await page.goto(`${BASE}/admin/users/details/${state.patronId}`);
    const pager = page.locator('[data-ledger-pager]');
    await expect(pager).toContainText(`1–200 di ${total}`);
    await expect(page.locator('#ledger-movements tbody tr')).toHaveCount(200);
    await expect(page.locator('#ledger-movements')).not.toContainText('ZZ pagina 204');
    await page.locator('[data-ledger-next]').click();
    await expect(page).toHaveURL(/ledger_page=2/);
    await expect(page.locator('#ledger-movements')).toContainText('ZZ pagina 204');
    await expect(page.locator('#ledger-movements tbody tr')).toHaveCount(total - 200);
    await page.locator('[data-ledger-prev]').click();
    await expect(page.locator('#ledger-movements tbody tr')).toHaveCount(200);
  });

  test('17. staff saving the loans tab cannot change fines or the debt threshold', async ({ browser }) => {
    const email = `zz-fines-staff-${RUN}@test.local`;
    dbQuery(`DELETE FROM utenti WHERE email='${email}'`);
    dbQuery(`INSERT INTO utenti (codice_tessera, nome, cognome, email, password, stato, email_verificata, tipo_utente, privacy_accettata) VALUES ('${`ZZS${RUN}`.slice(0, 20)}', 'Zz', 'Staff', '${email}', '${esc(phpHash(PATRON_PASS))}', 'attivo', 1, 'staff', 1)`);
    const before = { daily: readSetting('loans', 'daily_fine'), threshold: readSetting('loans', 'debt_block_threshold') };
    const staff = await browser.newPage();
    try {
      await staff.goto(`${BASE}/accedi`);
      await staff.fill('input[name="email"]', email);
      await staff.fill('input[name="password"]', PATRON_PASS);
      await staff.locator('button[type="submit"]').click();
      await staff.waitForURL((u) => !u.toString().includes('/accedi'), { timeout: 30000 });
      await staff.goto(`${BASE}/admin/settings?tab=loans`);
      await expect(staff.locator('[data-testid="fines-admin-only"]')).toBeVisible();
      await expect(staff.locator('#daily_fine')).toBeDisabled();
      await expect(staff.locator('#debt_block_threshold')).toBeDisabled();
      // A hand-built POST with fine values is ignored for those keys only.
      const form = staff.locator('form[action$="/admin/settings/loans"]');
      const fields = await form.evaluate((f) => Object.fromEntries(new FormData(/** @type {HTMLFormElement} */ (f)).entries()));
      const r = await staff.request.post(`${BASE}/admin/settings/loans`, { form: { ...fields, daily_fine: '9,99', debt_block_threshold: '1', max_renewals: '7' }, maxRedirects: 0 });
      expect(r.status()).toBe(302);
      expect(readSetting('loans', 'daily_fine')).toBe(before.daily);
      expect(readSetting('loans', 'debt_block_threshold')).toBe(before.threshold);
      expect(readSetting('loans', 'max_renewals')).toBe('7');
    } finally {
      await staff.close();
      dbQuery(`DELETE FROM utenti WHERE email='${email}'`);
    }
    // The administrator sees the fields enabled.
    await page.goto(`${BASE}/admin/settings?tab=loans`);
    await expect(page.locator('#daily_fine')).toBeEnabled();
    await expect(page.locator('[data-testid="fines-admin-only"]')).toHaveCount(0);
  });

  test('18. a custom role reads the ledger with users.manage and moves money only with loans.manage', async ({ browser }) => {
    // Two custom roles: one manages readers only, the other also runs the desk.
    dbQuery(`INSERT INTO ruoli (nome, permessi) VALUES ('ZZ Fines utenti ${RUN}', '["backend.access","users.manage"]'), ('ZZ Fines banco ${RUN}', '["backend.access","users.manage","loans.manage"]')`);
    const usersRole = Number(dbQuery(`SELECT id FROM ruoli WHERE nome='ZZ Fines utenti ${RUN}'`));
    const deskRole = Number(dbQuery(`SELECT id FROM ruoli WHERE nome='ZZ Fines banco ${RUN}'`));
    const email = `zz-fines-role-${RUN}@test.local`;
    dbQuery(`INSERT INTO utenti (codice_tessera, nome, cognome, email, password, stato, email_verificata, tipo_utente, ruolo_id, privacy_accettata) VALUES ('${`ZZR${RUN}`.slice(0, 20)}', 'Zz', 'Role', '${email}', '${esc(phpHash(PATRON_PASS))}', 'attivo', 1, 'standard', ${usersRole}, 1)`);
    const operatorId = Number(dbQuery(`SELECT id FROM utenti WHERE email='${email}'`));
    dbQuery(`INSERT INTO movimenti_lettore (utente_id, tipo, importo, descrizione, created_at) VALUES (${state.patronId}, 'addebito_manuale', 2.00, 'ZZ ruolo', NOW())`);
    const before = Number(dbQuery(`SELECT COUNT(*) FROM movimenti_lettore WHERE utente_id=${state.patronId}`));
    const operator = await browser.newPage();
    try {
      await operator.goto(`${BASE}/accedi`);
      await operator.fill('input[name="email"]', email);
      await operator.fill('input[name="password"]', PATRON_PASS);
      await operator.locator('button[type="submit"]').click();
      await operator.waitForURL((u) => !u.toString().includes('/accedi'), { timeout: 30000 });
      // users.manage: the reader record and its ledger are readable, nothing else.
      await operator.goto(`${BASE}/admin/users/details/${state.patronId}`);
      await expect(operator.locator('#movimenti [data-ledger-balance]')).toBeVisible();
      await expect(operator.locator('#ledger-movements tbody tr')).not.toHaveCount(0);
      await expect(operator.locator('#ledger-payment-form')).toHaveCount(0);
      await expect(operator.locator('#ledger-charge-form')).toHaveCount(0);
      await expect(operator.locator('#ledger-waive-form')).toHaveCount(0);
      await expect(operator.locator('#movimenti a[href*="/ledger/receipt/"]')).toHaveCount(0);
      // A hand-built POST is refused before the controller runs (no ledger flash, nothing written).
      const csrfInputs = operator.locator('input[name="csrf_token"]');
      const csrf = (await csrfInputs.count()) > 0 ? await csrfInputs.first().inputValue() : '';
      const refused = await operator.request.post(`${BASE}/admin/users/${state.patronId}/ledger/payment`, { form: { csrf_token: csrf, importo: '1', metodo_pagamento: 'contanti', request_key: 'a'.repeat(32) }, maxRedirects: 0 });
      expect([302, 403]).toContain(refused.status());
      expect(String(refused.headers().location || '')).not.toContain('ledger=');
      expect(Number(dbQuery(`SELECT COUNT(*) FROM movimenti_lettore WHERE utente_id=${state.patronId}`))).toBe(before);
      const payment = Number(dbQuery(`SELECT id FROM movimenti_lettore WHERE utente_id=${state.patronId} AND tipo='pagamento' ORDER BY id DESC LIMIT 1`));
      expect((await operator.request.get(`${BASE}/admin/users/${state.patronId}/ledger/receipt/${payment}`, { maxRedirects: 0 })).status()).not.toBe(200);

      // loans.manage added (the role is re-read on every request): the desk forms appear and work.
      dbQuery(`UPDATE utenti SET ruolo_id=${deskRole} WHERE id=${operatorId}`);
      // A fresh load: the same URL with only a #hash would not re-render the page.
      await operator.goto(`${BASE}/admin/users/details/${state.patronId}`);
      await operator.reload();
      await expect(operator.locator('#ledger-payment-form')).toBeVisible();
      await operator.fill('#ledger_pay_amount', '1,00');
      await operator.selectOption('#ledger_pay_method', 'contanti');
      await operator.locator('#ledger-payment-form button[type="submit"]').click();
      await expect(operator.locator('[data-ledger-flash="paid"]')).toContainText('Pagamento registrato.');
      expect(Number(dbQuery(`SELECT COUNT(*) FROM movimenti_lettore WHERE utente_id=${state.patronId}`))).toBe(before + 1);
      expect(Number(dbQuery(`SELECT creato_da FROM movimenti_lettore WHERE utente_id=${state.patronId} ORDER BY id DESC LIMIT 1`))).toBe(operatorId);
      const href = await operator.locator('[data-ledger-receipt]').getAttribute('href');
      expect((await operator.request.get(new URL(String(href), BASE).toString())).status()).toBe(200);
    } finally {
      await operator.close();
      dbQuery(`DELETE FROM user_sessions WHERE utente_id=${operatorId}`);
      dbQuery(`DELETE FROM utenti WHERE id=${operatorId}`);
      dbQuery(`DELETE FROM ruoli WHERE id IN (${usersRole}, ${deskRole})`);
    }
  });
});
