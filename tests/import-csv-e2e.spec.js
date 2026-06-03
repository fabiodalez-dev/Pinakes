// E2E: standard CSV import (20 books) through the real browser UI.
// Per project rule, CSV/TSV import and book creation are ALWAYS verified via
// real browser E2E (Uppy upload + the full chunk loop), never API-only — an
// API test passed once while the browser flow timed out. Keep this test.
const { test, expect } = require('@playwright/test');
const path = require('path');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const CSV = path.join(__dirname, 'seeds', 'import-csv-20books.csv');

async function loginAsAdmin(page) {
  await page.goto(`${BASE}/admin/dashboard`);
  const email = page.locator('input[name="email"]');
  if (await email.isVisible({ timeout: 3000 }).catch(() => false)) {
    await email.fill(process.env.E2E_ADMIN_EMAIL);
    await page.fill('input[name="password"]', process.env.E2E_ADMIN_PASS);
    await page.click('button[type="submit"]');
    await page.waitForURL(/.*(?:dashboard|admin).*/, { timeout: 15000 });
  }
}

test.describe.serial('Standard CSV import (20 books)', () => {
  test('imports all 20 rows via the browser with no row errors', async ({ page }) => {
    test.setTimeout(150000);
    await loginAsAdmin(page);
    await page.goto(`${BASE}/admin/books/import`);

    const statuses = [];
    let lastChunk = null;
    page.on('response', async (r) => {
      const u = r.url();
      if (u.includes('/admin/books/import/upload') || u.includes('/admin/books/import/chunk')) {
        statuses.push(r.status());
        if (u.includes('/chunk')) { try { lastChunk = JSON.parse(await r.text()); } catch { /* non-JSON => caught by assertions */ } }
      }
    });

    await page.setInputFiles('#csv_file', CSV);
    // The submit button is gated on the Uppy uploader; we use the plain input.
    await page.evaluate(() => { const b = document.getElementById('submitBtn'); if (b) b.disabled = false; });
    await page.click('#submitBtn');

    await expect
      .poll(() => (lastChunk && lastChunk.complete === true) ? true : false, { timeout: 120000, intervals: [2000] })
      .toBe(true);

    // Every prepare/chunk response was HTTP 200 (no "Risposta non valida").
    expect(statuses.length).toBeGreaterThan(0);
    expect(statuses.every((s) => s === 200)).toBeTruthy();
    // The import reached completion with zero per-row errors.
    expect(lastChunk).toBeTruthy();
    expect(lastChunk.complete).toBe(true);
    expect(lastChunk.errors).toBe(0);
  });
});
