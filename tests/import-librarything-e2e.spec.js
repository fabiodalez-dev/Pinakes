// E2E: LibraryThing TSV import (20 books, real 54-column export) through the
// real browser UI. Per project rule, import/book-creation is verified via real
// browser E2E (Uppy + full chunk loop), never API-only. Keep this test.
const { test, expect } = require('@playwright/test');
const path = require('path');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const TSV = path.join(__dirname, 'seeds', 'import-librarything-20books.tsv');

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

test.describe.serial('LibraryThing TSV import (20 books)', () => {
  test('imports all 20 rows via the browser with no row errors', async ({ page }) => {
    test.setTimeout(180000);
    await loginAsAdmin(page);
    await page.goto(`${BASE}/admin/libri/import/librarything`);

    const statuses = [];
    let lastChunk = null;
    page.on('response', async (r) => {
      const u = r.url();
      if (u.includes('/import/librarything/prepare') || u.includes('/import/librarything/chunk')) {
        statuses.push(r.status());
        if (u.includes('/chunk')) { try { lastChunk = JSON.parse(await r.text()); } catch { /* non-JSON => assertions fail */ } }
      }
    });

    await page.setInputFiles('#tsv_file', TSV);
    // No scraping here so the suite stays fast; scraping is covered manually.
    await page.evaluate(() => { const b = document.getElementById('submitBtn'); if (b) b.disabled = false; });
    await page.click('#submitBtn');

    await expect
      .poll(() => (lastChunk && lastChunk.complete === true) ? true : false, { timeout: 150000, intervals: [2000] })
      .toBe(true);

    expect(statuses.length).toBeGreaterThan(0);
    expect(statuses.every((s) => s === 200)).toBeTruthy();
    expect(lastChunk).toBeTruthy();
    expect(lastChunk.complete).toBe(true);
    expect(lastChunk.total).toBe(20);
    expect(lastChunk.current).toBe(20);
    expect(lastChunk.errors).toBe(0);
  });
});
