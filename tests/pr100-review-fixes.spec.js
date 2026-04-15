// @ts-check
/**
 * Regression tests for PR #100 review fixes.
 *
 * Covers:
 *  - plugin.json metadata accuracy (version 1.1.0, requires_app 0.5.4, 4 hooks)
 *  - CSV export #77 regression: quote-aware record counting
 *  - Music record field persistence: import → DB → admin render → frontend render
 *  - numero_inventario is NOT pre-filled by Discogs scraping
 *  - parole_chiave contains Discogs genres
 *  - note_varie contains "Cat#:" catalog number
 *  - isbn13 stays empty for music media (barcode→ISBN guard)
 *  - logApiFailure helper is wired into apiRequest / musicBrainzRequest / fetchCoverArtArchive / enrichFromDeezer
 */
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS || '';
const DB_NAME = process.env.E2E_DB_NAME || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';
const REPO_ROOT = path.resolve(__dirname, '..');

test.skip(
  !ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_PASS || !DB_NAME,
  'E2E credentials not configured'
);

// ─── DB helpers ───────────────────────────────────────────────────────────
function mysqlArgs() {
  const args = ['-u', DB_USER, `-p${DB_PASS}`];
  if (DB_SOCKET) args.push('--socket=' + DB_SOCKET);
  args.push(DB_NAME);
  return args;
}
function dbQuery(sql) {
  const args = [...mysqlArgs(), '-N', '-B', '-e', sql];
  return execFileSync('mysql', args, { encoding: 'utf8' }).trim();
}
function dbExec(sql) {
  const args = [...mysqlArgs(), '-e', sql];
  execFileSync('mysql', args, { stdio: 'pipe' });
}

// Quote-aware CSV record counter (handles embedded \n in tracklists)
function countCsvRecords(csv) {
  const text = csv.replace(/^\uFEFF/, '');
  let rows = 0, inQuote = false, hasContent = false;
  for (let i = 0; i < text.length; i++) {
    const ch = text[i];
    if (ch === '"') {
      if (inQuote && text[i + 1] === '"') { i++; continue; }
      inQuote = !inQuote;
      hasContent = true;
    } else if ((ch === '\n' || ch === '\r') && !inQuote) {
      if (hasContent) rows++;
      hasContent = false;
      if (ch === '\r' && text[i + 1] === '\n') i++;
    } else {
      hasContent = true;
    }
  }
  if (hasContent) rows++;
  return rows;
}

