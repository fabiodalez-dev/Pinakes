// @ts-check
// Closures calendar applied to circulation, as a librarian and a reader see it:
// the admin loan form proposes and stores a due date on an open day and
// refuses closed days in its pickers, a renewal lands on an open day, the
// pickup deadline counts open days only, and the reader's request calendar
// refuses a closed start day while a return date on a closed day moves to the
// next open one. Closures are written in the DB (setup); every action is a
// click in the UI. Dates come from the application timezone, not the runner.
//
// Run: /tmp/run-e2e.sh tests/closures-calendar-loans.spec.js --config=tests/playwright.config.js --workers=1
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';
const DB_HOST = process.env.E2E_DB_HOST || '';
const DB_PORT = process.env.E2E_DB_PORT || '';
const DB_NAME = process.env.E2E_DB_NAME || '';

test.skip(!ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME, 'E2E credentials not configured');

function db(sql) {
  const args = [];
  if (DB_HOST) { args.push('-h', DB_HOST); if (DB_PORT) args.push('-P', DB_PORT); } else if (DB_SOCKET) { args.push('-S', DB_SOCKET); }
  args.push('-u', DB_USER, DB_NAME, '-N', '-B', '-e', sql);
  return execFileSync('mysql', args, { encoding: 'utf-8', timeout: 10000, env: { ...process.env, MYSQL_PWD: DB_PASS } }).trim();
}
function addDays(iso, days) {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso);
  if (!m) throw new Error(`not an ISO date: ${iso}`);
  const d = new Date(Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3])));
  d.setUTCDate(d.getUTCDate() + days);
  return d.toISOString().slice(0, 10);
}
function weekday(iso) {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso);
  return new Date(Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3]))).getUTCDay();
}

const RUN = Date.now().toString().slice(-7);
const TAG = `E2E-CALLOAN-${RUN}`;
const READER_EMAIL = `calloan-${RUN}@example.test`;
const READER_PASS = 'CalLoan1234!';

let startMaxId = 0;
let prevWeekdays = '';
let readerId = 0;
let today = '';
let loanDays = 30;
let pickupDays = 3;
/** @type {Record<string, {id: number, inv: string}>} */
const books = {};

function closeDay(from, to = from) {
  db(`INSERT INTO calendario_chiusure (data_inizio, data_fine, descrizione) VALUES ('${from}', '${to}', '${TAG}')`);
}
function clearClosures() {
  db(`DELETE FROM calendario_chiusure WHERE descrizione='${TAG}'`);
}
function setWeekdays(value) {
  db(`INSERT INTO system_settings (category, setting_key, setting_value) VALUES ('loans','closed_weekdays','${value}') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)`);
}

async function loginAs(page, email, pass) {
  await page.goto(`${BASE}/accedi`);
  await page.fill('input[name="email"]', email);
  await page.fill('input[name="password"]', pass);
  await page.locator('button[type="submit"]').click();
  await page.waitForLoadState('domcontentloaded');
  await page.waitForURL((u) => !u.toString().includes('/accedi'), { timeout: 30000 });
}

async function selectBorrower(page) {
  await page.fill('#utente_search', READER_EMAIL);
  await page.waitForTimeout(400);
  const sug = page.locator('#utente_suggest .suggestion-item').first();
  await expect(sug).toBeVisible({ timeout: 8000 });
  await sug.click();
  await expect(page.locator('#utente_id')).toHaveValue(String(readerId));
}
async function resolveCopy(page, book) {
  await page.fill('#copy_code', book.inv);
  await expect.poll(async () => page.locator('#libro_id').inputValue(), { timeout: 8000 }).toBe(String(book.id));
}
async function flatpickrReady(page, selector) {
  await expect.poll(async () => page.evaluate((s) => !!(/** @type {any} */ (document.querySelector(s)))?._flatpickr, selector), { timeout: 8000 }).toBe(true);
}
async function submitLoanForm(page) {
  await Promise.all([
    page.waitForURL((u) => u.toString().includes('created=1') || u.toString().includes('error='), { timeout: 20000 }),
    page.click('form[action$="/admin/loans/create"] button[type="submit"]:not([name="save_and_new"])'),
  ]);
  expect(page.url()).toContain('created=1');
}
async function openRequestModal(page, book) {
  await page.goto(`${BASE}/libro/${book.id}`);
  const availability = page.waitForResponse((r) => /\/availability(\?|$)/.test(r.url()) && r.request().method() === 'GET', { timeout: 15000 });
  await page.locator('#btn-request-loan').click();
  const response = await availability;
  await page.waitForSelector('.swal2-popup', { timeout: 8000 });
  await flatpickrReady(page, '#swal-date-start');
  return response.json();
}

