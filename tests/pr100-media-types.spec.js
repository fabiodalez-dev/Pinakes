// @ts-check
/**
 * PR #100 Feature Tests: Media Types, Discogs Plugin, Dynamic Labels
 * 10 reusable tests covering the tipo_media system end-to-end.
 * Requires: app installed, admin user, tipo_media column in DB.
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

test.describe.serial('PR #100: Media Types System', () => {
  /** @type {import('@playwright/test').Page} */
  let page;
  /** @type {import('@playwright/test').BrowserContext} */
  let context;
  let cdId = '', bookId = '', audiobookId = '', dvdId = '';

  test.beforeAll(async ({ browser }) => {
    test.skip(!ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_PASS || !DB_NAME, 'Missing env vars');
    context = await browser.newContext();
    page = await context.newPage();

    // Login
    await page.goto(`${BASE}/accedi`);
    await page.fill('input[name="email"]', ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await page.click('button[type="submit"]');
    await page.waitForURL(/\/admin\//, { timeout: 15000 });

    // Seed 4 media types
    dbExec(
      "INSERT INTO libri (titolo, formato, tipo_media, ean, copie_totali, copie_disponibili, created_at, updated_at) " +
      "VALUES ('PR100_CD_" + RUN_ID + "', 'cd_audio', 'disco', '9990000000001', 1, 1, NOW(), NOW())"
    );
    dbExec(
      "INSERT INTO libri (titolo, formato, tipo_media, isbn13, copie_totali, copie_disponibili, created_at, updated_at) " +
      "VALUES ('PR100_Book_" + RUN_ID + "', 'cartaceo', 'libro', '9789990000002', 1, 1, NOW(), NOW())"
    );
    dbExec(
      "INSERT INTO libri (titolo, formato, tipo_media, copie_totali, copie_disponibili, created_at, updated_at) " +
      "VALUES ('PR100_Audiobook_" + RUN_ID + "', 'audiolibro', 'audiolibro', 1, 1, NOW(), NOW())"
    );
    dbExec(
      "INSERT INTO libri (titolo, formato, tipo_media, copie_totali, copie_disponibili, created_at, updated_at) " +
      "VALUES ('PR100_DVD_" + RUN_ID + "', 'dvd', 'dvd', 1, 1, NOW(), NOW())"
    );

    cdId = dbQuery("SELECT id FROM libri WHERE titolo = 'PR100_CD_" + RUN_ID + "' LIMIT 1");
    bookId = dbQuery("SELECT id FROM libri WHERE titolo = 'PR100_Book_" + RUN_ID + "' LIMIT 1");
    audiobookId = dbQuery("SELECT id FROM libri WHERE titolo = 'PR100_Audiobook_" + RUN_ID + "' LIMIT 1");
    dvdId = dbQuery("SELECT id FROM libri WHERE titolo = 'PR100_DVD_" + RUN_ID + "' LIMIT 1");
  });

  test.afterAll(async () => {
    try { dbExec("DELETE FROM libri WHERE (titolo LIKE 'PR100_%_" + RUN_ID + "' OR ean = '9990000000001' OR isbn13 = '9789990000002') AND deleted_at IS NULL"); } catch {}
    await context?.close();
  });

  // ═══════════════════════════════════════════════════════
  // 1. tipo_media column exists in DB
  // ═══════════════════════════════════════════════════════
  test('1. tipo_media column exists with correct ENUM values', async () => {
    const colType = dbQuery("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='libri' AND COLUMN_NAME='tipo_media'");
    expect(colType).toContain('libro');
    expect(colType).toContain('disco');
    expect(colType).toContain('audiolibro');
    expect(colType).toContain('dvd');
    expect(colType).toContain('altro');
  });

  // ═══════════════════════════════════════════════════════
  // 2. Admin book form has tipo_media dropdown
  // ═══════════════════════════════════════════════════════
  test('2. Book form has tipo_media dropdown with all options', async () => {
    await page.goto(`${BASE}/admin/libri/crea`);
    await page.waitForLoadState('domcontentloaded');

    const select = page.locator('#tipo_media');
    await expect(select).toBeVisible();

    const options = await select.locator('option').allTextContents();
    expect(options.length).toBeGreaterThanOrEqual(5);
  });

  // ═══════════════════════════════════════════════════════
  // 3. Admin list shows tipo_media icon column
  // ═══════════════════════════════════════════════════════
  test('3. Admin list has media type icon column', async () => {
    await page.goto(`${BASE}/admin/libri`);
    await page.waitForLoadState('domcontentloaded');
    await page.waitForTimeout(2000);

    const content = await page.content();
    // Should have the icon column header
    expect(content).toContain('fa-compact-disc');
  });

  // ═══════════════════════════════════════════════════════
  // 4. Admin list filters by tipo_media
  // ═══════════════════════════════════════════════════════
  test('4. API filters by tipo_media=disco', async () => {
    const resp = await page.request.get(`${BASE}/api/libri?tipo_media=disco&start=0&length=100&search_text=PR100`);
    expect(resp.status()).toBe(200);
    const data = await resp.json();
    const records = data.data || [];

    // Should find CD but not book/audiobook/dvd
    const titles = records.map((r) => r.titolo || '').join(' ');
    expect(titles).toContain('PR100_CD_');
    expect(titles).not.toContain('PR100_Book_');
  });

  // ═══════════════════════════════════════════════════════
  // 5. CD shows music labels (Etichetta, Anno di Uscita)
  // ═══════════════════════════════════════════════════════
  test('5. CD admin detail shows music-specific labels', async () => {
    await page.goto(`${BASE}/admin/libri/${cdId}`);
    await page.waitForLoadState('domcontentloaded');
    const content = await page.content();

    const hasEtichetta = content.includes('Etichetta') || content.includes('Label');
    const hasAnnoUscita = content.includes('Anno di Uscita') || content.includes('Release Year');
    expect(hasEtichetta || hasAnnoUscita).toBe(true);
    expect(content).toContain('fa-compact-disc');
  });

  // ═══════════════════════════════════════════════════════
  // 6. Book shows standard labels (Editore, Anno Pubblicazione)
  // ═══════════════════════════════════════════════════════
  test('6. Book admin detail shows standard labels', async () => {
    await page.goto(`${BASE}/admin/libri/${bookId}`);
    await page.waitForLoadState('domcontentloaded');
    const content = await page.content();

    const hasEditore = content.includes('Editore') || content.includes('Publisher');
    expect(hasEditore).toBe(true);
    expect(content).toContain('fa-book');
  });

  // ═══════════════════════════════════════════════════════
  // 7. Edit CD — tipo_media persists as 'disco'
  // ═══════════════════════════════════════════════════════
  test('7. Edit CD preserves tipo_media=disco', async () => {
    await page.goto(`${BASE}/admin/libri/modifica/${cdId}`);
    await page.waitForLoadState('domcontentloaded');

    const select = page.locator('#tipo_media');
    if (await select.isVisible({ timeout: 3000 }).catch(() => false)) {
      expect(await select.inputValue()).toBe('disco');

      // Change title, save
      await page.locator('input[name="titolo"]').fill('PR100_CD_' + RUN_ID + '_edited');
      await page.locator('button[type="submit"]').first().click();
      const swal = page.locator('.swal2-confirm');
      if (await swal.isVisible({ timeout: 3000 }).catch(() => false)) await swal.click();
      await page.waitForURL(/\/admin\/libri\/\d+/, { timeout: 15000 }).catch(() => {});

      // Verify DB
      const tipo = dbQuery(`SELECT tipo_media FROM libri WHERE id = ${cdId}`);
      expect(tipo).toBe('disco');

      // Restore title
      dbExec("UPDATE libri SET titolo = 'PR100_CD_" + RUN_ID + "' WHERE id = " + cdId);
    }
  });

  // ═══════════════════════════════════════════════════════
  // 8. CSV export includes tipo_media column
  // ═══════════════════════════════════════════════════════
  test('8. CSV export includes tipo_media', async () => {
    const resp = await page.request.get(`${BASE}/admin/libri/export/csv?ids=${cdId},${bookId}`);
    expect(resp.status()).toBe(200);
    const body = await resp.text();
    const header = body.split('\n')[0].replace(/^\uFEFF/, '');

    expect(header).toContain('tipo_media');

    const fields = header.split(';');
    const idx = fields.indexOf('tipo_media');
    expect(idx).toBeGreaterThan(-1);

    // Data rows
    const lines = body.split('\n').filter(l => l.trim());
    if (lines.length > 1) {
      const row1 = lines[1].split(';');
      // One should be disco, one empty/libro
      const tipos = lines.slice(1).map(l => l.split(';')[idx]);
      expect(tipos.some(t => t === 'disco')).toBe(true);
    }
  });

  // ═══════════════════════════════════════════════════════
  // 9. Format display name: "cd_audio" → "CD Audio"
  // ═══════════════════════════════════════════════════════
  test('9. Format shows human-readable name, not raw key', async () => {
    await page.goto(`${BASE}/admin/libri/${cdId}`);
    await page.waitForLoadState('domcontentloaded');
    const content = await page.content();

    // Should NOT show raw "cd_audio"
    // Should show "CD Audio" (or translated equivalent)
    const hasRaw = content.includes('>cd_audio<');
    const hasFormatted = content.includes('CD Audio') || content.includes('Audio CD') || content.includes('Audio-CD');
    expect(hasRaw).toBe(false);
    expect(hasFormatted).toBe(true);
  });

  // ═══════════════════════════════════════════════════════
  // 10. Discogs plugin is bundled and registered
  // ═══════════════════════════════════════════════════════
  test('10. Discogs plugin registered as bundled', async () => {
    const exists = dbQuery("SELECT COUNT(*) FROM plugins WHERE name = 'discogs'");
    expect(parseInt(exists)).toBeGreaterThan(0);

    // Plugin display name should be updated
    const displayName = dbQuery("SELECT display_name FROM plugins WHERE name = 'discogs' LIMIT 1");
    expect(displayName.toLowerCase()).toContain('music');
  });
});
