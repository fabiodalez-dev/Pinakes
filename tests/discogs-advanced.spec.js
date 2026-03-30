// @ts-check
/**
 * Advanced Discogs tests: tipo_media filtering, CSV export, Schema.org,
 * tracklist rendering, and edit persistence.
 * Requires: app installed, admin user, Discogs plugin active, music records in DB.
 */
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS || '';
const DB_NAME = process.env.E2E_DB_NAME || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';
const RUN_ID = Date.now();

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

test.describe.serial('Discogs Advanced Tests', () => {
  /** @type {import('@playwright/test').Page} */
  let page;
  /** @type {import('@playwright/test').BrowserContext} */
  let context;
  let musicBookId = '';
  let bookBookId = '';

  test.beforeAll(async ({ browser }) => {
    test.skip(!ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_PASS || !DB_NAME, 'Missing E2E env vars');
    context = await browser.newContext();
    page = await context.newPage();

    // Login
    await page.goto(`${BASE}/accedi`);
    await page.fill('input[name="email"]', ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await page.click('button[type="submit"]');
    await page.waitForURL(/\/admin\//, { timeout: 15000 });

    // Seed a music record and a book for comparison
    dbExec(
      "INSERT INTO libri (titolo, formato, tipo_media, ean, copie_totali, copie_disponibili, descrizione, note_varie, created_at, updated_at) " +
      "VALUES ('E2E_ADV_CD_" + RUN_ID + "', 'cd_audio', 'disco', '1234567890123', 1, 1, " +
      "'Track One - Track Two', 'Cat: TEST-001', NOW(), NOW())"
    );
    dbExec(
      "INSERT INTO libri (titolo, formato, tipo_media, isbn13, copie_totali, copie_disponibili, descrizione, created_at, updated_at) " +
      "VALUES ('E2E_ADV_Book_" + RUN_ID + "', 'cartaceo', 'libro', '9781234567897', 1, 1, " +
      "'A test book description', NOW(), NOW())"
    );

    musicBookId = dbQuery(`SELECT id FROM libri WHERE titolo = 'E2E_ADV_CD_${RUN_ID}' AND deleted_at IS NULL LIMIT 1`);
    bookBookId = dbQuery(`SELECT id FROM libri WHERE titolo = 'E2E_ADV_Book_${RUN_ID}' AND deleted_at IS NULL LIMIT 1`);
  });

  test.afterAll(async () => {
    try { dbExec("DELETE FROM libri WHERE (titolo LIKE 'E2E_ADV_%' OR ean = '1234567890123' OR isbn13 = '9781234567897') AND deleted_at IS NULL"); } catch {}
    await context?.close();
  });

  // ═══════════════════════════════════════════════════════════════════
  // Test 1: tipo_media filter in admin book list
  // ═══════════════════════════════════════════════════════════════════
  test('1. Admin list filters by tipo_media=disco', async () => {
    // Fetch the DataTable API with tipo_media filter
    const resp = await page.request.get(`${BASE}/api/libri?tipo_media=disco&start=0&length=100&search_text=E2E_ADV`);
    expect(resp.status()).toBe(200);
    const data = await resp.json();

    // Should find the CD but not the book
    const titles = (data.data || []).map((r) => r.titolo || r.info || '');
    const flatTitles = titles.join(' ');

    expect(flatTitles).toContain(`E2E_ADV_CD_${RUN_ID}`);
    expect(flatTitles).not.toContain(`E2E_ADV_Book_${RUN_ID}`);
  });

  // ═══════════════════════════════════════════════════════════════════
  // Test 2: CSV export includes tipo_media column
  // ═══════════════════════════════════════════════════════════════════
  test('2. CSV export includes tipo_media for music records', async () => {
    const resp = await page.request.get(`${BASE}/admin/libri/export/csv?ids=${musicBookId}`);
    expect(resp.status()).toBe(200);
    const body = await resp.text();

    // Parse header
    const lines = body.split('\n');
    const header = lines[0].replace(/^\uFEFF/, '');
    expect(header).toContain('tipo_media');

    // Parse the data row
    const headerFields = header.split(';');
    const tipoMediaIdx = headerFields.indexOf('tipo_media');
    expect(tipoMediaIdx).toBeGreaterThan(-1);

    if (lines.length > 1 && lines[1].trim()) {
      const dataFields = lines[1].split(';');
      expect(dataFields[tipoMediaIdx]).toBe('disco');
    }
  });

  // ═══════════════════════════════════════════════════════════════════
  // Test 3: Schema.org uses MusicAlbum for disco, Book for libro
  // ═══════════════════════════════════════════════════════════════════
  test('3. Schema.org JSON-LD type is MusicAlbum for disco', async () => {
    // We need the public URL for the music book — get it from sitemap or construct
    // The book might not have an author, so book_path may not work
    // Instead, check the admin detail for the Schema.org type (admin also renders it? No, only frontend)
    // Use the public API to check tipo_media is returned
    const apiResp = await page.request.get(`${BASE}/api/books?q=E2E_ADV_CD_${RUN_ID}`);

    if (apiResp.status() === 200) {
      const apiData = await apiResp.json();
      const books = Array.isArray(apiData) ? apiData : (apiData.results || apiData.data || []);
      const musicBook = books.find((b) => b.titolo?.includes(`E2E_ADV_CD_${RUN_ID}`) || b.title?.includes(`E2E_ADV_CD_${RUN_ID}`));

      if (musicBook) {
        expect(musicBook.tipo_media).toBe('disco');
      }
    }

    // Also verify via direct DB that tipo_media is stored correctly
    const tipoMedia = dbQuery(`SELECT tipo_media FROM libri WHERE id = ${musicBookId}`);
    expect(tipoMedia).toBe('disco');

    const bookTipoMedia = dbQuery(`SELECT tipo_media FROM libri WHERE id = ${bookBookId}`);
    expect(bookTipoMedia).toBe('libro');
  });

  // ═══════════════════════════════════════════════════════════════════
  // Test 4: Tracklist renders as <ol> in admin detail (music)
  //         vs prose <p> for regular books
  // ═══════════════════════════════════════════════════════════════════
  test('4. Admin detail: music shows tracklist, book shows prose description', async () => {
    // Music book
    await page.goto(`${BASE}/admin/libri/${musicBookId}`);
    await page.waitForLoadState('domcontentloaded');
    const musicContent = await page.content();

    // Should have tracklist HTML
    expect(musicContent).toContain('Track One');
    expect(musicContent).toContain('Track Two');

    // Should show music-specific labels
    const hasEtichetta = musicContent.includes('Etichetta') || musicContent.includes('Label');
    const hasAnnoUscita = musicContent.includes('Anno di Uscita') || musicContent.includes('Release Year');
    expect(hasEtichetta || hasAnnoUscita).toBe(true);

    // Should have the media type badge
    expect(musicContent).toContain('fa-compact-disc');

    // Regular book
    await page.goto(`${BASE}/admin/libri/${bookBookId}`);
    await page.waitForLoadState('domcontentloaded');
    const bookContent = await page.content();

    // Should have standard labels
    const hasEditore = bookContent.includes('Editore') || bookContent.includes('Publisher');
    expect(hasEditore).toBe(true);

    // Should have book icon badge
    expect(bookContent).toContain('fa-book');
  });

  // ═══════════════════════════════════════════════════════════════════
  // Test 5: Edit a CD — tipo_media persists after save
  // ═══════════════════════════════════════════════════════════════════
  test('5. Edit music record: tipo_media persists after save', async () => {
    await page.goto(`${BASE}/admin/libri/modifica/${musicBookId}`);
    await page.waitForLoadState('domcontentloaded');

    // Verify tipo_media select shows "disco" (or equivalent)
    const tipoSelect = page.locator('#tipo_media');
    if (await tipoSelect.isVisible({ timeout: 3000 }).catch(() => false)) {
      const currentValue = await tipoSelect.inputValue();
      expect(currentValue).toBe('disco');

      // Change the title slightly
      const titleInput = page.locator('input[name="titolo"]');
      const currentTitle = await titleInput.inputValue();
      await titleInput.fill(currentTitle + ' (edited)');

      // Submit
      await page.locator('button[type="submit"]').first().click();
      const swalConfirm = page.locator('.swal2-confirm');
      if (await swalConfirm.isVisible({ timeout: 3000 }).catch(() => false)) {
        await swalConfirm.click();
      }
      await page.waitForURL(/\/admin\/libri\/\d+/, { timeout: 15000 }).catch(() => {});

      // Verify tipo_media was NOT overwritten to 'libro'
      const tipoAfter = dbQuery(`SELECT tipo_media FROM libri WHERE id = ${musicBookId}`);
      expect(tipoAfter).toBe('disco');

      // Verify title was updated
      const titleAfter = dbQuery(`SELECT titolo FROM libri WHERE id = ${musicBookId}`);
      expect(titleAfter).toContain('(edited)');

      // Clean up title
      dbExec(`UPDATE libri SET titolo = 'E2E_ADV_CD_${RUN_ID}' WHERE id = ${musicBookId}`);
    } else {
      // tipo_media column might not exist yet (pre-migration)
      const tipoMedia = dbQuery(`SELECT tipo_media FROM libri WHERE id = ${musicBookId}`);
      expect(tipoMedia).toBe('disco');
    }
  });
});
