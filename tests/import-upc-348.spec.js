// E2E (#348): CSV and TSV import must accept a 12-digit UPC-A barcode and store
// it as the canonical 13-digit GTIN (a leading zero, which preserves the check
// digit). Both imports share one code path (delimiter auto-detected), so we
// exercise both a comma/semicolon CSV and a tab-delimited "TSV".
//
// Per project rule, CSV/TSV import is ALWAYS verified through the real browser
// upload flow (never API-only) and the stored value is read back from the DB.
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
  !ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_PASS || !DB_NAME,
  'E2E credentials not configured',
);

const sqlEscape = (s) => String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'");

function dbQuery(sql) {
  const args = ['-u', DB_USER, `-p${DB_PASS}`, DB_NAME, '-N', '-B', '-e', sql];
  if (DB_HOST) {
    args.splice(3, 0, '-h', DB_HOST);
    if (DB_PORT) args.splice(5, 0, '-P', DB_PORT);
  } else if (DB_SOCKET) {
    args.splice(3, 0, '-S', DB_SOCKET);
  }
  return execFileSync('mysql', args, { encoding: 'utf-8', timeout: 10000 }).trim();
}

// Per-run token so the imported rows are unique and findable.
const RUN_ID = Date.now().toString(36);
const CSV_TITLE = `UPC CSV ${RUN_ID}`;
const TSV_TITLE = `UPC TSV ${RUN_ID}`;
const CSV_UPC = '036000291452'; // valid UPC-A → GTIN 0036000291452
const TSV_UPC = '012345678905'; // valid UPC-A → GTIN 0012345678905
const CSV_GTIN = '0036000291452';
const TSV_GTIN = '0012345678905';

function cleanup() {
  // libri.ean is UNIQUE: remove any leftover test rows so re-runs never hit a
  // duplicate-ean. These are test-only rows we create — hard delete is fine.
  dbQuery(
    "DELETE FROM libri WHERE ean IN ('" + CSV_GTIN + "','" + TSV_GTIN + "')"
    + " OR titolo LIKE 'UPC CSV %' OR titolo LIKE 'UPC TSV %'",
  );
}

async function loginAsAdmin(page) {
  await page.goto(`${BASE}/admin/dashboard`);
  const email = page.locator('input[name="email"]');
  if (await email.isVisible({ timeout: 3000 }).catch(() => false)) {
    await email.fill(ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await page.click('button[type="submit"]');
    await page.waitForURL(/.*(?:dashboard|admin).*/, { timeout: 15000 });
  }
}

/**
 * Upload one in-memory import file through the real browser flow and wait for
 * the chunked import to report completion. Returns the collected HTTP statuses
 * and the final /chunk payload.
 */
async function runImport(page, { name, mimeType, content }) {
  await page.goto(`${BASE}/admin/books/import`);

  const statuses = [];
  let lastChunk = null;
  page.on('response', async (r) => {
    const u = r.url();
    if (u.includes('/admin/books/import/upload') || u.includes('/admin/books/import/chunk')) {
      statuses.push(r.status());
      if (u.includes('/chunk')) {
        try { lastChunk = JSON.parse(await r.text()); } catch { /* non-JSON => assertions catch it */ }
      }
    }
  });

  await page.setInputFiles('#csv_file', { name, mimeType, buffer: Buffer.from(content, 'utf-8') });
  // The submit button is gated on the Uppy uploader; drive the plain input.
  await page.evaluate(() => { const b = document.getElementById('submitBtn'); if (b) b.disabled = false; });
  await page.click('#submitBtn');

  await expect
    .poll(() => (lastChunk && lastChunk.complete === true) ? true : false, { timeout: 120000, intervals: [2000] })
    .toBe(true);

  return { statuses, lastChunk };
}

test.describe.serial('UPC barcode import (#348)', () => {
  test.beforeAll(() => cleanup());
  test.afterAll(() => cleanup());

  test('CSV: a 12-digit UPC-A is stored as its 13-digit GTIN', async ({ page }) => {
    test.setTimeout(150000);
    await loginAsAdmin(page);

    const content = `titolo;ean\n${CSV_TITLE};${CSV_UPC}\n`;
    const { statuses, lastChunk } = await runImport(page, {
      name: 'upc-test.csv',
      mimeType: 'text/csv',
      content,
    });

    expect(statuses.length).toBeGreaterThan(0);
    expect(statuses.every((s) => s === 200)).toBeTruthy();
    expect(lastChunk).toBeTruthy();
    expect(lastChunk.complete).toBe(true);
    expect(lastChunk.errors).toBe(0);

    const storedEan = dbQuery(
      "SELECT ean FROM libri WHERE titolo='" + sqlEscape(CSV_TITLE) + "' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1",
    );
    expect(storedEan).toBe(CSV_GTIN);
  });

  test('TSV: a tab-delimited 12-digit UPC-A is stored as its 13-digit GTIN', async ({ page }) => {
    test.setTimeout(150000);
    await loginAsAdmin(page);

    // The app accepts only a .csv-named file (str_ends_with('.csv')) and
    // auto-detects the delimiter (";", "," or TAB), so its "TSV" support is
    // tab-delimited content inside a .csv file — not the .tsv extension.
    const content = `titolo\tean\n${TSV_TITLE}\t${TSV_UPC}\n`;
    const { statuses, lastChunk } = await runImport(page, {
      name: 'upc-test-tab.csv',
      mimeType: 'text/csv',
      content,
    });

    expect(statuses.length).toBeGreaterThan(0);
    expect(statuses.every((s) => s === 200)).toBeTruthy();
    expect(lastChunk).toBeTruthy();
    expect(lastChunk.complete).toBe(true);
    expect(lastChunk.errors).toBe(0);

    const storedEan = dbQuery(
      "SELECT ean FROM libri WHERE titolo='" + sqlEscape(TSV_TITLE) + "' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1",
    );
    expect(storedEan).toBe(TSV_GTIN);
  });
});
