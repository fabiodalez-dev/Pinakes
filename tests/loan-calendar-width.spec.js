// @ts-check
// Loan request popup on the book page: the date pickers must fill the row of
// their field at every width (they were flatpickr's fixed 308px box in a 720px
// popup, and full-width only under 420px), and a reader must be able to move
// months ahead and pick a start on the last day of a month.
//
// Run: /tmp/run-e2e.sh tests/loan-calendar-width.spec.js --config=tests/playwright.config.js --workers=1
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';
const DB_NAME = process.env.E2E_DB_NAME || '';

test.skip(!ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME, 'E2E credentials not configured');

function db(sql) {
  const args = [];
  if (DB_SOCKET) args.push('-S', DB_SOCKET);
  args.push('-u', DB_USER, DB_NAME, '-N', '-B', '-e', sql);
  return execFileSync('mysql', args, { encoding: 'utf-8', timeout: 10000, env: { ...process.env, MYSQL_PWD: DB_PASS } }).trim();
}

/**
 * A book with a copy and nothing that occupies it (no loan in any open state,
 * pending requests with an assigned copy included, no active reservation):
 * every day in the calendar is free.
 */
function freeBookId() {
  const desiderata = db("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'libri' AND column_name = 'is_desiderata'") === '1';
  return db(`SELECT l.id FROM libri l
    WHERE l.deleted_at IS NULL ${desiderata ? 'AND l.is_desiderata = 0' : ''} AND l.copie_totali > 0
      AND NOT EXISTS (SELECT 1 FROM prestiti p WHERE p.libro_id = l.id
                        AND (p.attivo = 1 OR p.stato IN ('pendente', 'prenotato', 'da_ritirare', 'in_corso', 'in_ritardo')))
      AND NOT EXISTS (SELECT 1 FROM prenotazioni r WHERE r.libro_id = l.id AND r.stato = 'attiva')
    ORDER BY l.id LIMIT 1`);
}

async function openLoanPopup(page, bookId) {
  await page.goto(`${BASE}/accedi`);
  const rejectCookies = page.locator('#silktide-wrapper .reject-all');
  await rejectCookies.click({ timeout: 1500 }).catch(() => {});
  await page.fill('input[name="email"]', ADMIN_EMAIL);
  await page.fill('input[name="password"]', ADMIN_PASS);
  await page.locator('button[type="submit"]').click();
  await page.waitForURL(/admin/, { timeout: 30000 });
  await page.goto(`${BASE}/libro/${bookId}`);
  await page.locator('#btn-request-loan').click();
  await page.locator('.loan-request-popup .flatpickr-calendar').first().waitFor({ state: 'attached' });
}

/** Flatpickr controls are clicked in the page (project rule for tests/**). */
const clickIn = (page, selector, index = 0) => page.evaluate(([sel, i]) => {
  const el = document.querySelectorAll(sel)[i];
  if (!el) throw new Error(`no element for ${sel} [${i}]`);
  el.click();
}, [selector, index]);
const NEXT = '.loan-request-popup .flatpickr-calendar .flatpickr-next-month';

const shownMonth = (page) => page.evaluate(() => {
  const cal = document.querySelector('.loan-request-popup .flatpickr-calendar.open');
  const select = cal?.querySelector('.flatpickr-monthDropdown-months');
  return select ? `${select.value}/${cal.querySelector('.cur-year')?.value}` : null;
});

for (const [label, width] of [['desktop', 1280], ['phone', 390]]) {
  test(`${label}: the start calendar fills the row of its field`, async ({ page }) => {
    const bookId = freeBookId();
    expect(bookId, 'a free book is needed').not.toBe('');
    await page.setViewportSize({ width, height: 900 });
    await openLoanPopup(page, bookId);
    await clickIn(page, '#swal-date-start-display');

    const sizes = await page.evaluate(() => {
      const field = document.getElementById('swal-date-start-display');
      const cal = document.querySelector('.loan-request-popup .flatpickr-calendar.open');
      const day = cal?.querySelector('.flatpickr-day:not(.prevMonthDay):not(.nextMonthDay)');
      return {
        field: field?.getBoundingClientRect().width ?? 0,
        calendar: cal?.getBoundingClientRect().width ?? 0,
        day: day?.getBoundingClientRect().width ?? 0,
      };
    });
    expect(sizes.calendar).toBeGreaterThan(sizes.field - 2);
    // Seven columns share the row: no fixed 39px cells left in the middle.
    expect(sizes.day).toBeGreaterThan(sizes.calendar / 7 - 3);
  });
}