test.describe.configure({ mode: 'serial' });

test.describe('Closures calendar — circulation', () => {
  test.beforeAll(() => {
    startMaxId = parseInt(db('SELECT COALESCE(MAX(id), 0) FROM calendario_chiusure') || '0', 10);
    prevWeekdays = db("SELECT setting_value FROM system_settings WHERE category='loans' AND setting_key='closed_weekdays'");
    setWeekdays('');
    const tz = db("SELECT setting_value FROM system_settings WHERE category='app' AND setting_key='timezone' LIMIT 1") || 'Europe/Rome';
    today = new Intl.DateTimeFormat('en-CA', { timeZone: tz, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
    loanDays = parseInt(db("SELECT COALESCE((SELECT setting_value FROM system_settings WHERE category='loans' AND setting_key='loan_duration_days'), '30')"), 10) || 30;
    const maxDays = parseInt(db("SELECT COALESCE((SELECT setting_value FROM system_settings WHERE category='loans' AND setting_key='max_loan_duration_days'), '90')"), 10) || 90;
    loanDays = Math.min(loanDays, 3650);
    // The reader's default request duration is min(loan, max): keep the spec on one number.
    expect(maxDays).toBeGreaterThanOrEqual(loanDays);
    pickupDays = Math.max(1, parseInt(db("SELECT COALESCE((SELECT setting_value FROM system_settings WHERE category='loans' AND setting_key='pickup_expiry_days'), '3')"), 10) || 3);

    const hash = execFileSync('php', ['-r', `echo password_hash('${READER_PASS}', PASSWORD_DEFAULT);`], { encoding: 'utf-8' }).trim();
    db(`INSERT INTO utenti (codice_tessera, nome, cognome, email, password, stato, email_verificata, tipo_utente, created_at) VALUES ('CAL${RUN}', 'Cal', 'Reader', '${READER_EMAIL}', '${hash}', 'attivo', 1, 'standard', NOW())`);
    readerId = parseInt(db(`SELECT id FROM utenti WHERE email='${READER_EMAIL}'`), 10);
    for (const key of ['prefill', 'store', 'pickup', 'reprieve', 'branch', 'approve', 'approvebusy', 'crafted', 'renew', 'weekly', 'request', 'reqstart', 'api', 'zero']) {
      const title = `${TAG} ${key}`;
      db(`INSERT INTO libri (titolo, anno_pubblicazione, copie_totali, copie_disponibili, created_at, updated_at) VALUES ('${title}', 2024, 1, 1, NOW(), NOW())`);
      const id = parseInt(db(`SELECT id FROM libri WHERE titolo='${title}' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1`), 10);
      const inv = `CAL-${key}-${RUN}`;
      db(`INSERT INTO copie (libro_id, stato, numero_inventario, created_at) VALUES (${id}, 'disponibile', '${inv}', NOW())`);
      db(`UPDATE libri SET search_index = titolo WHERE id=${id}`);
      books[key] = { id, inv };
    }
  });

  test.afterAll(() => {
    db(`DELETE FROM calendario_chiusure WHERE descrizione='${TAG}' OR id > ${startMaxId}`);
    if (prevWeekdays === '') db("DELETE FROM system_settings WHERE category='loans' AND setting_key='closed_weekdays'");
    else setWeekdays(prevWeekdays);
    const ids = Object.values(books).map((b) => b.id).join(',') || '0';
    db(`DELETE FROM admin_notifications WHERE related_id IN (SELECT id FROM prestiti WHERE libro_id IN (${ids}))`);
    db(`DELETE FROM prestiti WHERE libro_id IN (${ids})`);
    db(`DELETE FROM prenotazioni WHERE libro_id IN (${ids})`);
    db(`DELETE FROM copie WHERE libro_id IN (${ids})`);
    db(`DELETE FROM libri WHERE id IN (${ids})`);
    if (readerId) db(`DELETE FROM utenti WHERE id=${readerId}`);
  });

  test.afterEach(() => { clearClosures(); setWeekdays(''); });

  // ── Admin loan form ──────────────────────────────────────────────────────
  test('Admin 1: the proposed due date skips a closed day', async ({ page }) => {
    const defaultDue = addDays(today, loanDays);
    closeDay(defaultDue);
    await loginAs(page, ADMIN_EMAIL, ADMIN_PASS);
    await page.goto(`${BASE}/admin/loans/create`);
    await expect(page.locator('#data_scadenza')).toHaveValue(addDays(defaultDue, 1));
  });

  test('Admin 2: closed days are disabled in both pickers, today stays selectable', async ({ page }) => {
    const closed = addDays(today, 5);
    closeDay(today);
    closeDay(closed);
    await loginAs(page, ADMIN_EMAIL, ADMIN_PASS);
    await page.goto(`${BASE}/admin/loans/create`);
    await flatpickrReady(page, '#data_scadenza');
    const state = await page.evaluate(({ closed, today }) => {
      const s = /** @type {any} */ (document.getElementById('data_prestito'))._flatpickr;
      const e = /** @type {any} */ (document.getElementById('data_scadenza'))._flatpickr;
      return { startClosed: s.isEnabled(closed), endClosed: e.isEnabled(closed), startToday: s.isEnabled(today) };
    }, { closed, today });
    expect(state.startClosed).toBe(false);
    expect(state.endClosed).toBe(false);
    expect(state.startToday).toBe(true);
  });

  test('Admin 3: a loan saved with a due date that became a closed day is stored on the next open day', async ({ page }) => {
    await loginAs(page, ADMIN_EMAIL, ADMIN_PASS);
    await page.goto(`${BASE}/admin/loans/create`);
    await selectBorrower(page);
    await resolveCopy(page, books.store);
    await page.uncheck('#scarica_pdf');
    const due = await page.locator('#data_scadenza').inputValue();
    expect(due).toMatch(/^\d{4}-\d{2}-\d{2}$/);
    // The library announces a closure on that very day while the form is open.
    closeDay(due);
    await submitLoanForm(page);
    expect(db(`SELECT data_scadenza FROM prestiti WHERE libro_id=${books.store.id} AND utente_id=${readerId}`)).toBe(addDays(due, 1));
  });

  test('Admin 4: the pickup deadline counts open days only', async ({ page }) => {
    // Two closed days right after today: N pickup days end two days later.
    closeDay(addDays(today, 1), addDays(today, 2));
    await loginAs(page, ADMIN_EMAIL, ADMIN_PASS);
    await page.goto(`${BASE}/admin/loans/create`);
    await selectBorrower(page);
    await resolveCopy(page, books.pickup);
    await page.uncheck('#consegna_immediata');
    await submitLoanForm(page);
    const row = db(`SELECT CONCAT(stato,'|',pickup_deadline) FROM prestiti WHERE libro_id=${books.pickup.id} AND utente_id=${readerId}`);
    expect(row).toBe(`da_ritirare|${addDays(today, pickupDays + 2)}`);
  });

  test('Admin 5: with no closures the due date and the pickup deadline are as before', async ({ page }) => {
    await loginAs(page, ADMIN_EMAIL, ADMIN_PASS);
    await page.goto(`${BASE}/admin/loans/create`);
    await expect(page.locator('#data_scadenza')).toHaveValue(addDays(today, loanDays));
    await selectBorrower(page);
    await resolveCopy(page, books.zero);
    await page.uncheck('#consegna_immediata');
    await submitLoanForm(page);
    expect(db(`SELECT CONCAT(data_scadenza,'|',pickup_deadline) FROM prestiti WHERE libro_id=${books.zero.id} AND utente_id=${readerId}`))
      .toBe(`${addDays(today, loanDays)}|${addDays(today, Math.min(pickupDays, loanDays))}`);
  });

  test('Admin 6: closing the past pickup deadline day from the calendar page keeps the pickup collectable', async ({ page }) => {
    // The deadline was yesterday; the librarian then declares yesterday closed.
    const yesterday = addDays(today, -1);
    const copy = db(`SELECT id FROM copie WHERE libro_id=${books.reprieve.id} LIMIT 1`);
    db(`UPDATE copie SET stato='prenotato' WHERE id=${copy}`);
    db(`INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, attivo, pickup_deadline, origine) VALUES (${books.reprieve.id}, ${copy}, ${readerId}, '${addDays(today, -4)}', '${addDays(today, 20)}', 'da_ritirare', 1, '${yesterday}', 'diretto')`);
    const loanId = parseInt(db(`SELECT id FROM prestiti WHERE libro_id=${books.reprieve.id} AND utente_id=${readerId} AND attivo=1`), 10);

    await loginAs(page, ADMIN_EMAIL, ADMIN_PASS);
    await page.goto(`${BASE}/admin/calendar`);
    await page.fill('#closure_start', yesterday);
    await page.fill('#closure_description', TAG);
    await Promise.all([
      page.waitForURL(/\/admin\/calendar/, { timeout: 15000 }),
      page.locator('#save-closure').click(),
    ]);
    // Yesterday was closed, so the reader still has today to come in.
    expect(db(`SELECT pickup_deadline FROM prestiti WHERE id=${loanId}`)).toBe(today);

    await page.goto(`${BASE}/admin/loans/pending`);
    const pickupBtn = page.locator(`.confirm-pickup-btn[data-loan-id="${loanId}"]`);
    await expect(pickupBtn).toBeVisible({ timeout: 10000 });
    await pickupBtn.click();
    await page.waitForSelector('.swal2-popup', { timeout: 10000 });
    const confirm = page.waitForResponse((r) => r.url().includes('/admin/loans/confirm-pickup') && r.request().method() === 'POST', { timeout: 15000 });
    await page.locator('.swal2-confirm').click();
    const body = await (await confirm).json().catch(() => ({}));
    expect(body.success, body.message || 'confirm-pickup refused').toBe(true);
    expect(db(`SELECT stato FROM prestiti WHERE id=${loanId}`)).toBe('in_corso');
  });

  test('Admin 7: closures of the copy\'s branch move the due date and lengthen the pickup days', async ({ page }) => {
    db(`INSERT INTO sedi (nome) VALUES ('${TAG} sede')`);
    const sede = parseInt(db(`SELECT id FROM sedi WHERE nome='${TAG} sede'`), 10);
    try {
      db(`UPDATE copie SET sede_id=${sede} WHERE libro_id=${books.branch.id}`);
      const due = addDays(today, loanDays);
      // Closed at that branch only: on the due date and on the day after today.
      db(`INSERT INTO calendario_chiusure (data_inizio, data_fine, descrizione, sede_id) VALUES ('${due}', '${due}', '${TAG}', ${sede}), ('${addDays(today, 1)}', '${addDays(today, 1)}', '${TAG}', ${sede})`);
      await loginAs(page, ADMIN_EMAIL, ADMIN_PASS);
      await page.goto(`${BASE}/admin/loans/create`);
      // The form does not know the copy yet: it proposes the library-wide date.
      await expect(page.locator('#data_scadenza')).toHaveValue(due);
      await selectBorrower(page);
      await resolveCopy(page, books.branch);
      await page.uncheck('#consegna_immediata');
      await submitLoanForm(page);
      expect(db(`SELECT CONCAT(stato,'|',data_scadenza,'|',pickup_deadline) FROM prestiti WHERE libro_id=${books.branch.id} AND utente_id=${readerId}`))
        .toBe(`da_ritirare|${addDays(due, 1)}|${addDays(today, Math.min(pickupDays + 1, loanDays + 1))}`);
    } finally {
      db(`DELETE FROM calendario_chiusure WHERE sede_id=${sede}`);
      db(`UPDATE copie SET sede_id=NULL WHERE libro_id=${books.branch.id}`);
      db(`DELETE FROM sedi WHERE id=${sede}`);
    }
  });

  test('Admin 8: approving a request onto a copy whose branch is closed on the due date moves it to the next open day', async ({ page }) => {
    db(`INSERT INTO sedi (nome) VALUES ('${TAG} sede approva')`);
    const sede = parseInt(db(`SELECT id FROM sedi WHERE nome='${TAG} sede approva'`), 10);
    try {
      db(`UPDATE copie SET sede_id=${sede} WHERE libro_id=${books.approve.id}`);
      const due = addDays(today, 10);
      db(`INSERT INTO calendario_chiusure (data_inizio, data_fine, descrizione, sede_id) VALUES ('${due}', '${due}', '${TAG}', ${sede})`);
      // A reader request waiting for approval (library-wide date, no copy yet).
      db(`INSERT INTO prestiti (libro_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo) VALUES (${books.approve.id}, ${readerId}, '${today}', '${due}', 'pendente', 'richiesta', 0)`);
      const loanId = parseInt(db(`SELECT id FROM prestiti WHERE libro_id=${books.approve.id} AND utente_id=${readerId} AND stato='pendente'`), 10);
      await loginAs(page, ADMIN_EMAIL, ADMIN_PASS);
      await page.goto(`${BASE}/admin/loans/pending`);
      const approve = page.locator(`.approve-btn[data-loan-id="${loanId}"]`);
      await expect(approve).toBeVisible({ timeout: 10000 });
      await approve.click();
      await page.waitForSelector('.swal2-popup', { timeout: 10000 });
      const reply = page.waitForResponse((r) => r.url().includes('/admin/loans/approve') && r.request().method() === 'POST', { timeout: 15000 });
      await page.locator('.swal2-confirm').click();
      expect((await (await reply).json()).success).toBe(true);
      expect(db(`SELECT CONCAT(stato,'|',data_scadenza) FROM prestiti WHERE id=${loanId}`)).toBe(`da_ritirare|${addDays(due, 1)}`);
    } finally {
      db(`DELETE FROM calendario_chiusure WHERE sede_id=${sede}`);
      db(`UPDATE copie SET sede_id=NULL WHERE libro_id=${books.approve.id}`);
      db(`DELETE FROM sedi WHERE id=${sede}`);
    }
  });

  test('Admin 9: the approval keeps the requested due date when the copy is taken right after it', async ({ page }) => {
    db(`INSERT INTO sedi (nome) VALUES ('${TAG} sede piena')`);
    const sede = parseInt(db(`SELECT id FROM sedi WHERE nome='${TAG} sede piena'`), 10);
    const book = books.approvebusy;
    let otherId = 0;
    try {
      const copyA = parseInt(db(`SELECT id FROM copie WHERE libro_id=${book.id} LIMIT 1`), 10);
      // A second copy keeps the title-level capacity free on the extra day.
      db(`INSERT INTO copie (libro_id, stato, numero_inventario, created_at) VALUES (${book.id}, 'disponibile', 'CAL-approvebusy2-${RUN}', NOW())`);
      db(`UPDATE libri SET copie_totali=2, copie_disponibili=2 WHERE id=${book.id}`);
      db(`UPDATE copie SET sede_id=${sede} WHERE id=${copyA}`);
      const due = addDays(today, 10);
      db(`INSERT INTO calendario_chiusure (data_inizio, data_fine, descrizione, sede_id) VALUES ('${due}', '${due}', '${TAG}', ${sede})`);
      // Copy A is promised to another reader from the day after the due date.
      db(`INSERT INTO utenti (codice_tessera, nome, cognome, email, password, stato, email_verificata, tipo_utente, created_at) VALUES ('CAO${RUN}', 'Cal', 'Other', 'calother-${RUN}@example.test', 'x', 'attivo', 1, 'standard', NOW())`);
      otherId = parseInt(db(`SELECT id FROM utenti WHERE email='calother-${RUN}@example.test'`), 10);
      db(`INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo) VALUES (${book.id}, ${copyA}, ${otherId}, '${addDays(due, 1)}', '${addDays(due, 20)}', 'prenotato', 'diretto', 1)`);
      // A request already holding copy A (as a promoted reservation does).
      db(`INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo) VALUES (${book.id}, ${copyA}, ${readerId}, '${today}', '${due}', 'pendente', 'prenotazione', 0)`);
      const loanId = parseInt(db(`SELECT id FROM prestiti WHERE libro_id=${book.id} AND stato='pendente'`), 10);
      await loginAs(page, ADMIN_EMAIL, ADMIN_PASS);
      await page.goto(`${BASE}/admin/loans/pending`);
      const approve = page.locator(`.approve-btn[data-loan-id="${loanId}"]`);
      await expect(approve).toBeVisible({ timeout: 10000 });
      await approve.click();
      await page.waitForSelector('.swal2-popup', { timeout: 10000 });
      const reply = page.waitForResponse((r) => r.url().includes('/admin/loans/approve') && r.request().method() === 'POST', { timeout: 15000 });
      await page.locator('.swal2-confirm').click();
      const body = await (await reply).json();
      expect(body.success, JSON.stringify(body)).toBe(true);
      expect(db(`SELECT CONCAT(stato,'|',copia_id,'|',data_scadenza) FROM prestiti WHERE id=${loanId}`)).toBe(`da_ritirare|${copyA}|${due}`);
    } finally {
      db(`DELETE FROM calendario_chiusure WHERE sede_id=${sede}`);
      db(`UPDATE copie SET sede_id=NULL WHERE libro_id=${book.id}`);
      db(`DELETE FROM sedi WHERE id=${sede}`);
      if (otherId) {
        db(`DELETE FROM prestiti WHERE utente_id=${otherId}`);
        db(`DELETE FROM utenti WHERE id=${otherId}`);
      }
    }
  });

  // ── Renewal ──────────────────────────────────────────────────────────────
  async function renewFromBookPage(page, book, loanId) {
    await page.goto(`${BASE}/admin/books/${book.id}`);
    const form = page.locator(`form[action*="/admin/loans/renew/${loanId}"]`).last();
    await form.waitFor({ state: 'attached', timeout: 10000 });
    await form.locator('button[type="submit"]').click();
    await page.waitForSelector('.swal2-popup', { timeout: 8000 });
    await Promise.all([
      page.waitForURL(/\/admin\/books\/\d+/, { timeout: 15000 }),
      page.locator('.swal2-confirm').click(),
    ]);
  }
  function activeLoan(book, due) {
    const copy = db(`SELECT id FROM copie WHERE libro_id=${book.id} LIMIT 1`);
    db(`UPDATE copie SET stato='prestato' WHERE id=${copy}`);
    db(`INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, attivo, renewals) VALUES (${book.id}, ${copy}, ${readerId}, '${today}', '${due}', 'in_corso', 1, 0)`);
    db(`UPDATE libri SET copie_disponibili=0 WHERE id=${book.id}`);
    return parseInt(db(`SELECT id FROM prestiti WHERE libro_id=${book.id} AND utente_id=${readerId} AND attivo=1`), 10);
  }

  test('Renew 1: a renewal ending on a closed day moves to the next open day', async ({ page }) => {
    const due = addDays(today, 5);
    const loanId = activeLoan(books.renew, due);
    closeDay(addDays(due, loanDays));
    await loginAs(page, ADMIN_EMAIL, ADMIN_PASS);
    await renewFromBookPage(page, books.renew, loanId);
    await expect.poll(() => db(`SELECT CONCAT(data_scadenza,'|',renewals) FROM prestiti WHERE id=${loanId}`), { timeout: 8000 })
      .toBe(`${addDays(due, loanDays + 1)}|1`);
  });

  test('Renew 2: a renewal ending on a weekly closed day moves to the next open day', async ({ page }) => {
    const due = addDays(today, 6);
    const loanId = activeLoan(books.weekly, due);
    const raw = addDays(due, loanDays);
    setWeekdays(String(weekday(raw)));
    await loginAs(page, ADMIN_EMAIL, ADMIN_PASS);
    await renewFromBookPage(page, books.weekly, loanId);
    await expect.poll(() => db(`SELECT data_scadenza FROM prestiti WHERE id=${loanId}`), { timeout: 8000 }).toBe(addDays(raw, 1));
  });

  // ── Reader's request calendar ────────────────────────────────────────────
  test('Request 1: the availability sent to the calendar lists the closed days', async ({ page }) => {
    const closed = addDays(today, 3);
    closeDay(closed);
    await loginAs(page, READER_EMAIL, READER_PASS);
    const data = await openRequestModal(page, books.api);
    expect(data.availability.closed_dates).toContain(closed);
    expect(data.availability.closed_dates).not.toContain(addDays(today, 4));
  });

  test('Request 2: a closed day cannot be chosen as the start day', async ({ page }) => {
    const closed = addDays(today, 3);
    closeDay(closed);
    await loginAs(page, READER_EMAIL, READER_PASS);
    await openRequestModal(page, books.reqstart);
    const result = await page.evaluate((closed) => {
      const fp = /** @type {any} */ (document.querySelector('#swal-date-start'))._flatpickr;
      fp.jumpToDate(closed);
      const el = Array.from(document.querySelectorAll('.flatpickr-day')).find((d) => /** @type {any} */ (d).dateObj && fp.formatDate(/** @type {any} */ (d).dateObj, 'Y-m-d') === closed && !d.classList.contains('prevMonthDay') && !d.classList.contains('nextMonthDay'));
      fp.setDate(closed, true);
      return { enabled: fp.isEnabled(closed), disabledClass: !!el && el.classList.contains('flatpickr-disabled'), title: el ? el.getAttribute('title') : null, selected: fp.input.value };
    }, closed);
    expect(result.enabled).toBe(false);
    expect(result.disabledClass).toBe(true);
    expect(result.title).toMatch(/Biblioteca chiusa/);
    expect(result.selected).not.toBe(closed);
  });

  test('Request 3: when today is closed the calendar proposes the next open day', async ({ page }) => {
    closeDay(today);
    await loginAs(page, READER_EMAIL, READER_PASS);
    await openRequestModal(page, books.reqstart);
    await expect(page.locator('#swal-date-start')).toHaveValue(addDays(today, 1));
  });

  test('Request 5: a request sent with a closed start day starts on the next open day', async ({ page }) => {
    // The calendar refuses a closed start day; a request built by hand gets
    // the same answer from the server, and the window it stored comes back.
    const closed = addDays(today, 2);
    closeDay(closed);
    await loginAs(page, READER_EMAIL, READER_PASS);
    await page.goto(`${BASE}/libro/${books.crafted.id}`);
    const sent = await page.evaluate(async ({ id, start }) => {
      const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
      const res = await fetch(`${/** @type {any} */ (window).BASE_PATH || ''}/api/libro/${id}/reservation`, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify({ start_date: start, csrf_token: csrf }),
      });
      return { status: res.status, body: await res.json() };
    }, { id: books.crafted.id, start: closed });
    expect(sent.body.success, JSON.stringify(sent.body)).toBe(true);
    expect(sent.body.start_date).toBe(addDays(closed, 1));
    expect(db(`SELECT data_prestito FROM prestiti WHERE libro_id=${books.crafted.id} AND utente_id=${readerId} ORDER BY id DESC LIMIT 1`)).toBe(addDays(closed, 1));
  });

  test('Request 4: a request whose return date falls on a closed day is saved with the next open day', async ({ page }) => {
    const start = addDays(today, 1);
    closeDay(addDays(start, loanDays));
    await loginAs(page, READER_EMAIL, READER_PASS);
    await openRequestModal(page, books.request);
    await page.evaluate((iso) => { /** @type {any} */ (document.querySelector('#swal-date-start'))._flatpickr.setDate(iso, true); }, start);
    await page.locator('#swal-date-end').evaluate((el) => /** @type {any} */ (el)._flatpickr.clear());
    await page.locator('.swal2-confirm').click();
    await page.waitForSelector('.swal2-icon-success', { timeout: 15000 });
    expect(db(`SELECT CONCAT(data_prestito,'|',data_scadenza) FROM prestiti WHERE libro_id=${books.request.id} AND utente_id=${readerId} ORDER BY id DESC LIMIT 1`))
      .toBe(`${start}|${addDays(start, loanDays + 1)}`);
  });
});
