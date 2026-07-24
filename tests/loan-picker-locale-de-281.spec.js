// @ts-check
// Real German-locale verification for issue #281: the Edit Loan date picker
// must follow the CONFIGURED locale, not a hardcoded Italian default. This
// switches the whole install to de_DE (languages default + system setting),
// opens the Edit Loan page, and asserts the flatpickr calendar renders GERMAN
// weekday names — then restores it_IT. Destructive-looking but fully reverted.
//
// Run: /tmp/run-e2e.sh tests/loan-picker-locale-de-281.spec.js --config=tests/playwright.config.js --workers=1
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
  'E2E credentials not configured',
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

const TAG = 'ZZDEPICK' + Date.now().toString().slice(-7);
let bookId = 0;
let copyId = 0;
let userId = 0;
let loanId = 0;
let priorDefault = 'it_IT';
let priorUserLocale = 'it_IT';

test.beforeAll(() => {
  // Remember the current default so we can restore it, then switch to German.
  priorDefault = dbQuery("SELECT code FROM languages WHERE is_default=1 LIMIT 1") || 'it_IT';
  dbQuery("UPDATE languages SET is_default=0");
  dbQuery("UPDATE languages SET is_default=1, is_active=1 WHERE code='de_DE'");
  dbQuery("UPDATE system_settings SET setting_value='de_DE' WHERE category='app' AND setting_key='locale'");
  // A logged-in user's own locale overrides the system default (the session
  // sources it from utenti.locale), so the admin must be German too — this
  // mirrors a real German librarian.
  priorUserLocale = dbQuery(`SELECT locale FROM utenti WHERE email='${ADMIN_EMAIL}' LIMIT 1`) || 'it_IT';
  dbQuery(`UPDATE utenti SET locale='de_DE' WHERE email='${ADMIN_EMAIL}'`);

  dbQuery(`INSERT INTO libri (titolo, stato, copie_totali, copie_disponibili) VALUES ('${TAG} Book','disponibile',1,0)`);
  bookId = parseInt(dbQuery(`SELECT id FROM libri WHERE titolo='${TAG} Book' LIMIT 1`), 10);
  dbQuery(`INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (${bookId},'${TAG}-C1','prestato')`);
  copyId = parseInt(dbQuery(`SELECT id FROM copie WHERE numero_inventario='${TAG}-C1' LIMIT 1`), 10);
  dbQuery(`INSERT INTO utenti (codice_tessera,nome,cognome,email,password,tipo_utente,stato,email_verificata)
           VALUES ('${TAG}','De','Pick','${TAG.toLowerCase()}@example.com','$2y$10$abcdefghijklmnopqrstuv','standard','attivo',1)`);
  userId = parseInt(dbQuery(`SELECT id FROM utenti WHERE codice_tessera='${TAG}' LIMIT 1`), 10);
  dbQuery(`INSERT INTO prestiti (libro_id,copia_id,utente_id,data_prestito,data_scadenza,stato,origine,attivo)
           VALUES (${bookId},${copyId},${userId},DATE_SUB(CURDATE(),INTERVAL 5 DAY),DATE_ADD(CURDATE(),INTERVAL 5 DAY),'in_corso','diretto',1)`);
  loanId = parseInt(dbQuery(`SELECT id FROM prestiti WHERE libro_id=${bookId} AND attivo=1 ORDER BY id DESC LIMIT 1`), 10);
});

test.afterAll(() => {
  // Restore the original locale FIRST, then remove fixtures.
  dbQuery("UPDATE languages SET is_default=0");
  dbQuery(`UPDATE languages SET is_default=1 WHERE code='${priorDefault.replace(/'/g, '')}'`);
  dbQuery(`UPDATE system_settings SET setting_value='${priorDefault.replace(/'/g, '')}' WHERE category='app' AND setting_key='locale'`);
  dbQuery(`UPDATE utenti SET locale='${priorUserLocale.replace(/'/g, '')}' WHERE email='${ADMIN_EMAIL}'`);
  if (loanId) dbQuery(`DELETE FROM prestiti WHERE id=${loanId}`);
  if (copyId) dbQuery(`DELETE FROM copie WHERE id=${copyId}`);
  if (bookId) dbQuery(`DELETE FROM libri WHERE id=${bookId}`);
  if (userId) dbQuery(`DELETE FROM utenti WHERE id=${userId}`);
});

test('Edit Loan calendar renders in German when the install locale is de_DE', async ({ page }) => {
  expect(loanId).toBeGreaterThan(0);

  // Login — /accedi redirects to the localized /anmelden in German; Playwright
  // follows it, and the fields keep the same names.
  await page.goto(`${BASE}/accedi`);
  await page.fill('input[name="email"]', ADMIN_EMAIL);
  await page.fill('input[name="password"]', ADMIN_PASS);
  await page.locator('button[type="submit"]').click();
  await page.waitForLoadState('networkidle');

  await page.goto(`${BASE}/admin/loans/${loanId}/edit`);
  await page.waitForLoadState('networkidle');

  // Sanity: the whole page is German now.
  expect(await page.getAttribute('html', 'lang')).toBe('de');

  // Read the flatpickr localization actually bound to the due-date field.
  const weekdays = await page.evaluate(() => {
    const input = document.querySelector('input[name="data_scadenza"]');
    const fp = input && input._flatpickr;
    if (fp && fp.l10n && fp.l10n.weekdays) {
      return fp.l10n.weekdays.shorthand;
    }
    return [];
  });

  expect(weekdays.length, 'flatpickr is initialized on the due-date field').toBeGreaterThan(0);
  const joined = weekdays.join(' ');
  // German shorthand weekdays include Mi (Mittwoch) and Do (Donnerstag);
  // the Italian ones would be Mer/Gio. This proves the picker follows the
  // configured locale rather than the old hardcoded Italian default.
  expect(joined, `German weekdays, got: ${joined}`).toContain('Mi');
  expect(joined).toContain('Do');
  expect(joined).not.toContain('Mer');
  expect(joined).not.toContain('Gio');
});
