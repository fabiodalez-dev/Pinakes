// @ts-check
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS || '';
const DB_NAME = process.env.E2E_DB_NAME || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';

function dbQuery(sql) {
  const args = ['-u', DB_USER, `-p${DB_PASS}`, DB_NAME, '-N', '-B', '-e', sql];
  if (DB_SOCKET) args.splice(3, 0, '-S', DB_SOCKET);
  return execFileSync('mysql', args, { encoding: 'utf-8', timeout: 10000 }).trim();
}

function dbExec(sql) {
  const args = ['-u', DB_USER, `-p${DB_PASS}`, DB_NAME, '-e', sql];
  if (DB_SOCKET) args.splice(3, 0, '-S', DB_SOCKET);
  execFileSync('mysql', args, { encoding: 'utf-8', timeout: 10000 });
}

// Nirvana - Nevermind (very common CD, reliable on Discogs)
const TEST_BARCODE = '0720642442524';

test.describe.serial('Discogs Import: full scraping flow', () => {
  /** @type {import('@playwright/test').Page} */
  let page;
  /** @type {import('@playwright/test').BrowserContext} */
  let context;
  let createdId = '';

  test.beforeAll(async ({ browser }) => {
    test.skip(
      !ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_PASS || !DB_NAME,
      'Missing E2E env vars'
    );
    context = await browser.newContext();
    page = await context.newPage();

    // Login
    await page.goto(`${BASE}/accedi`);
    await page.fill('input[name="email"]', ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await page.click('button[type="submit"]');
    await page.waitForURL(/\/admin\//, { timeout: 15000 });
  });

  test.afterAll(async () => {
    // Cleanup test data
    try {
      if (createdId !== '') {
        dbExec(`DELETE FROM libri WHERE id = ${Number(createdId)} AND deleted_at IS NULL`);
      }
    } catch {}
    await context?.close();
  });

  test('1. Verify Discogs plugin is active', async () => {
    const isActive = dbQuery("SELECT COUNT(*) FROM plugins WHERE name = 'discogs' AND is_active = 1");
    if (parseInt(isActive) === 0) {
      // Activate it
      await page.goto(`${BASE}/admin/plugins`);
      await page.waitForLoadState('domcontentloaded');
      // The plugin is bundled, so it should auto-register on page visit
      await page.waitForTimeout(2000);
      const isActiveNow = dbQuery("SELECT COUNT(*) FROM plugins WHERE name = 'discogs' AND is_active = 1");
      expect(parseInt(isActiveNow, 10), 'Discogs plugin could not be activated').toBeGreaterThan(0);
    }
  });

  test('2. Import CD via barcode in book form', async () => {
    await page.goto(`${BASE}/admin/libri/crea`);
    await page.waitForLoadState('domcontentloaded');

    // Find the ISBN import field and button
    const importField = page.locator('#importIsbn');
    const importBtn = page.locator('#btnImportIsbn');

    await expect(importBtn, 'Import button not visible — scraping flow unavailable').toBeVisible({ timeout: 5000 });

    // Enter barcode and trigger import
    await importField.fill(TEST_BARCODE);
    await importBtn.click();

    // Wait for scraping response (Discogs needs time + rate limits)
    // The scraping service tries multiple sources — wait up to 20s
    await page.waitForTimeout(8000);

    // Check if title was populated
    const titleField = page.locator('input[name="titolo"]');
    const titleValue = await titleField.inputValue();

    expect(titleValue.trim().length, 'Scraping did not return a title for the Discogs barcode').toBeGreaterThan(0);

    // Title should contain "Nevermind" (the album name)
    expect(titleValue.toLowerCase()).toContain('nevermind');
  });

  test('3. Verify scraped fields are populated', async () => {
    // After successful scraping, check multiple fields
    const titleValue = await page.locator('input[name="titolo"]').inputValue();
    expect(titleValue.trim().length, 'No scraped data available after Discogs import').toBeGreaterThan(0);

    // Author/Artist should be populated
    // Choices.js creates items — check if any author is selected
    const authorItems = page.locator('#autori-wrapper .choices__item--selectable, .choices__item.choices__item--selectable');
    const authorCount = await authorItems.count().catch(() => 0);

    // At minimum, title should be populated
    expect(titleValue.length).toBeGreaterThan(0);

    // Check EAN field has the barcode — and isbn13 MUST be empty.
    // Regression guard: music barcodes must never be stuffed into isbn13
    // (commit 7016608 + normalizeIsbnFields guard).
    const eanValue = await page.locator('input[name="ean"]').inputValue();
    const isbn13Value = await page.locator('input[name="isbn13"]').inputValue();
    expect(eanValue, 'Barcode must land in ean for music media').toBe(TEST_BARCODE);
    expect(isbn13Value, 'isbn13 must stay empty for non-book scraping').toBe('');
  });

  test('4. Save the imported CD', async () => {
    const titleValue = await page.locator('input[name="titolo"]').inputValue();
    expect(titleValue.trim().length, 'No scraped data to save').toBeGreaterThan(0);

    // Set copies (required field)
    const copieInput = page.locator('input[name="copie_totali"]');
    const copieVal = await copieInput.inputValue();
    if (!copieVal || copieVal === '0') {
      await copieInput.fill('1');
    }

    // Submit the form (triggers SweetAlert confirmation)
    await page.locator('button[type="submit"]').first().click();

    // Wait for and confirm SweetAlert dialog
    const swalConfirm = page.locator('.swal2-confirm');
    await expect(swalConfirm).toBeVisible({ timeout: 5000 });
    await swalConfirm.click();

    // Wait for navigation after save
    await page.waitForURL(/\/admin\/libri\/\d+/, { timeout: 15000 });
    const finalUrl = page.url();
    expect(/\/admin\/libri\/\d+/.test(finalUrl)).toBe(true);
    const createdIdMatch = finalUrl.match(/\/admin\/libri\/(\d+)/);
    expect(createdIdMatch, 'Could not resolve created record id from save redirect').not.toBeNull();
    createdId = createdIdMatch?.[1] ?? '';
  });

  test('5. Verify saved CD in database', async () => {
    expect(createdId, 'Created record id not captured during save').not.toBe('');
    const book = dbQuery(
      `SELECT titolo, COALESCE(ean, ''), COALESCE(isbn13, ''), formato FROM libri WHERE id = ${Number(createdId)} AND deleted_at IS NULL LIMIT 1`
    );
    expect(book, 'CD not found in database after import/save flow').not.toBe('');
    expect(book.toLowerCase()).toContain('nevermind');
  });

  test('6. Verify music labels on saved CD detail page', async () => {
    const bookId = createdId;
    expect(bookId, 'CD not found for label check').not.toBe('');

    await page.goto(`${BASE}/admin/libri/${bookId}`);
    await page.waitForLoadState('domcontentloaded');
    const content = await page.content();

    const tipoMedia = dbQuery(`SELECT tipo_media FROM libri WHERE id = ${bookId}`);
    expect(tipoMedia).toBe('disco');

    const hasMusicLabel = content.includes('Etichetta') || content.includes('Label') ||
                          content.includes('Anno di Uscita') || content.includes('Release Year');
    expect(hasMusicLabel).toBe(true);
  });
});