test('months ahead: four months forward, start on the last day of a month, end follows', async ({ page }) => {
  const bookId = freeBookId();
  await page.setViewportSize({ width: 1280, height: 900 });
  await openLoanPopup(page, bookId);
  const calendars = page.locator('.loan-request-popup .flatpickr-calendar');

  await clickIn(page, '#swal-date-start-display');
  const first = await shownMonth(page);
  for (let i = 0; i < 4; i++) {
    await clickIn(page, NEXT, 0);
  }
  expect(await shownMonth(page)).not.toBe(first);

  const count = await calendars.nth(0).locator('.flatpickr-day:not(.prevMonthDay):not(.nextMonthDay):not(.flatpickr-disabled)').count();
  expect(count, 'days to pick four months ahead').toBeGreaterThan(0);
  await page.evaluate(() => {
    const cal = document.querySelectorAll('.loan-request-popup .flatpickr-calendar')[0];
    const days = cal.querySelectorAll('.flatpickr-day:not(.prevMonthDay):not(.nextMonthDay):not(.flatpickr-disabled)');
    days[days.length - 1].click();
  });

  const start = await page.inputValue('#swal-date-start');
  const lastOfMonth = new Date(Date.UTC(Number(start.slice(0, 4)), Number(start.slice(5, 7)), 0)).getUTCDate();
  expect(Number(start.slice(8, 10)), `${start} is the last day of its month`).toBe(lastOfMonth);
  const end = await page.inputValue('#swal-date-end');
  expect(end > start, `return date ${end} after ${start}`).toBe(true);

  // The return calendar opens on the next month and moves on from there.
  await clickIn(page, '#swal-date-end-display');
  const endMonth = await shownMonth(page);
  await clickIn(page, NEXT, 1);
  expect(await shownMonth(page)).not.toBe(endMonth);
});

// ── Scheduled loan: the pickup days never outlast the loan ──────────────────
// The pickup window runs from the start date for the configured days, capped at
// the end of the loan. A two-day loan with seven pickup days must say two days,
// in the popup and in the alert after a request without JavaScript.
test.describe('scheduled loan pickup days', () => {
  const plusDays = (iso, n) => {
    const d = new Date(`${iso}T00:00:00Z`);
    d.setUTCDate(d.getUTCDate() + n);
    return d.toISOString().slice(0, 10);
  };
  const today = () => db('SELECT CURDATE()');
  const DAYS_TWO = /\b2\b\D{0,20}(giorni|days|Tage|jours|dage)/;
  let priorPickupDays = null;
  let loanId = '';

  test.beforeAll(() => {
    priorPickupDays = db("SELECT COALESCE(MAX(setting_value), 'NULL') FROM system_settings WHERE category = 'loans' AND setting_key = 'pickup_expiry_days'");
    db("INSERT INTO system_settings (category, setting_key, setting_value) VALUES ('loans', 'pickup_expiry_days', '7') ON DUPLICATE KEY UPDATE setting_value = '7'");
  });
  test.afterAll(() => {
    if (loanId) db(`DELETE FROM prestiti WHERE id = ${Number(loanId)}`);
    if (priorPickupDays === 'NULL') db("DELETE FROM system_settings WHERE category = 'loans' AND setting_key = 'pickup_expiry_days'");
    else if (priorPickupDays !== null) db(`UPDATE system_settings SET setting_value = '${priorPickupDays.replace(/'/g, "''")}' WHERE category = 'loans' AND setting_key = 'pickup_expiry_days'`);
  });

  test('popup: a two-day loan names two pickup days', async ({ page }) => {
    const bookId = freeBookId();
    expect(bookId, 'a free book is needed').not.toBe('');
    await page.setViewportSize({ width: 1280, height: 900 });
    await openLoanPopup(page, bookId);
    // The answer of an auto-approved scheduled request; the popup builds its
    // message from the dates the reader picked.
    await page.route('**/reservation', (route) => (route.request().method() === 'POST'
      ? route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, auto_approved: true, loan_state: 'prenotato' }) })
      : route.continue()));
    const start = plusDays(today(), 30);
    const end = plusDays(start, 2);
    await page.evaluate(([s, e]) => {
      document.getElementById('swal-date-start').value = s;
      document.getElementById('swal-date-end').value = e;
    }, [start, end]);
    await page.locator('.swal2-confirm').click();
    const message = page.locator('.swal2-html-container small');
    await expect(message).toBeVisible();
    await expect(message).toHaveText(DAYS_TWO);
    await expect(message).not.toHaveText(/\b7\b\D{0,20}(giorni|days|Tage|jours|dage)/);
    await page.locator('.swal2-confirm').click();
    await expect(page.locator('.swal2-popup')).toBeHidden();
  });

  test('alert after a request without JavaScript: two pickup days', async ({ page }) => {
    const bookId = freeBookId();
    const userId = db(`SELECT id FROM utenti WHERE email = '${ADMIN_EMAIL.replace(/'/g, "''")}'`);
    const start = plusDays(today(), 30);
    db(`INSERT INTO prestiti (libro_id, utente_id, data_prestito, data_scadenza, stato, attivo)
        VALUES (${Number(bookId)}, ${Number(userId)}, '${start}', '${plusDays(start, 2)}', 'prenotato', 0)`);
    loanId = db(`SELECT MAX(id) FROM prestiti WHERE libro_id = ${Number(bookId)} AND utente_id = ${Number(userId)} AND data_prestito = '${start}'`);
    await openLoanPopup(page, bookId);
    await page.goto(`${BASE}/libro/${bookId}?loan_request_success=1&auto_approved=1&loan_state=prenotato&loan_id=${loanId}`);
    const alert = page.locator('#book-alerts .alert-success');
    await expect(alert).toBeVisible();
    await expect(alert).toHaveText(DAYS_TWO);
    // A malformed id is not cast to a number: the alert falls back to the setting.
    await page.goto(`${BASE}/libro/${bookId}?loan_request_success=1&auto_approved=1&loan_state=prenotato&loan_id=${loanId}abc`);
    await expect(page.locator('#book-alerts .alert-success')).toHaveText(/\b7\b\D{0,20}(giorni|days|Tage|jours|dage)/);
  });
});
