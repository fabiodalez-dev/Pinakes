// @ts-check
// Issue #164: scanning an ISBN (scanner appends Enter/CR) into the import field
// must NOT submit the form (which trips required-field validation on the title);
// it must trigger the "Import Data" action instead.
const { test, expect } = require('@playwright/test');
const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';
test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'admin creds not configured');

test('#164: Enter in the ISBN scan field triggers import, not a form submit', async ({ page }) => {
  await page.goto(`${BASE}/accedi`);
  await page.fill('input[name="email"]', ADMIN_EMAIL);
  await page.fill('input[name="password"]', ADMIN_PASS);
  await page.locator('button[type="submit"]').click();
  await page.waitForURL((u) => !u.toString().includes('/accedi'), { timeout: 15000 });

  await page.goto(`${BASE}/admin/books/create`);
  await expect(page.locator('#importIsbn')).toBeVisible({ timeout: 10000 });
  const urlBefore = page.url();

  // Simulate a scanner: type the code then send the trailing Enter (CR).
  await page.fill('#importIsbn', '9788842935780');
  await page.locator('#importIsbn').press('Enter');

  // The import must start: the button enters its loading state (spinner/disabled)
  // OR the source panel/title gets populated — and crucially the page must NOT
  // have navigated (no form submit / no required-field validation).
  await page.waitForTimeout(1200);
  expect(page.url()).toBe(urlBefore); // form was NOT submitted
  // title must NOT have a native validation bubble blocking us; confirm the
  // import path ran by checking the button reacted or fields/source appeared.
  const importStarted = await page.evaluate(() => {
    const btn = document.getElementById('btnImportIsbn');
    const src = document.getElementById('scrapeSourceInfo');
    const titolo = /** @type {HTMLInputElement} */(document.getElementById('titolo'));
    return (btn && (btn.disabled || /spin|Importazione|Aggiornamento/i.test(btn.innerHTML)))
        || (src && !src.classList.contains('hidden'))
        || (titolo && titolo.value.trim().length > 0);
  });
  expect(importStarted).toBeTruthy();
});
