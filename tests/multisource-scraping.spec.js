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
const RUN_ID = Date.now().toString();
const RUN_TAG = `E2E_MULTI_${RUN_ID}`;

const OPEN_LIBRARY_ISBN = '9780140328721';
const ITALIAN_ISBN = '9788804671664';
const NEVERMIND_BARCODE = '0720642442524';
const MEDDLE_BARCODE = '5099902894225';

const MANUAL_BOOK_TITLE = `${RUN_TAG} BOOK MANUAL`;
const IMPORTED_BOOK_TITLE = `${RUN_TAG} BOOK IMPORT`;
const MANUAL_DISC_TITLE = `${RUN_TAG} DISC MANUAL`;
const IMPORTED_DISC_1_TITLE = `${RUN_TAG} DISC NEVERMIND`;
const IMPORTED_DISC_2_TITLE = `${RUN_TAG} DISC MEDDLE`;

const MANUAL_BOOK_ISBN13 = `9781234${RUN_ID.slice(-6)}`;
const MANUAL_DISC_EAN = `999${RUN_ID.slice(-10)}`;
const IMPORTED_BOOK_ISBN13 = `9782234${RUN_ID.slice(-6)}`;
const IMPORTED_DISC_1_EAN = `888${RUN_ID.slice(-10)}`;
const IMPORTED_DISC_2_EAN = `777${RUN_ID.slice(-10)}`;

function mysqlArgs(sql) {
  const args = ['-u', DB_USER, `-p${DB_PASS}`, DB_NAME, '-N', '-B', '-e', sql];
  if (DB_SOCKET) args.splice(3, 0, '-S', DB_SOCKET);
  return args;
}

function dbQuery(sql) {
  return execFileSync('mysql', mysqlArgs(sql), {
    encoding: 'utf-8',
    timeout: 10000,
  }).trim();
}

