// @ts-check
// Holidays applied to circulation. The holidays are configured as an
// administrator does it, from Admin → Calendar: the national preset (Italy:
// Christmas, St Stephen, Easter Monday) and the library's own holidays (a
// fixed day every year, one or two days long, switched off). They are
// evaluated from their rules, not from closure rows, so these tests check
// that the loan code really asks them: the admin loan form, the stored due
// date, the pickup deadline, a renewal, the reader's request calendar and
// the closures module switched off. Expected dates are computed here from
// the holidays the test configured, never through the application.
//
// Run: /tmp/run-e2e.sh tests/closures-calendar-holidays-loans.spec.js --config=tests/playwright.config.js --workers=1
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const ft = require('./helpers/feature-toggles');

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
  const [y, m, d] = iso.split('-').map(Number);
  return new Date(Date.UTC(y, m - 1, d)).getUTCDay();
}
// Gregorian Easter Sunday (anonymous algorithm), independent of the app.
function easterSunday(year) {
  const a = year % 19, b = Math.floor(year / 100), c = year % 100, d = Math.floor(b / 4), e = b % 4;
  const f = Math.floor((b + 8) / 25), g = Math.floor((b - f + 1) / 3), h = (19 * a + b - d - g + 15) % 30;
  const i = Math.floor(c / 4), k = c % 4, l = (32 + 2 * e + 2 * i - h - k) % 7, m = Math.floor((a + 11 * h + 22 * l) / 451);
  const month = Math.floor((h + l - 7 * m + 114) / 31), day = ((h + l - 7 * m + 114) % 31) + 1;
  return `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
}

const RUN = Date.now().toString().slice(-7);
const TAG = `E2E-HOLLOAN-${RUN}`;
const READER_EMAIL = `holloan-${RUN}@example.test`;
const READER_PASS = 'HolLoan1234!';

let readerId = 0;
let today = '';
let loanDays = 30;
let requestDays = 30;
let pickupDays = 3;
/** @type {Record<string, {id: number, inv: string}>} */
const books = {};
/** Snapshot of what the tests change, restored in afterAll. */
const prev = { weekdays: '', holidaySettings: '', maxClosureId: 0, maxCustomId: 0, features: /** @type {Record<string,string>} */ ({}) };
/** Days the test made closed, besides the weekly closed days below. */
let closedDays = new Set();
let closedWeekdays = new Set();

function nextOpen(day) {
  let d = day;
  for (let i = 0; i < 400 && (closedDays.has(d) || closedWeekdays.has(weekday(d))); i++) d = addDays(d, 1);
  return d;
}
function addOpenDays(from, n, capAt) {
  let d = from;
  for (let counted = 0, i = 0; counted < n && i < 400; i++) {
    d = addDays(d, 1);
    if (!closedDays.has(d) && !closedWeekdays.has(weekday(d))) counted++;
  }
  return capAt && d > capAt ? capAt : d;
}
// The next occurrence of month-day `md` (MM-DD) at least `minDays` after today.
function nextMonthDay(md, minDays) {
  for (let y = Number(today.slice(0, 4)); ; y++) {
    const iso = `${y}-${md}`;
    if (iso >= addDays(today, minDays)) return iso;
  }
}

async function loginAs(page, email, pass) {
  await page.goto(`${BASE}/accedi`);
  await page.fill('input[name="email"]', email);
  await page.fill('input[name="password"]', pass);
  await page.locator('button[type="submit"]').click();
  await page.waitForLoadState('domcontentloaded');
  await page.waitForURL((u) => !u.toString().includes('/accedi'), { timeout: 30000 });
}
async function submitAndLand(page, button) {
  await Promise.all([page.waitForURL(/\/admin\/calendar/, { timeout: 15000 }), page.locator(button).click()]);
}
// Admin → Calendar → national holidays: tick exactly `ids` for Italy, apply.
async function applyItalianPreset(page, ids) {
  await page.goto(`${BASE}/admin/calendar?holiday_country=IT#calendar-preset`);
  await page.locator('[data-holiday-select="none"]').click();
  for (const id of ids) await page.locator(`[data-holiday-row="IT:${id}"] input[data-holiday-rule]`).check();
  await submitAndLand(page, '#import-preset');
  await expect(page.locator('body')).toContainText(/Festività applicate/);
}
// Admin → Calendar → the library's holidays: a fixed day every year.
async function addLibraryHoliday(page, iso, days = 1) {
  await page.goto(`${BASE}/admin/calendar#calendar-custom`);
  await page.fill('#custom_name', `${TAG} ${iso}`);
  await page.selectOption('#custom_kind', 'fixed');
  await page.fill('#custom_fixed_day', String(Number(iso.slice(8, 10))));
  await page.selectOption('#custom_fixed_month', String(Number(iso.slice(5, 7))));
  await page.fill('#custom_days', String(days));
  await submitAndLand(page, '#save-custom-holiday');
  await expect(page.locator('#custom-holidays')).toContainText(`${TAG} ${iso}`);
  for (let i = 0; i < days; i++) closedDays.add(addDays(iso, i));
}
async function setWeekdaysInUi(page, days) {
  await page.goto(`${BASE}/admin/calendar#calendar-weekdays`);
  for (let w = 0; w <= 6; w++) {
    const box = page.locator(`#closed_weekday_${w}`);
    if ((await box.isChecked()) !== days.includes(w)) await box.click();
  }
  await submitAndLand(page, '#save-weekdays');
  closedWeekdays = new Set(days);
}
async function flatpickrReady(page, selector) {
  await expect.poll(async () => page.evaluate((s) => !!(/** @type {any} */ (document.querySelector(s)))?._flatpickr, selector), { timeout: 8000 }).toBe(true);
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
async function submitLoanForm(page) {
  await Promise.all([
    page.waitForURL((u) => u.toString().includes('created=1') || u.toString().includes('error='), { timeout: 20000 }),
    page.click('form[action$="/admin/loans/create"] button[type="submit"]:not([name="save_and_new"])'),
  ]);
  expect(page.url()).toContain('created=1');
}
// A desk loan as a librarian makes it: borrower, copy, the proposed dates.
async function deskLoan(page, book, { immediate = true } = {}) {
  await page.goto(`${BASE}/admin/loans/create`);
  const proposed = await page.locator('#data_scadenza').inputValue();
  await selectBorrower(page);
  await resolveCopy(page, book);
  await page.uncheck('#scarica_pdf').catch(() => {});
  if (!immediate) await page.uncheck('#consegna_immediata');
  await submitLoanForm(page);
  const [due, pickup] = db(`SELECT CONCAT(data_scadenza,'|',IFNULL(pickup_deadline,'-')) FROM prestiti WHERE libro_id=${book.id} AND utente_id=${readerId} ORDER BY id DESC LIMIT 1`).split('|');
  return { proposed, due, pickup };
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

test.describe('Holidays in circulation', () => {
  test.beforeAll(async () => {
    prev.weekdays = db("SELECT IFNULL((SELECT setting_value FROM system_settings WHERE category='loans' AND setting_key='closed_weekdays'), '')");
    prev.holidaySettings = db("SELECT IFNULL(GROUP_CONCAT(CONCAT_WS('|', setting_key, setting_value) SEPARATOR ';'), '') FROM system_settings WHERE category='calendar' AND setting_key LIKE 'holiday\\_%'");
    prev.maxClosureId = parseInt(db('SELECT COALESCE(MAX(id), 0) FROM calendario_chiusure') || '0', 10);
    prev.maxCustomId = parseInt(db('SELECT COALESCE(MAX(id), 0) FROM festivita_personalizzate') || '0', 10);
    prev.features = ft.readFeatures(['closures_calendar']);
    await ft.writeFeatures({ closures_calendar: '1' });
    // Expectations below know only the holidays this run configures: any
    // other closure would move a date for a reason the test cannot see.
    test.skip(db('SELECT COUNT(*) FROM calendario_chiusure WHERE attiva=1') !== '0'
      || db('SELECT COUNT(*) FROM festivita_personalizzate WHERE attiva=1') !== '0', 'the calendar already has closures');
    db("DELETE FROM system_settings WHERE category='loans' AND setting_key='closed_weekdays'");

    const tz = db("SELECT setting_value FROM system_settings WHERE category='app' AND setting_key='timezone' LIMIT 1") || 'Europe/Rome';
    today = new Intl.DateTimeFormat('en-CA', { timeZone: tz, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
    const setting = (key, def) => parseInt(db(`SELECT COALESCE((SELECT setting_value FROM system_settings WHERE category='loans' AND setting_key='${key}'), '${def}')`), 10) || def;
    loanDays = setting('loan_duration_days', 30);
    requestDays = Math.min(loanDays, setting('max_loan_duration_days', 90));
    pickupDays = Math.max(1, setting('pickup_expiry_days', 3));

    const hash = execFileSync('php', ['-r', `echo password_hash('${READER_PASS}', PASSWORD_DEFAULT);`], { encoding: 'utf-8' }).trim();
    db(`INSERT INTO utenti (codice_tessera, nome, cognome, email, password, stato, email_verificata, tipo_utente, created_at) VALUES ('HOL${RUN}', 'Hol', 'Reader', '${READER_EMAIL}', '${hash}', 'attivo', 1, 'standard', NOW())`);
    readerId = parseInt(db(`SELECT id FROM utenti WHERE email='${READER_EMAIL}'`), 10);
    for (const key of ['custom', 'twodays', 'weekly', 'off', 'pickup', 'renew', 'request', 'module', 'api']) {
      const title = `${TAG} ${key}`;
      db(`INSERT INTO libri (titolo, anno_pubblicazione, copie_totali, copie_disponibili, created_at, updated_at) VALUES ('${title}', 2024, 1, 1, NOW(), NOW())`);
      const id = parseInt(db(`SELECT id FROM libri WHERE titolo='${title}' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1`), 10);
      const inv = `HOL-${key}-${RUN}`;
      db(`INSERT INTO copie (libro_id, stato, numero_inventario, created_at) VALUES (${id}, 'disponibile', '${inv}', NOW())`);
      db(`UPDATE libri SET search_index = titolo WHERE id=${id}`);
      books[key] = { id, inv };
    }
  });

  test.afterAll(async () => {
    db(`DELETE FROM calendario_chiusure WHERE id > ${prev.maxClosureId}`);
    db(`DELETE FROM festivita_personalizzate WHERE id > ${prev.maxCustomId}`);
    db("DELETE FROM system_settings WHERE category='calendar' AND setting_key LIKE 'holiday\\_%'");
    for (const row of prev.holidaySettings ? prev.holidaySettings.split(';') : []) {
      const [key, value] = row.split('|');
      db(`INSERT INTO system_settings (category, setting_key, setting_value) VALUES ('calendar','${key}','${value.replace(/'/g, "''")}')`);
    }
    if (prev.weekdays === '') db("DELETE FROM system_settings WHERE category='loans' AND setting_key='closed_weekdays'");
    else db(`INSERT INTO system_settings (category, setting_key, setting_value) VALUES ('loans','closed_weekdays','${prev.weekdays.replace(/'/g, "''")}') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)`);
    await ft.writeFeatures(prev.features);
    const ids = Object.values(books).map((b) => b.id).join(',') || '0';
    db(`DELETE FROM admin_notifications WHERE (type IN ('new_loan_request','overdue_loan') OR (type='general' AND link LIKE '%/admin/loans%')) AND related_id IN (SELECT id FROM prestiti WHERE libro_id IN (${ids}))`);
    db(`DELETE FROM prestiti WHERE libro_id IN (${ids})`);
    db(`DELETE FROM prenotazioni WHERE libro_id IN (${ids})`);
    db(`DELETE FROM copie WHERE libro_id IN (${ids})`);
    db(`DELETE FROM libri WHERE id IN (${ids})`);
    if (readerId) db(`DELETE FROM utenti WHERE id=${readerId}`);
  });

  // Each test starts from an empty calendar: its own holidays only.
  test.beforeEach(() => {
    db(`DELETE FROM calendario_chiusure WHERE id > ${prev.maxClosureId}`);
    db(`DELETE FROM festivita_personalizzate WHERE id > ${prev.maxCustomId}`);
    db("DELETE FROM system_settings WHERE category='calendar' AND setting_key LIKE 'holiday\\_%'");
    db("DELETE FROM system_settings WHERE category='loans' AND setting_key='closed_weekdays'");
    closedDays = new Set();
    closedWeekdays = new Set();
  });

  // ── National holidays (preset) ───────────────────────────────────────────
  test('Preset 1: Christmas, St Stephen and Easter Monday are refused by the admin loan pickers', async ({ page }) => {
    const christmas = nextMonthDay('12-25', 1);
    const easterMonday = addDays(easterSunday(Number(today.slice(0, 4)) + (easterSunday(Number(today.slice(0, 4))) > today ? 0 : 1)), 1);
    await loginAs(page, ADMIN_EMAIL, ADMIN_PASS);
    await applyItalianPreset(page, ['christmas', 'st_stephen', 'easter_monday']);
    await page.goto(`${BASE}/admin/loans/create`);
    await flatpickrReady(page, '#data_scadenza');
    const enabled = await page.evaluate((days) => {
      const fp = /** @type {any} */ (document.getElementById('data_scadenza'))._flatpickr;
      return days.map((d) => fp.isEnabled(d));
    }, [christmas, addDays(christmas, 1), addDays(christmas, 2), easterMonday, addDays(easterMonday, 1)]);
    expect(enabled).toEqual([false, false, true, false, true]);
  });

  test('Preset 2: a renewal that would end on Christmas ends after St Stephen', async ({ page }) => {
    const christmas = nextMonthDay('12-25', loanDays + 2);
    const due = addDays(christmas, -loanDays);
    const copy = db(`SELECT id FROM copie WHERE libro_id=${books.renew.id} LIMIT 1`);
    db(`UPDATE copie SET stato='prestato' WHERE id=${copy}`);
    db(`INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, attivo, renewals) VALUES (${books.renew.id}, ${copy}, ${readerId}, '${today}', '${due}', 'in_corso', 1, 0)`);
    db(`UPDATE libri SET copie_disponibili=0 WHERE id=${books.renew.id}`);
    const loanId = parseInt(db(`SELECT id FROM prestiti WHERE libro_id=${books.renew.id} AND utente_id=${readerId} AND attivo=1`), 10);
    await loginAs(page, ADMIN_EMAIL, ADMIN_PASS);
    await applyItalianPreset(page, ['christmas', 'st_stephen']);
    closedDays = new Set([christmas, addDays(christmas, 1)]);
    await page.goto(`${BASE}/admin/books/${books.renew.id}`);
    const form = page.locator(`form[action*="/admin/loans/renew/${loanId}"]`).last();
    await form.waitFor({ state: 'attached', timeout: 10000 });
    await form.locator('button[type="submit"]').click();
    await page.waitForSelector('.swal2-popup', { timeout: 8000 });
    await Promise.all([page.waitForURL(/\/admin\/books\/\d+/, { timeout: 15000 }), page.locator('.swal2-confirm').click()]);
    await expect.poll(() => db(`SELECT CONCAT(data_scadenza,'|',renewals) FROM prestiti WHERE id=${loanId}`), { timeout: 8000 })
      .toBe(`${nextOpen(christmas)}|1`);
    expect(nextOpen(christmas)).toBe(addDays(christmas, 2));
  });

  test('Preset 3: the reader\'s request calendar receives the national holidays as closed days', async ({ page }) => {
    const christmas = nextMonthDay('12-25', 1);
    const browser = page.context().browser();
    const admin = await /** @type {any} */ (browser).newPage();
    await loginAs(admin, ADMIN_EMAIL, ADMIN_PASS);
    await applyItalianPreset(admin, ['christmas', 'st_stephen']);
    await admin.close();
    await loginAs(page, READER_EMAIL, READER_PASS);
    const data = await openRequestModal(page, books.api);
    const closed = data.availability.closed_dates;
    // The calendar only receives the days it can show (about five months).
    test.skip(christmas > addDays(today, requestDays + 120), 'Christmas is past the request horizon');
    expect(closed).toContain(christmas);
    expect(closed).toContain(addDays(christmas, 1));
    expect(closed).not.toContain(addDays(christmas, 2));
  });

  // ── The library's own holidays ───────────────────────────────────────────
  test('Library 1: a holiday on the due date moves the proposed and the stored due date to the next day', async ({ page }) => {
    const raw = addDays(today, loanDays);
    await loginAs(page, ADMIN_EMAIL, ADMIN_PASS);
    await addLibraryHoliday(page, raw);
    const loan = await deskLoan(page, books.custom);
    expect(loan.proposed).toBe(addDays(raw, 1));
    expect(loan.due).toBe(addDays(raw, 1));
  });

  test('Library 2: a two-day holiday on the due date moves it past both days', async ({ page }) => {
    const raw = addDays(today, loanDays);
    await loginAs(page, ADMIN_EMAIL, ADMIN_PASS);
    await addLibraryHoliday(page, raw, 2);
    const loan = await deskLoan(page, books.twodays);
    expect(loan.due).toBe(addDays(raw, 2));
  });

  test('Library 3: a holiday followed by a weekly closed day moves the due date past both', async ({ page }) => {
    const raw = addDays(today, loanDays);
    await loginAs(page, ADMIN_EMAIL, ADMIN_PASS);
    await addLibraryHoliday(page, raw);
    await setWeekdaysInUi(page, [weekday(addDays(raw, 1))]);
    const loan = await deskLoan(page, books.weekly);
    expect(loan.due).toBe(addDays(raw, 2));
    expect(loan.due).toBe(nextOpen(raw));
  });

  test('Library 4: a holiday switched off no longer moves the due date', async ({ page }) => {
    const raw = addDays(today, loanDays);
    await loginAs(page, ADMIN_EMAIL, ADMIN_PASS);
    await addLibraryHoliday(page, raw);
    await submitAndLand(page, `#custom-holidays tr:has-text("${TAG} ${raw}") [data-action="toggle-custom-holiday"]`);
    expect(db(`SELECT attiva FROM festivita_personalizzate WHERE nome='${TAG} ${raw}'`)).toBe('0');
    closedDays = new Set();
    const loan = await deskLoan(page, books.off);
    expect(loan.due).toBe(raw);
  });

  test('Library 5: the pickup deadline does not count a holiday', async ({ page }) => {
    const holiday = addDays(today, 1);
    await loginAs(page, ADMIN_EMAIL, ADMIN_PASS);
    await addLibraryHoliday(page, holiday);
    const loan = await deskLoan(page, books.pickup, { immediate: false });
    expect(loan.pickup).toBe(addOpenDays(today, pickupDays, loan.due));
    expect(loan.pickup).toBe(addDays(today, pickupDays + 1));
  });

  test('Library 6: a reader\'s request whose return date is a holiday is saved with the next day', async ({ page }) => {
    const start = addDays(today, 1);
    const raw = addDays(start, requestDays);
    const admin = await /** @type {any} */ (page.context().browser()).newPage();
    await loginAs(admin, ADMIN_EMAIL, ADMIN_PASS);
    await addLibraryHoliday(admin, raw);
    await admin.close();
    await loginAs(page, READER_EMAIL, READER_PASS);
    await openRequestModal(page, books.request);
    await page.evaluate((iso) => { /** @type {any} */ (document.querySelector('#swal-date-start'))._flatpickr.setDate(iso, true); }, start);
    await page.locator('#swal-date-end').evaluate((el) => /** @type {any} */ (el)._flatpickr.clear());
    await page.locator('.swal2-confirm').click();
    await page.waitForSelector('.swal2-icon-success', { timeout: 15000 });
    expect(db(`SELECT CONCAT(data_prestito,'|',data_scadenza) FROM prestiti WHERE libro_id=${books.request.id} AND utente_id=${readerId} ORDER BY id DESC LIMIT 1`))
      .toBe(`${start}|${addDays(raw, 1)}`);
  });

  test('Module off: with the closures calendar switched off holidays move nothing', async ({ page }) => {
    const raw = addDays(today, loanDays);
    await loginAs(page, ADMIN_EMAIL, ADMIN_PASS);
    await addLibraryHoliday(page, raw);
    await applyItalianPreset(page, ['christmas', 'st_stephen']);
    try {
      await ft.setModuleInUi(page, 'closures_calendar', false);
      const loan = await deskLoan(page, books.module);
      expect(loan.proposed).toBe(raw);
      expect(loan.due).toBe(raw);
    } finally {
      await ft.writeFeatures({ closures_calendar: '1' });
    }
  });
});
