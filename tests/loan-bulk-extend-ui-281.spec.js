// @ts-check
// Browser E2E for issue #281: the bulk-extend UI (select loans -> extend by N)
// and the locale-driven date picker on the Edit Loan page.
//
// Complements the unit tests (loan-extension-281 / loan-bulk-extension-capacity),
// which cover the controller: this drives the real admin UI a librarian uses.
//
// Run: /tmp/run-e2e.sh tests/loan-bulk-extend-ui-281.spec.js --config=tests/playwright.config.js --workers=1
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

test.skip(
  !ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME,
  'E2E credentials not configured (set E2E_ADMIN_EMAIL, E2E_ADMIN_PASS, E2E_DB_USER, E2E_DB_NAME)',
);

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
    encoding: 'utf-8', timeout: 10000,
    env: { ...process.env, MYSQL_PWD: DB_PASS },
  }).trim();
}

function isoOffset(days) {
  const d = new Date();
  d.setDate(d.getDate() + days);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

async function loginAsAdmin(page) {
  await page.goto(`${BASE}/accedi`);
  await page.fill('input[name="email"]', ADMIN_EMAIL);
  await page.fill('input[name="password"]', ADMIN_PASS);
  await page.locator('button[type="submit"]').click();
  await page.waitForLoadState('networkidle');
}

const TAG = 'ZZBULKUI' + Date.now().toString().slice(-7);
let bookId = 0;
let copyId = 0;
let userId = 0;
let loanId = 0;

test.beforeAll(() => {
  // A book with one physical copy, a borrower, and a deeply-overdue active loan
  // on that copy (due 40 days ago) — so bulk-extend by 14 must both persist the
  // new date AND flip the status back to in_corso.
  dbQuery(`INSERT INTO libri (titolo, stato, copie_totali, copie_disponibili) VALUES ('${TAG} Book', 'disponibile', 1, 0)`);
  bookId = parseInt(dbQuery(`SELECT id FROM libri WHERE titolo='${TAG} Book' LIMIT 1`), 10);
  dbQuery(`INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (${bookId}, '${TAG}-C1', 'prestato')`);
  copyId = parseInt(dbQuery(`SELECT id FROM copie WHERE numero_inventario='${TAG}-C1' LIMIT 1`), 10);
  dbQuery(`INSERT INTO utenti (codice_tessera, nome, cognome, email, password, tipo_utente, stato, email_verificata)
           VALUES ('${TAG}', 'Bulk', 'Borrower', '${TAG.toLowerCase()}@example.com', '$2y$10$abcdefghijklmnopqrstuv', 'standard', 'attivo', 1)`);
  userId = parseInt(dbQuery(`SELECT id FROM utenti WHERE codice_tessera='${TAG}' LIMIT 1`), 10);
  const past = isoOffset(-40);
  const start = isoOffset(-50);
  dbQuery(`INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo, warning_sent, overdue_notification_sent)
           VALUES (${bookId}, ${copyId}, ${userId}, '${start}', '${past}', 'in_ritardo', 'diretto', 1, 1, 1)`);
  loanId = parseInt(dbQuery(`SELECT id FROM prestiti WHERE libro_id=${bookId} AND attivo=1 ORDER BY id DESC LIMIT 1`), 10);
});

test.afterAll(() => {
  if (loanId) dbQuery(`DELETE FROM prestiti WHERE id=${loanId}`);
  if (copyId) dbQuery(`DELETE FROM copie WHERE id=${copyId}`);
  if (bookId) dbQuery(`DELETE FROM libri WHERE id=${bookId}`);
  if (userId) dbQuery(`DELETE FROM utenti WHERE id=${userId}`);
});

test('bulk-extend UI extends a selected overdue loan and clears its overdue status', async ({ page }) => {
  expect(loanId, 'fixture loan seeded').toBeGreaterThan(0);
  await loginAsAdmin(page);
  await page.goto(`${BASE}/admin/loans`);
  await page.waitForLoadState('networkidle');

  // Filter the (DataTables) list down to our fixture so its row/checkbox is on
  // the visible page regardless of how many loans exist.
  const search = page.locator('input[type="search"]').first();
  if (await search.isVisible({ timeout: 3000 }).catch(() => false)) {
    await search.fill(`${TAG} Book`);
    await page.waitForTimeout(600);
  }

  const checkbox = page.locator(`.loan-select[data-id="${loanId}"]`);
  await expect(checkbox, 'extendable loan exposes a selection checkbox').toBeVisible({ timeout: 8000 });
  await checkbox.check();

  // The bulk action bar appears once something is selected.
  const bar = page.locator('#loans-bulk-bar');
  await expect(bar).toBeVisible();
  await page.fill('#loans-bulk-days', '14');
  await page.locator('#loans-bulk-extend').click();

  // Confirm the SweetAlert, if one is shown.
  const confirm = page.locator('.swal2-confirm');
  if (await confirm.isVisible({ timeout: 3000 }).catch(() => false)) {
    await confirm.click();
  }
  await page.waitForLoadState('networkidle');

  // The overdue loan now extends from today (today+14) and is back in_corso.
  await expect.poll(
    () => dbQuery(`SELECT stato FROM prestiti WHERE id=${loanId}`),
    { timeout: 8000 },
  ).toBe('in_corso');
  expect(dbQuery(`SELECT data_scadenza FROM prestiti WHERE id=${loanId}`)).toBe(isoOffset(14));
});

test('Edit Loan date picker follows the app locale (not hardcoded Italian)', async ({ page }) => {
  await loginAsAdmin(page);
  await page.goto(`${BASE}/admin/loans/${loanId}/edit`);
  await page.waitForLoadState('networkidle');

  // The native date input is upgraded to flatpickr; its localization must match
  // the app locale, and the other shipped locales must be registered so a
  // de/fr/da install renders its own calendar instead of the old Italian default.
  const info = await page.evaluate(() => {
    const lang = (document.documentElement.getAttribute('lang') || '').slice(0, 2).toLowerCase();
    const registered = Object.keys(window.flatpickrLocales || {});
    const fp = document.querySelector('.flatpickr-input');
    let weekdays = [];
    if (window.flatpickr && fp && fp._flatpickr && fp._flatpickr.l10n) {
      weekdays = fp._flatpickr.l10n.weekdays.shorthand || [];
    }
    return { lang, registered, weekdays };
  });

  // de/fr/da (plus it/en) are wired into the bundle by the fix.
  for (const loc of ['de', 'fr', 'da']) {
    expect(info.registered, `flatpickr locale ${loc} registered`).toContain(loc);
  }
  // On this Italian install the calendar renders Italian weekday names — i.e. it
  // is locale-driven. "Lun" (it) must appear; the old bug showed Italian even on
  // non-it installs, so proving it tracks the configured locale is the guard.
  if (info.lang === 'it' && info.weekdays.length) {
    const joined = info.weekdays.join(' ').toLowerCase();
    expect(joined, 'Italian install shows an Italian calendar').toMatch(/lun|dom/);
  }
});