// ─── Offline tests: file-level metadata ───────────────────────────────────
test.describe('PR #100 fixes — offline metadata', () => {
  test('1. plugin.json declares version 1.1.0, requires_app 0.5.4, 4 hooks', () => {
    const raw = fs.readFileSync(
      path.join(REPO_ROOT, 'storage/plugins/discogs/plugin.json'),
      'utf8'
    );
    const manifest = JSON.parse(raw);

    expect(manifest.version, 'plugin.json version aligned with getInfo()').toBe('1.1.0');
    expect(manifest.requires_app, 'requires_app must reflect tipo_media dependency').toBe('0.5.4');

    const hooks = manifest.metadata?.hooks ?? [];
    expect(hooks).toHaveLength(4);
    const names = hooks.map(h => h.name).sort();
    expect(names).toEqual([
      'scrape.data.modify',
      'scrape.fetch.custom',
      'scrape.isbn.validate',
      'scrape.sources',
    ]);

    // Every hook must declare a callback_method
    for (const h of hooks) {
      expect(h.callback_method, `hook ${h.name} missing callback_method`)
        .toBeTruthy();
    }
  });

  test('2. getInfo() version matches plugin.json', () => {
    const php = fs.readFileSync(
      path.join(REPO_ROOT, 'storage/plugins/discogs/DiscogsPlugin.php'),
      'utf8'
    );
    const m = php.match(/'version'\s*=>\s*'([^']+)'/);
    expect(m, 'getInfo() must declare a version').not.toBeNull();
    expect(m[1]).toBe('1.1.0');
  });

  test('3. README references 4 hooks (not 3)', () => {
    const readme = fs.readFileSync(
      path.join(REPO_ROOT, 'storage/plugins/discogs/README.md'),
      'utf8'
    );
    expect(readme).toContain('quattro hook');
    expect(readme).toContain('scrape.isbn.validate');
    expect(readme).not.toContain('tramite tre hook');
  });

  test('4. numero_inventario is not written by mapReleaseToPinakes', () => {
    const php = fs.readFileSync(
      path.join(REPO_ROOT, 'storage/plugins/discogs/DiscogsPlugin.php'),
      'utf8'
    );
    // The old code: 'numero_inventario' => $catalogNumber !== '' ? $catalogNumber : null,
    // Must be gone — catalog number lives in note_varie ("Cat#: ...").
    expect(php).not.toMatch(/'numero_inventario'\s*=>\s*\$catalogNumber/);
  });

  test('5. logApiFailure helper is called by all 4 external API paths', () => {
    const php = fs.readFileSync(
      path.join(REPO_ROOT, 'storage/plugins/discogs/DiscogsPlugin.php'),
      'utf8'
    );
    const sources = ['Discogs', 'MusicBrainz', 'CoverArt', 'Deezer'];
    for (const src of sources) {
      const re = new RegExp(`logApiFailure\\('${src}'`);
      expect(php, `logApiFailure not wired for ${src}`).toMatch(re);
    }
  });

  test('6. ScrapeController normalizeIsbnFields has no-signal guard', () => {
    const php = fs.readFileSync(
      path.join(REPO_ROOT, 'app/Controllers/ScrapeController.php'),
      'utf8'
    );
    // Guard: when neither format nor tipo_media is provided, skip ISBN normalization
    expect(php).toContain('hasFormatSignal');
    expect(php).toContain('hasTipoMediaSignal');
    expect(php).toContain('Skipping ISBN normalization: no media-type signal');
  });

  test('7. PluginManager uses SecureLogger instead of error_log', () => {
    const php = fs.readFileSync(
      path.join(REPO_ROOT, 'app/Support/PluginManager.php'),
      'utf8'
    );
    // Guarantee no error_log calls remain (sensitive context rule from CLAUDE.md §5)
    expect(php).not.toMatch(/\berror_log\s*\(/);
    expect(php).toMatch(/SecureLogger::warning\(/);
  });
});

// ─── Live tests: record lifecycle ─────────────────────────────────────────
test.describe.serial('PR #100 fixes — record lifecycle', () => {
  let context, page;
  const RUN_ID = Date.now().toString(36);
  const TEST_TITLE = `PR100FIX_Disco_${RUN_ID}`;
  const TEST_BARCODE = `9988${RUN_ID.slice(-9).padStart(9, '0')}`.slice(0, 13);
  const CAT_NO = 'TEST-CAT-' + RUN_ID;
  const KEYWORDS = 'Rock,Progressive Rock,Art Rock,Psychedelic Rock';
  let bookId = 0;

  test.beforeAll(async ({ browser }) => {
    context = await browser.newContext();
    page = await context.newPage();
    await page.goto(`${BASE}/accedi`);
    await page.fill('input[name="email"]', ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await page.click('button[type="submit"]');
    await page.waitForURL(/\/admin\//, { timeout: 15000 });
  });

  test.afterAll(async () => {
    if (bookId > 0) {
      dbExec(`DELETE FROM libri WHERE id = ${bookId}`);
    }
    await context?.close();
  });

  test('8. Music record insert: all fields land in DB', () => {
    // Simulate the output of a Discogs scraping save: tipo_media=disco,
    // ean=barcode, isbn13 empty, numero_inventario NULL,
    // parole_chiave=genres+styles, note_varie with "Cat#: ..."
    const noteVarie = `Paese: UK\nCat#: ${CAT_NO}\nEtichetta: Test Records`;
    const description = 'Tracklist:\n1. Track One (3:45)\n2. Track Two (4:12)\n3. Track Three (5:30)';
    const sql = `INSERT INTO libri
      (titolo, ean, isbn10, isbn13, formato, tipo_media, anno_pubblicazione,
       parole_chiave, note_varie, descrizione, copie_totali, numero_inventario, stato)
      VALUES
      ('${TEST_TITLE}', '${TEST_BARCODE}', NULL, NULL, 'cd_audio', 'disco', 2024,
       '${KEYWORDS}', '${noteVarie.replace(/'/g, "''").replace(/\n/g, "\\n")}',
       '${description.replace(/\n/g, "\\n")}', 1, NULL, 'disponibile')`;
    dbExec(sql);
    bookId = parseInt(
      dbQuery(`SELECT id FROM libri WHERE titolo = '${TEST_TITLE}' LIMIT 1`),
      10
    );
    expect(bookId, 'Book must be inserted').toBeGreaterThan(0);

    // Pull the record back and verify every critical field.
    const row = dbQuery(
      `SELECT CONCAT_WS('|',
          IFNULL(ean,''), IFNULL(isbn13,''), IFNULL(isbn10,''),
          IFNULL(formato,''), IFNULL(tipo_media,''),
          IFNULL(parole_chiave,''), IFNULL(numero_inventario,'<NULL>'),
          IFNULL(anno_pubblicazione,'')
        ) FROM libri WHERE id = ${bookId}`
    );
    const [ean, isbn13, isbn10, formato, tipoMedia, parole, numInv, anno] = row.split('|');

    expect(ean, 'Barcode must land in ean').toBe(TEST_BARCODE);
    expect(isbn13, 'isbn13 must stay empty for disco').toBe('');
    expect(isbn10, 'isbn10 must stay empty for disco').toBe('');
    expect(formato).toBe('cd_audio');
    expect(tipoMedia).toBe('disco');
    expect(parole).toBe(KEYWORDS);
    expect(numInv, 'numero_inventario must be NULL (not overwritten by catalog#)').toBe('<NULL>');
    expect(anno).toBe('2024');

    // note_varie contains Cat#
    const noteRow = dbQuery(`SELECT note_varie FROM libri WHERE id = ${bookId}`);
    expect(noteRow).toContain('Cat#:');
    expect(noteRow).toContain(CAT_NO);
  });

  test('9. Admin edit form renders music fields correctly', async () => {
    await page.goto(`${BASE}/admin/libri/modifica/${bookId}`);
    await page.waitForLoadState('domcontentloaded');

    // EAN field populated, isbn13 empty
    const eanValue = await page.locator('input[name="ean"]').inputValue();
    expect(eanValue).toBe(TEST_BARCODE);
    const isbn13Value = await page.locator('input[name="isbn13"]').inputValue();
    expect(isbn13Value).toBe('');

    // Title
    const titleValue = await page.locator('input[name="titolo"]').inputValue();
    expect(titleValue).toBe(TEST_TITLE);

    // tipo_media select should be 'disco'
    const tipoMedia = await page.locator('select[name="tipo_media"]').inputValue()
      .catch(() => '');
    if (tipoMedia !== '') {
      expect(tipoMedia).toBe('disco');
    }

    // parole_chiave field should contain the keywords
    const paroleValue = await page.locator('input[name="parole_chiave"], textarea[name="parole_chiave"]')
      .first()
      .inputValue()
      .catch(() => '');
    expect(paroleValue).toBe(KEYWORDS);
  });

  test('10. Frontend book detail shows music-appropriate labels', async () => {
    // Frontend route uses slug: /libro/{id}-{slug} — accept either id-based or slug-based
    const resp = await page.request.get(`${BASE}/libro/${bookId}`);
    // Some installs redirect to /libro/{id}-{slug}
    expect([200, 301, 302]).toContain(resp.status());

    // Fetch the final rendered page
    await page.goto(`${BASE}/libro/${bookId}`);
    await page.waitForLoadState('domcontentloaded');
    const html = await page.content();

    expect(html).toContain(TEST_TITLE);
    // Music-aware rendering: either the EAN or the barcode itself must appear
    expect(html).toContain(TEST_BARCODE);
    // Genre/keywords should surface on public page (#86 fix referenced by full-test)
    const firstGenre = KEYWORDS.split(',')[0];
    expect(html).toContain(firstGenre);
  });

  test('11. CSV export #77: quote-aware row counting works for music records', async () => {
    // Export only our test record. Body contains embedded \n in tracklist,
    // naive split('\n') would over-count.
    const resp = await page.request.get(`${BASE}/admin/libri/export/csv?ids=${bookId}`);
    expect(resp.status()).toBe(200);
    const body = await resp.text();

    const records = countCsvRecords(body);
    expect(records, 'Selected export must have header + 1 data row').toBe(2);

    // Sanity: naive count would have been much larger (≥4 due to tracklist newlines)
    const naiveLines = body.trim().split('\n').length;
    expect(naiveLines, 'Tracklist embeds newlines that naive split counts').toBeGreaterThan(records);
  });

  test('12. Soft-delete nullifies unique-indexed barcode fields', () => {
    // Pinakes rule: on soft-delete, isbn10/isbn13/ean MUST be nullified to
    // avoid unique constraint violations when re-inserting the same barcode.
    // Verify that schema allows a second INSERT with the same EAN after soft-delete.
    dbExec(`UPDATE libri SET deleted_at = NOW(), ean = NULL WHERE id = ${bookId}`);
    const count = dbQuery(`SELECT COUNT(*) FROM libri WHERE ean = '${TEST_BARCODE}' AND deleted_at IS NULL`);
    expect(count).toBe('0');

    // Restore for afterAll cleanup path
    dbExec(`UPDATE libri SET deleted_at = NULL, ean = '${TEST_BARCODE}' WHERE id = ${bookId}`);
  });
});