function sqlEscape(value) {
  return String(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
}

async function loginAsAdmin(page) {
  await page.goto(`${BASE}/accedi`);
  await page.fill('input[name="email"]', ADMIN_EMAIL);
  await page.fill('input[name="password"]', ADMIN_PASS);
  await page.click('button[type="submit"]');
  await page.waitForURL(/\/admin\//, { timeout: 15000 });
}

async function openCreateForm(page) {
  await page.goto(`${BASE}/admin/libri/crea`);
  await page.waitForLoadState('domcontentloaded');
}

async function importIdentifier(page, identifier) {
  await page.locator('#importIsbn').fill(identifier);
  await page.locator('#btnImportIsbn').click();
  await expect(page.locator('input[name="titolo"]')).not.toHaveValue('', { timeout: 20000 });

  const sourceNameLocator = page.locator('#scrapeSourceName');
  await expect.poll(
    async () => ((await sourceNameLocator.textContent().catch(() => '')) || '').trim(),
    { timeout: 5000 }
  ).not.toBe('');
  const sourceName = await sourceNameLocator.textContent().catch(() => '');
  return (sourceName || '').trim();
}

async function clearImportedEan(page) {
  const scrapedEan = page.locator('#scraped_ean');
  if (await scrapedEan.count()) {
    await scrapedEan.evaluate((node) => {
      node.value = '';
    });
  }
}

async function saveCurrentForm(page) {
  await page.locator('button[type="submit"]').first().click();

  const swalConfirm = page.locator('.swal2-confirm');
  if (await swalConfirm.isVisible({ timeout: 5000 }).catch(() => false)) {
    await swalConfirm.click();
  }

  await page.waitForURL(/\/admin\/libri\/\d+/, { timeout: 15000 });
  const match = page.url().match(/\/admin\/libri\/(\d+)/);
  if (!match) {
    throw new Error(`Could not resolve saved book id from URL: ${page.url()}`);
  }

  return Number(match[1]);
}

async function getScrapePayload(page, identifier) {
  const response = await page.request.get(`${BASE}/api/scrape/isbn?isbn=${encodeURIComponent(identifier)}`);
  expect(response.status(), `Unexpected scrape status for ${identifier}`).toBe(200);
  return response.json();
}

test.describe.serial('Multi-source scraping and creation flows', () => {
  /** @type {import('@playwright/test').BrowserContext} */
  let context;
  /** @type {import('@playwright/test').Page} */
  let page;

  let manualBookId = 0;
  let importedBookId = 0;
  let manualDiscId = 0;
  let importedDisc1Id = 0;
  let importedDisc2Id = 0;

  test.beforeAll(async ({ browser }) => {
    test.skip(
      !ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_PASS || !DB_NAME,
      'Missing E2E env vars'
    );

    context = await browser.newContext();
    page = await context.newPage();
    await loginAsAdmin(page);
  });

  test.afterAll(async () => {
    await context?.close();
  });

  test('1. Scraping plugins are active with the expected priority order', async () => {
    const activePlugins = dbQuery(
      "SELECT GROUP_CONCAT(name ORDER BY name SEPARATOR ',') FROM plugins WHERE name IN ('discogs','open-library','z39-server') AND is_active = 1"
    );
    expect(activePlugins).toContain('discogs');
    expect(activePlugins).toContain('open-library');
    expect(activePlugins).toContain('z39-server');

    const hookOrder = dbQuery(
      "SELECT GROUP_CONCAT(CONCAT(p.name, ':', ph.priority) ORDER BY ph.priority SEPARATOR ',') " +
      "FROM plugin_hooks ph JOIN plugins p ON p.id = ph.plugin_id " +
      "WHERE ph.hook_name = 'scrape.fetch.custom' AND ph.is_active = 1 AND p.name IN ('z39-server','open-library','discogs')"
    );
    expect(hookOrder).toBe('z39-server:3,open-library:5,discogs:8');
  });

  test('2. Scrape API returns Open Library book data for a known ISBN', async () => {
    const payload = await getScrapePayload(page, OPEN_LIBRARY_ISBN);

    expect(payload.title).toContain('Fantastic Mr. Fox');
    expect(payload.tipo_media).toBe('libro');
    expect(payload.source).toContain('openlibrary.org');
    expect(Array.isArray(payload.authors) ? payload.authors.length : 0).toBeGreaterThan(0);
    expect(payload.image).toBeTruthy();
  });

  test('3. Scrape API returns enriched Italian metadata for a second book ISBN', async () => {
    const payload = await getScrapePayload(page, ITALIAN_ISBN);

    expect(payload.title).toBeTruthy();
    expect(payload.tipo_media).toBe('libro');
    expect(payload.classificazione_dewey).toBe('188');
    expect(payload.isbn13).toBe(ITALIAN_ISBN);
    expect((payload.collana || payload.series || '').length).toBeGreaterThan(0);
  });

  test('4. Scrape API returns Discogs music data for Nevermind barcode', async () => {
    const payload = await getScrapePayload(page, NEVERMIND_BARCODE);

    expect(payload.source).toBe('discogs');
    expect(payload.title).toContain('Nevermind');
    expect(payload.tipo_media).toBe('disco');
    expect(payload.ean).toBe(NEVERMIND_BARCODE);
    expect(payload.publisher).toBeTruthy();
  });

  test('5. Scrape API returns Discogs music data for Meddle barcode', async () => {
    const payload = await getScrapePayload(page, MEDDLE_BARCODE);

    expect(payload.source).toBe('discogs');
    expect(payload.title).toContain('Meddle');
    expect(payload.tipo_media).toBe('disco');
    expect(payload.ean).toBe(MEDDLE_BARCODE);
    expect(payload.image).toBeTruthy();
  });

  test('6. Admin can create a manual book from the create form', async () => {
    await openCreateForm(page);

    await page.locator('input[name="titolo"]').fill(MANUAL_BOOK_TITLE);
    await page.locator('input[name="isbn13"]').fill(MANUAL_BOOK_ISBN13);
    await page.locator('input[name="copie_totali"]').fill('1');
    await page.locator('#tipo_media').selectOption('libro');
    await page.locator('input[name="formato"]').fill('cartaceo');

    manualBookId = await saveCurrentForm(page);
    expect(manualBookId).toBeGreaterThan(0);
  });

  test('7. The manual book is persisted as a book record', async () => {
    const row = dbQuery(
      `SELECT CONCAT(id, '|', titolo, '|', COALESCE(tipo_media, ''), '|', COALESCE(isbn13, '')) FROM libri WHERE titolo = '${sqlEscape(MANUAL_BOOK_TITLE)}' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1`
    );

    expect(row).toContain(MANUAL_BOOK_TITLE);
    expect(row).toContain('|libro|');
    expect(row).toContain(MANUAL_BOOK_ISBN13);
  });

  test('8. Admin can import and save a book from Open Library', async () => {
    await openCreateForm(page);

    const sourceName = await importIdentifier(page, OPEN_LIBRARY_ISBN);
    expect(sourceName).toContain('Open Library');

    await expect(page.locator('input[name="isbn13"]')).toHaveValue(OPEN_LIBRARY_ISBN);
    await page.locator('input[name="titolo"]').fill(IMPORTED_BOOK_TITLE);
    await page.locator('input[name="isbn10"]').fill('');
    await page.locator('input[name="isbn13"]').fill(IMPORTED_BOOK_ISBN13);
    await page.locator('input[name="ean"]').fill('');
    await clearImportedEan(page);
    await page.locator('input[name="copie_totali"]').fill('1');
    await expect(page.locator('#tipo_media')).toHaveValue('libro');

    importedBookId = await saveCurrentForm(page);
    expect(importedBookId).toBeGreaterThan(0);
  });

  test('9. The imported book detail keeps book labels and ISBN metadata', async () => {
    const row = dbQuery(
      `SELECT CONCAT(COALESCE(tipo_media, ''), '|', COALESCE(isbn13, ''), '|', COALESCE(ean, '')) FROM libri WHERE id = ${importedBookId}`
    );
    expect(row).toContain('libro');
    expect(row).toContain(IMPORTED_BOOK_ISBN13);

    await page.goto(`${BASE}/admin/libri/${importedBookId}`);
    await page.waitForLoadState('domcontentloaded');
    const html = await page.content();

    const hasBookLabel = html.includes('Editore') || html.includes('Publisher');
    const hasIsbnLabel = html.includes('ISBN-13') || html.includes(IMPORTED_BOOK_ISBN13);
    expect(hasBookLabel).toBe(true);
    expect(hasIsbnLabel).toBe(true);
  });

  test('10. Admin can create a manual disc from the create form', async () => {
    await openCreateForm(page);

    await page.locator('input[name="titolo"]').fill(MANUAL_DISC_TITLE);
    await page.locator('input[name="ean"]').fill(MANUAL_DISC_EAN);
    await page.locator('input[name="copie_totali"]').fill('1');
    await page.locator('#tipo_media').selectOption('disco');
    await page.locator('input[name="formato"]').fill('cd_audio');
    await page.locator('textarea[name="descrizione"]').fill('Manual test tracklist');

    manualDiscId = await saveCurrentForm(page);
    expect(manualDiscId).toBeGreaterThan(0);
  });

  test('11. The manual disc is persisted with music-specific metadata', async () => {
    const row = dbQuery(
      `SELECT CONCAT(COALESCE(tipo_media, ''), '|', COALESCE(ean, ''), '|', COALESCE(formato, '')) FROM libri WHERE id = ${manualDiscId}`
    );
    expect(row).toContain('disco');
    expect(row).toContain(MANUAL_DISC_EAN);
    expect(row).toContain('cd_audio');

    await page.goto(`${BASE}/admin/libri/${manualDiscId}`);
    await page.waitForLoadState('domcontentloaded');
    const html = await page.content();

    const hasMusicBadge = html.includes('fa-compact-disc') || html.includes('Barcode');
    expect(hasMusicBadge).toBe(true);
  });

  test('12. Admin can import and save a Discogs music release from Nevermind barcode', async () => {
    await openCreateForm(page);

    const sourceName = await importIdentifier(page, NEVERMIND_BARCODE);
    expect(sourceName.toLowerCase()).toContain('discogs');

    await expect(page.locator('#tipo_media')).toHaveValue('disco');
    await expect(page.locator('input[name="ean"]')).toHaveValue(NEVERMIND_BARCODE);
    await page.locator('input[name="titolo"]').fill(IMPORTED_DISC_1_TITLE);
    await page.locator('input[name="ean"]').fill(IMPORTED_DISC_1_EAN);
    await page.locator('input[name="isbn10"]').fill('');
    await page.locator('input[name="isbn13"]').fill('');
    await page.locator('input[name="copie_totali"]').fill('1');

    importedDisc1Id = await saveCurrentForm(page);
    expect(importedDisc1Id).toBeGreaterThan(0);
  });

  test('13. The first imported disc exposes music labels and Discogs metadata', async () => {
    const row = dbQuery(
      `SELECT CONCAT(COALESCE(tipo_media, ''), '|', COALESCE(ean, ''), '|', COALESCE(editore_id, 0), '|', COALESCE(anno_pubblicazione, '')) FROM libri WHERE id = ${importedDisc1Id}`
    );
    expect(row).toContain('disco');
    expect(row).toContain(IMPORTED_DISC_1_EAN);

    await page.goto(`${BASE}/admin/libri/${importedDisc1Id}`);
    await page.waitForLoadState('domcontentloaded');
    const html = await page.content();

    const hasMusicLabel = html.includes('Etichetta') || html.includes('Label');
    const hasBarcode = html.includes('Barcode') || html.includes(NEVERMIND_BARCODE);
    expect(hasMusicLabel || hasBarcode).toBe(true);
  });

  test('14. Admin can import and save a second Discogs release from Meddle barcode', async () => {
    await openCreateForm(page);

    const sourceName = await importIdentifier(page, MEDDLE_BARCODE);
    expect(sourceName.toLowerCase()).toContain('discogs');

    await expect(page.locator('#tipo_media')).toHaveValue('disco');
    await expect(page.locator('input[name="ean"]')).toHaveValue(MEDDLE_BARCODE);
    await page.locator('input[name="titolo"]').fill(IMPORTED_DISC_2_TITLE);
    await page.locator('input[name="ean"]').fill(IMPORTED_DISC_2_EAN);
    await page.locator('input[name="isbn10"]').fill('');
    await page.locator('input[name="isbn13"]').fill('');
    await page.locator('input[name="copie_totali"]').fill('1');

    importedDisc2Id = await saveCurrentForm(page);
    expect(importedDisc2Id).toBeGreaterThan(0);

    const row = dbQuery(
      `SELECT CONCAT(COALESCE(tipo_media, ''), '|', COALESCE(ean, ''), '|', COALESCE(titolo, '')) FROM libri WHERE id = ${importedDisc2Id}`
    );
    expect(row).toContain('disco');
    expect(row).toContain(IMPORTED_DISC_2_EAN);
    expect(row).toContain(IMPORTED_DISC_2_TITLE);
  });

  test('15. Admin filters and public search distinguish the new books and discs', async () => {
    const adminResponse = await page.request.get(
      `${BASE}/api/libri?tipo_media=disco&start=0&length=200&search_text=${encodeURIComponent(RUN_TAG)}`
    );
    expect(adminResponse.status()).toBe(200);
    const adminData = await adminResponse.json();
    const adminText = JSON.stringify(adminData.data || []);

    expect(adminText).toContain(MANUAL_DISC_TITLE);
    expect(adminText).toContain(IMPORTED_DISC_1_TITLE);
    expect(adminText).toContain(IMPORTED_DISC_2_TITLE);
    expect(adminText).not.toContain(MANUAL_BOOK_TITLE);
    expect(adminText).not.toContain(IMPORTED_BOOK_TITLE);

    const publicResponse = await page.request.get(`${BASE}/api/catalog?q=${encodeURIComponent(RUN_TAG)}`);
    expect(publicResponse.status()).toBe(200);
    const publicData = await publicResponse.json();
    const publicHtml = publicData.html || '';

    expect(publicHtml).toContain(MANUAL_BOOK_TITLE);
    expect(publicHtml).toContain(IMPORTED_BOOK_TITLE);
    expect(publicHtml).toContain(MANUAL_DISC_TITLE);
    expect(publicHtml).toContain(IMPORTED_DISC_1_TITLE);
    expect(publicHtml).toContain(IMPORTED_DISC_2_TITLE);

    const publicDiscResponse = await page.request.get(
      `${BASE}/api/catalog?q=${encodeURIComponent(RUN_TAG)}&tipo_media=disco`
    );
    expect(publicDiscResponse.status()).toBe(200);
    const publicDiscData = await publicDiscResponse.json();
    const publicDiscHtml = publicDiscData.html || '';

    expect(publicDiscHtml).toContain(MANUAL_DISC_TITLE);
    expect(publicDiscHtml).toContain(IMPORTED_DISC_1_TITLE);
    expect(publicDiscHtml).toContain(IMPORTED_DISC_2_TITLE);
    expect(publicDiscHtml).not.toContain(MANUAL_BOOK_TITLE);
    expect(publicDiscHtml).not.toContain(IMPORTED_BOOK_TITLE);
  });
});
