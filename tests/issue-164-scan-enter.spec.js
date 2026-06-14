// @ts-check
// Issue #164 — barcode-scanner Enter must trigger the ISBN import, never submit
// the surrounding form (which would trip the title's required-field validation).
// Covered conditions: (1) Enter with a code triggers the import request and does
// NOT submit; (2) Enter on an empty scan field shows the "ISBN missing" warning
// and does NOT submit either.
const { test, expect } = require('@playwright/test');
test.describe.configure({ mode: 'serial' });
const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';
// Credentials come from the E2E wrapper (/tmp/run-e2e.sh / ci-e2e.yml), which
// exports the E2E_* env this reads — that IS the project's bootstrap.
test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'E2E admin credentials not configured');

test.describe('#164 — scanner Enter on the ISBN field', () => {
  /** @type {import('@playwright/test').Page} */
  let page;
  test.beforeAll(async ({ browser }) => {
    page = await browser.newPage();
    await page.goto(`${BASE}/accedi`);
    await page.fill('input[name="email"]', ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await page.locator('button[type="submit"]').click();
    await page.waitForURL((u) => !u.toString().includes('/accedi'), { timeout: 15000 });
  });
  test.afterAll(async () => { await page?.close(); });

  test.beforeEach(async () => {
    await page.goto(`${BASE}/admin/books/create`);
    await expect(page.locator('#importIsbn')).toBeVisible({ timeout: 10000 });
  });

  test('1. Enter with a code triggers the import request, not a form submit', async () => {
    const urlBefore = page.url();
    await page.fill('#importIsbn', '9788842935780');
    const [req] = await Promise.all([
      page.waitForRequest((r) => r.url().includes('/api/scrape/isbn'), { timeout: 8000 }),
      page.locator('#importIsbn').press('Enter'),
    ]);
    expect(req).toBeTruthy();            // import started (deterministic signal)
    expect(page.url()).toBe(urlBefore);  // the form was NOT submitted
  });

  test('2. Enter on an empty scan field warns and does not submit', async () => {
    const urlBefore = page.url();
    await page.fill('#importIsbn', '');
    // No scrape request must fire; instead the "ISBN missing" SweetAlert shows.
    let scrapeFired = false;
    const onReq = (r) => { if (r.url().includes('/api/scrape/isbn')) scrapeFired = true; };
    page.on('request', onReq);
    await page.locator('#importIsbn').press('Enter');
    await expect(page.locator('.swal2-popup')).toBeVisible({ timeout: 5000 });
    // Close the warning explicitly (project E2E convention) and wait for it to
    // disappear. Detaching the listener only after the popup is fully dismissed
    // gives any erroneously-late scrape request a deterministic window to
    // surface before we assert it never fired.
    await page.locator('.swal2-confirm').click();
    await expect(page.locator('.swal2-popup')).toBeHidden({ timeout: 5000 });
    page.off('request', onReq);
    expect(scrapeFired).toBe(false);     // empty code → no import call
    expect(page.url()).toBe(urlBefore);  // and still no form submit
  });
});
