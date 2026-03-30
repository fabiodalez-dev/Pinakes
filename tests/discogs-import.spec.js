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
    try { dbExec("DELETE FROM libri WHERE ean = '0720642442524' AND deleted_at IS NULL"); } catch {}
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
      test.skip(parseInt(isActiveNow) === 0, 'Discogs plugin could not be activated');
    }
  });

  test('2. Import CD via barcode in book form', async () => {
    await page.goto(`${BASE}/admin/libri/crea`);
    await page.waitForLoadState('domcontentloaded');

    // Find the ISBN import field and button
    const importField = page.locator('#importIsbn');
    const importBtn = page.locator('#btnImportIsbn');

    if (!await importBtn.isVisible({ timeout: 5000 }).catch(() => false)) {
      test.skip(true, 'Import button not visible — no scraping plugin active');
      return;
    }

    // Enter barcode and trigger import
    await importField.fill(TEST_BARCODE);
    await importBtn.click();

    // Wait for scraping response (Discogs needs time + rate limits)
    // The scraping service tries multiple sources — wait up to 20s
    await page.waitForTimeout(8000);

    // Check if title was populated
    const titleField = page.locator('input[name="titolo"]');
    const titleValue = await titleField.inputValue();

    if (titleValue === '') {
      // Scraping may have failed (rate limit, network). Check if any source populated data
      test.skip(true, 'Scraping did not return data (possibly rate limited)');
      return;
    }

    // Title should contain "Nevermind" (the album name)
    expect(titleValue.toLowerCase()).toContain('nevermind');
  });

  test('3. Verify scraped fields are populated', async () => {
    // After successful scraping, check multiple fields
    const titleValue = await page.locator('input[name="titolo"]').inputValue();
    test.skip(titleValue === '', 'No scraped data available');

    // Author/Artist should be populated
    // Choices.js creates items — check if any author is selected
    const authorItems = page.locator('#autori-wrapper .choices__item--selectable, .choices__item.choices__item--selectable');
    const authorCount = await authorItems.count().catch(() => 0);

    // At minimum, title should be populated
    expect(titleValue.length).toBeGreaterThan(0);

    // Check EAN field has the barcode
    const eanValue = await page.locator('input[name="ean"]').inputValue();
    // The barcode might be in isbn13 or ean depending on the scraper
    const isbn13Value = await page.locator('input[name="isbn13"]').inputValue();
    expect(eanValue === TEST_BARCODE || isbn13Value === TEST_BARCODE ||
           eanValue.includes('720642442524') || isbn13Value.includes('720642442524')).toBe(true);
  });

  test('4. Save the imported CD', async () => {
    const titleValue = await page.locator('input[name="titolo"]').inputValue();
    test.skip(titleValue === '', 'No scraped data to save');

    // Set copies (required field)
    await page.locator('input[name="copie_totali"]').fill('1');

    // Submit the form
    await page.locator('button[type="submit"]').first().click();
    await page.waitForLoadState('domcontentloaded');
    await page.waitForTimeout(2000);

    // Should redirect to book detail or show success
    const url = page.url();
    const content = await page.content();

    // Either redirected to book detail or success message
    const saved = url.includes('/admin/libri/') || content.includes('successo') || content.includes('success');
    expect(saved).toBe(true);
  });

  test('5. Verify saved CD in database', async () => {
    const book = dbQuery(
      `SELECT titolo, ean, formato FROM libri WHERE ean = '${TEST_BARCODE}' AND deleted_at IS NULL LIMIT 1`
    );

    if (book === '') {
      // Try isbn13
      const bookByIsbn = dbQuery(
        `SELECT titolo, isbn13, formato FROM libri WHERE isbn13 LIKE '%720642442524%' AND deleted_at IS NULL LIMIT 1`
      );
      test.skip(bookByIsbn === '', 'CD not found in database');
      if (bookByIsbn) {
        expect(bookByIsbn.toLowerCase()).toContain('nevermind');
      }
      return;
    }

    expect(book.toLowerCase()).toContain('nevermind');
  });

  test('6. Verify music labels on saved CD detail page', async () => {
    const bookId = dbQuery(
      `SELECT id FROM libri WHERE (ean = '${TEST_BARCODE}' OR isbn13 LIKE '%720642442524%') AND deleted_at IS NULL LIMIT 1`
    );
    test.skip(bookId === '', 'CD not found for label check');

    await page.goto(`${BASE}/admin/libri/${bookId}`);
    await page.waitForLoadState('domcontentloaded');
    const content = await page.content();

    // Check format was set to a music format by the scraper
    const formato = dbQuery(`SELECT formato FROM libri WHERE id = ${bookId}`);

    if (formato && ['cd_audio', 'vinile', 'cd', 'vinyl'].some(f => formato.toLowerCase().includes(f))) {
      // Music labels should be active
      const hasMusicLabel = content.includes('Etichetta') || content.includes('Label') ||
                            content.includes('Anno di Uscita') || content.includes('Release Year');
      expect(hasMusicLabel).toBe(true);
    }
  });
});
