// @ts-check
/**
 * SweetAlert coverage — series/collane cluster.
 *
 * Proves each SweetAlert in app/Views/collane/dettaglio.php is BOTH shown
 * AND functional:
 *   - remove-book  (data-swal-confirm, kind=action)  → POST /admin/series/remove-book
 *   - merge        (data-swal-confirm, kind=action)  → POST /admin/series/merge
 *
 * Run with:
 *   /tmp/run-e2e.sh tests/swal-series-collane.spec.js --config=tests/playwright.config.js --workers=1
 */
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';
const DB_HOST = process.env.E2E_DB_HOST || '';
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS ?? '';
const DB_NAME = process.env.E2E_DB_NAME || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';

const HAS_E2E_ENV = Boolean(ADMIN_EMAIL && ADMIN_PASS && DB_USER && DB_NAME && (DB_HOST || DB_SOCKET));
test.skip(!HAS_E2E_ENV, 'Missing E2E env vars for series/collane SweetAlert tests');

function mysqlArgs(sql = '', batch = false) {
  const args = [];
  if (DB_HOST) args.push('-h', DB_HOST);
  if (DB_SOCKET) args.push('-S', DB_SOCKET);
  args.push('-u', DB_USER);
  if (DB_PASS !== '') args.push(`-p${DB_PASS}`);
  args.push(DB_NAME);
  if (batch) args.push('-N', '-B');
  if (sql !== '') args.push('-e', sql);
  return args;
}
function dbQuery(sql) {
  return execFileSync('mysql', mysqlArgs(sql, true), { encoding: 'utf-8', timeout: 10000 }).trim();
}
function dbExec(sql, timeout = 10000) {
  execFileSync('mysql', mysqlArgs(sql), { encoding: 'utf-8', timeout });
}
function escapeSql(value) {
  return String(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
}

async function loginAsAdmin(page) {
  await page.goto(`${BASE}/admin/dashboard`);
  const emailField = page.locator('input[name="email"]');
  if (await emailField.isVisible({ timeout: 3000 }).catch(() => false)) {
    await emailField.fill(ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await Promise.all([
      page.waitForURL(/\/admin\//, { timeout: 15000 }),
      page.click('button[type="submit"]'),
    ]);
  }
}

const TAG = 'E2E_SWAL_SERIES_' + Date.now();
const SRC = `${TAG} Source`;
const TGT = `${TAG} Target`;
const BOOK_A = `${TAG} Volume A`;
const BOOK_B = `${TAG} Volume B`;

test.describe.serial('SweetAlert — series/collane (collane/dettaglio.php)', () => {
  /** @type {import('@playwright/test').BrowserContext} */
  let context;
  /** @type {import('@playwright/test').Page} */
  let page;

  function cleanup() {
    dbExec(`DELETE FROM libri WHERE titolo LIKE '${escapeSql(TAG)}%'`);
    dbExec(`DELETE FROM collane WHERE nome LIKE '${escapeSql(TAG)}%'`);
  }

  test.beforeAll(async ({ browser }) => {
    context = await browser.newContext();
    page = await context.newPage();
    await loginAsAdmin(page);

    cleanup();
    // Two books in the SRC series (legacy libri.collana column drives the
    // detail-page grouping and the remove/merge forms).
    dbExec(`INSERT INTO libri (titolo, collana, numero_serie, copie_totali, copie_disponibili, created_at, updated_at)
            VALUES ('${escapeSql(BOOK_A)}', '${escapeSql(SRC)}', '1', 1, 1, NOW(), NOW())`);
    dbExec(`INSERT INTO libri (titolo, collana, numero_serie, copie_totali, copie_disponibili, created_at, updated_at)
            VALUES ('${escapeSql(BOOK_B)}', '${escapeSql(SRC)}', '2', 1, 1, NOW(), NOW())`);
  });

  test.afterAll(async () => {
    try { cleanup(); } catch { /* ignore */ }
    await context?.close();
  });

  test('remove-book: SweetAlert shown + book detached from the series', async () => {
    const bookAId = dbQuery(`SELECT id FROM libri WHERE titolo='${escapeSql(BOOK_A)}' AND deleted_at IS NULL`);
    expect(parseInt(bookAId, 10)).toBeGreaterThan(0);

    await page.goto(`${BASE}/admin/series/detail?nome=${encodeURIComponent(SRC)}`);

    const form = page.locator(`form[action$="/admin/series/remove-book"]:has(input[name="book_id"][value="${bookAId}"])`);
    await expect(form).toBeVisible({ timeout: 5000 });
    await form.locator('button[type="submit"]').click();

    // (a) SweetAlert is shown
    await page.waitForSelector('.swal2-popup', { timeout: 8000 });
    await expect(page.locator('.swal2-popup')).toContainText('Rimuovere questo libro');

    // (b) confirm → outcome
    await page.locator('.swal2-confirm').click();
    await page.waitForURL(/\/admin\/series/, { timeout: 10000 });

    const collanaA = dbQuery(`SELECT IFNULL(collana, '') FROM libri WHERE id=${bookAId}`);
    expect(collanaA).toBe('');
    // The other volume is still attached to the source series
    const collanaB = dbQuery(`SELECT collana FROM libri WHERE titolo='${escapeSql(BOOK_B)}' AND deleted_at IS NULL`);
    expect(collanaB).toBe(SRC);
  });

  test('merge: SweetAlert shown + remaining books moved to the target series', async () => {
    await page.goto(`${BASE}/admin/series/detail?nome=${encodeURIComponent(SRC)}`);

    await page.fill('#merge-target', TGT);
    const mergeForm = page.locator('form[action$="/admin/series/merge"]');
    await expect(mergeForm).toBeVisible({ timeout: 5000 });
    await mergeForm.locator('button[type="submit"]').click();

    // (a) SweetAlert is shown
    await page.waitForSelector('.swal2-popup', { timeout: 8000 });
    await expect(page.locator('.swal2-popup')).toContainText('verranno spostati');

    // (b) confirm → outcome
    await page.locator('.swal2-confirm').click();
    await page.waitForURL(/\/admin\/series/, { timeout: 10000 });

    const inSrc = dbQuery(`SELECT COUNT(*) FROM libri WHERE collana='${escapeSql(SRC)}' AND deleted_at IS NULL`);
    expect(inSrc).toBe('0');
    const inTgt = dbQuery(`SELECT COUNT(*) FROM libri WHERE collana='${escapeSql(TGT)}' AND deleted_at IS NULL`);
    expect(parseInt(inTgt, 10)).toBeGreaterThanOrEqual(1);
  });
});
