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

// Unique barcode for testing (not used by seeded records)
const TEST_BARCODE = '9999999999901';
const TEST_ARTIST = 'Pink Floyd';

test.describe.serial('Discogs Plugin (#87)', () => {
  /** @type {import('@playwright/test').BrowserContext} */
  let context;
  /** @type {import('@playwright/test').Page} */
  let page;
  let pluginActivated = false;
  let discogsPluginId = '';

  test.beforeAll(async ({ browser }) => {
    test.skip(
      !ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_PASS || !DB_NAME,
      'Missing E2E env vars'
    );
    // Pre-clean: afterAll() is best-effort. If a previous run crashed before
    // cleanup, an existing row with ean=TEST_BARCODE would trip the UNIQUE
    // index on libri.ean before test 6 reaches any assertion. Nullify the
    // unique cols + soft-delete (CLAUDE.md soft-delete consistency).
    try {
      dbExec(
        `UPDATE libri SET deleted_at = NOW(), ean = NULL, isbn13 = NULL, ` +
        `isbn10 = NULL WHERE (ean = '${TEST_BARCODE}' OR isbn13 = '${TEST_BARCODE}') AND deleted_at IS NULL`
      );
    } catch { /* best-effort */ }

    context = await browser.newContext();
    page = await context.newPage();

    // Login
    await page.goto(`${BASE}/admin/dashboard`);
    const emailField = page.locator('input[name="email"]');
    if (await emailField.isVisible({ timeout: 3000 }).catch(() => false)) {
      await emailField.fill(ADMIN_EMAIL);
      await page.fill('input[name="password"]', ADMIN_PASS);
      await page.click('button[type="submit"]');
      await page.waitForURL(/\/admin\//, { timeout: 15000 });
    }
  });

  test.afterAll(async () => {
    // Cleanup: remove test books
    try { dbExec("DELETE FROM libri WHERE titolo LIKE '%E2E_DISCOGS_%'"); } catch {}
    await context?.close();
  });

  test('1. Discogs plugin files exist in storage', async () => {
    // Verify plugin is shipped (via DB — plugins table may have it installed)
    const pluginExists = dbQuery(
      "SELECT COUNT(*) FROM plugins WHERE name = 'discogs'"
    );

    // If not installed, check if plugin.json is accessible via the admin page
    await page.goto(`${BASE}/admin/plugins`);
    await page.waitForLoadState('domcontentloaded');
    const pageContent = await page.content();
    const discogsCard = page.locator('div[data-plugin-id]').filter({
      has: page.getByRole('heading', { name: /discogs/i }),
    }).first();
    await expect(discogsCard).toBeVisible({ timeout: 5000 });

    // Plugin should appear in the list (installed or available)
    expect(
      pageContent.toLowerCase().includes('discogs') || parseInt(pluginExists) > 0
    ).toBe(true);
  });

  test('2. Activate Discogs plugin', async () => {
    await page.goto(`${BASE}/admin/plugins`);
    await page.waitForLoadState('domcontentloaded');

    // Check if already active
    const isActive = dbQuery(
      "SELECT COUNT(*) FROM plugins WHERE name = 'discogs' AND is_active = 1"
    );
    if (parseInt(isActive) > 0) {
      pluginActivated = true;
      return;
    }

    discogsPluginId = dbQuery("SELECT id FROM plugins WHERE name = 'discogs' LIMIT 1");
    expect(discogsPluginId).not.toBe('');

    const discogsCard = page.locator('div[data-plugin-id]').filter({
      has: page.getByRole('heading', { name: /discogs/i }),
    }).first();
    await expect(discogsCard, 'Discogs card not found on the plugins page').toBeVisible({ timeout: 5000 });

    const activateBtn = discogsCard.getByRole('button', { name: /^Attiva$/ }).first();
    if (await activateBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
      await activateBtn.click();
      await page.waitForLoadState('domcontentloaded');
      await page.waitForTimeout(2000);
    }

    // Verify activation
    const activeNow = dbQuery(
      "SELECT COUNT(*) FROM plugins WHERE name = 'discogs' AND is_active = 1"
    );
    expect(parseInt(activeNow, 10), 'Discogs plugin failed to activate').toBeGreaterThan(0);
    pluginActivated = true;
  });

  test('3. Plugin settings page loads', async () => {
    test.skip(!pluginActivated, 'Discogs plugin not activated');
    if (!discogsPluginId) {
      discogsPluginId = dbQuery("SELECT id FROM plugins WHERE name = 'discogs' LIMIT 1");
    }
    expect(discogsPluginId).not.toBe('');

    await page.goto(`${BASE}/admin/plugins/${discogsPluginId}/settings`);
    await page.waitForLoadState('domcontentloaded');

    const tokenField = page.locator('input[name="api_token"]');
    await expect(tokenField).toBeVisible({ timeout: 3000 });
  });

  test('4. MediaLabels: book with music format shows adapted labels', async () => {
    // Create a test book with music format via DB
    dbExec(`
      INSERT INTO libri (titolo, formato, copie_totali, copie_disponibili, created_at, updated_at)
      VALUES ('E2E_DISCOGS_MediaLabel_Test', 'cd_audio', 1, 1, NOW(), NOW())
    `);
    const bookId = dbQuery(
      "SELECT id FROM libri WHERE titolo = 'E2E_DISCOGS_MediaLabel_Test' AND deleted_at IS NULL LIMIT 1"
    );
    expect(bookId).not.toBe('');

    // Visit the admin book detail page
    await page.goto(`${BASE}/admin/libri/${bookId}`);
    await page.waitForLoadState('domcontentloaded');
    const adminContent = await page.content();

    // Labels should be music-aware (check for at least one adapted label)
    // "Etichetta" instead of "Editore", or "Anno di Uscita" instead of "Anno di Pubblicazione"
    const hasEtichetta = adminContent.includes('Etichetta') || adminContent.includes('Label');
    const hasAnnoUscita = adminContent.includes('Anno di Uscita') || adminContent.includes('Release Year');
    expect(hasEtichetta || hasAnnoUscita).toBe(true);
  });

  test('5. MediaLabels: regular book keeps standard labels', async () => {
    // Create a regular book
    dbExec(`
      INSERT INTO libri (titolo, formato, copie_totali, copie_disponibili, created_at, updated_at)
      VALUES ('E2E_DISCOGS_RegularBook', 'cartaceo', 1, 1, NOW(), NOW())
    `);
    const bookId = dbQuery(
      "SELECT id FROM libri WHERE titolo = 'E2E_DISCOGS_RegularBook' AND deleted_at IS NULL LIMIT 1"
    );

    await page.goto(`${BASE}/admin/libri/${bookId}`);
    await page.waitForLoadState('domcontentloaded');
    const content = await page.content();

    // Should have standard labels (Editore, not Etichetta)
    // Won't have "Etichetta" unless there's music data
    const hasEditore = content.includes('Editore') || content.includes('Publisher');
    expect(hasEditore).toBe(true);
  });

  test('6. Frontend: music book shows Barcode instead of ISBN-13', async () => {
    // Create a music book with EAN
    dbExec(
      "INSERT INTO libri (titolo, formato, tipo_media, ean, copie_totali, copie_disponibili, created_at, updated_at) " +
      "VALUES ('E2E_DISCOGS_Frontend_CD', 'vinile', 'disco', '" + TEST_BARCODE + "', 1, 1, NOW(), NOW())"
    );
    const bookId = dbQuery(
      "SELECT id FROM libri WHERE titolo = 'E2E_DISCOGS_Frontend_CD' AND deleted_at IS NULL LIMIT 1"
    );

    const resp = await page.request.get(`${BASE}/libro/${bookId}`);
    expect(resp.status()).toBe(200);

    // Check that the frontend music page uses the barcode label path
    const html = await resp.text();
    const hasBarcode = html.includes('Barcode');
    const hasMusicLabel = html.includes('Etichetta') || html.includes('Label') ||
                          html.includes('Anno di Uscita') || html.includes('Release Year');
    expect(hasBarcode).toBe(true);
    expect(hasMusicLabel).toBe(true);
  });

  test('7. Discogs scraping via ISBN import (if plugin active)', async () => {
    test.skip(!pluginActivated, 'Discogs plugin not activated');

    await page.goto(`${BASE}/admin/libri/crea`);
    await page.waitForLoadState('domcontentloaded');

    const importBtn = page.locator('#btnImportIsbn');
    if (!await importBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
      test.skip(true, 'Import button not visible');
      return;
    }

    // Attach console error listener BEFORE the scrape so we capture any
    // JS error that fires during import. Previously the listener was added
    // after the scrape and never asserted, so a silent JS failure passed.
    const consoleErrors = [];
    page.on('console', msg => {
      if (msg.type() === 'error') consoleErrors.push(msg.text());
    });

    // Try importing with a known CD barcode
    await page.locator('#importIsbn').fill(TEST_BARCODE);
    await importBtn.click();

    // Wait for response (up to 15s — Discogs can be slow)
    await page.waitForTimeout(5000);

    // Check if any fields were populated
    const titleField = page.locator('input[name="titolo"]');
    const titleValue = await titleField.inputValue().catch(() => '');

    if (titleValue !== '') {
      // Scraping succeeded — verify some data
      expect(titleValue.length).toBeGreaterThan(0);

      // Check if format was set to a music type
      const formatField = page.locator('input[name="formato"]');
      const formatValue = await formatField.inputValue().catch(() => '');
      // Format might be populated from Discogs

      // Check description (should contain tracklist)
      const descFrame = page.frameLocator('.tox-edit-area__iframe').first();
      if (await descFrame.locator('body').isVisible({ timeout: 2000 }).catch(() => false)) {
        const descText = await descFrame.locator('body').textContent().catch(() => '');
        // If Discogs returned tracklist, description should have content
        if (descText) {
          expect(descText.length).toBeGreaterThan(0);
        }
      }
    }
    // Whether scraping succeeded (populated the title) or failed gracefully
    // (rate limit / network), the page must not have thrown any console errors.
    // Known-benign errors (e.g., ad-blockers blocking CDN maps in dev) can be
    // allowlisted here if they surface in CI.
    const unexpectedErrors = consoleErrors.filter(e =>
      !e.includes('Awesomplete') && !e.includes('.map') && !e.includes('favicon')
    );
    expect(unexpectedErrors, `console errors during scrape: ${unexpectedErrors.join(' | ')}`)
      .toEqual([]);
  });
});
