// E2E: standard CSV import (20 books) through the real browser UI.
// Per project rule, CSV/TSV import and book creation are ALWAYS verified via
// real browser E2E (Uppy upload + the full chunk loop), never API-only — an
// API test passed once while the browser flow timed out. Keep this test.
const { test, expect } = require('@playwright/test');
const path = require('path');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const CSV = path.join(__dirname, 'seeds', 'import-csv-20books.csv');

const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';
const DB_HOST = process.env.E2E_DB_HOST || '';
const DB_PORT = process.env.E2E_DB_PORT || '';
const DB_NAME = process.env.E2E_DB_NAME || '';

function dbQuery(sql) {
  // Password via MYSQL_PWD env, never as a -p argv (visible in ps//proc).
  const args = ['-u', DB_USER, DB_NAME, '-N', '-B', '-e', sql];
  if (DB_HOST) {
    args.splice(2, 0, '-h', DB_HOST);
    if (DB_PORT) args.splice(4, 0, '-P', DB_PORT);
  } else if (DB_SOCKET) {
    args.splice(2, 0, '-S', DB_SOCKET);
  }
  return execFileSync('mysql', args, {
    encoding: 'utf-8',
    timeout: 10000,
    env: { ...process.env, MYSQL_PWD: DB_PASS },
  }).trim();
}

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

// ---------------------------------------------------------------------------
// #380 — "Libri già presenti: quali dati aggiornare": per-family checkboxes
// (all checked by default = historical overwrite behavior). An UNCHECKED
// family is fully preserved on existing books (matched by ID/ISBN/EAN), while
// checked families update exactly as before. Verified via the real browser
// upload flow + DB readback.
// ---------------------------------------------------------------------------

const FIELDS_SKIP = !process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASS || !DB_USER || !DB_PASS || !DB_NAME;

const sqlEscape = (s) => String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'");

// Valid ISBN-13 (unique per run) so every import matches the same book.
function isbn13From(base12) {
  let sum = 0;
  for (let i = 0; i < 12; i++) sum += (i % 2 === 0 ? 1 : 3) * Number(base12[i]);
  return base12 + String((10 - (sum % 10)) % 10);
}

const F_RUN = Date.now().toString(36);
const TITLE_1 = `FieldsModeUno ${F_RUN}`;
const TITLE_2 = `FieldsModeDue ${F_RUN}`;
const TITLE_3 = `FieldsModeTre ${F_RUN}`;
const AUTHOR_A = `FieldsAuthorA ${F_RUN}`;
const AUTHOR_B = `FieldsAuthorB ${F_RUN}`;
const AUTHOR_C = `FieldsAuthorC ${F_RUN}`;
const TRANSLATOR_1 = `FieldsTranslatorUno ${F_RUN}`;
const TRANSLATOR_3 = `FieldsTranslatorTre ${F_RUN}`;
const GENRE_1 = `FieldsGenreUno ${F_RUN}`;
const GENRE_2 = `FieldsGenreDue ${F_RUN}`;
const GENRE_3 = `FieldsGenreTre ${F_RUN}`;
const PUBLISHER_1 = `FieldsPublisherUno ${F_RUN}`;
const PUBLISHER_2 = `FieldsPublisherDue ${F_RUN}`;
const PUBLISHER_3 = `FieldsPublisherTre ${F_RUN}`;
const KW_1 = `kwone${F_RUN}`;
const KW_2 = `kwtwo${F_RUN}`;
const KW_3 = `kwthree${F_RUN}`;
const DESC_1 = `Description one ${F_RUN}`;
const DESC_2 = `Description two ${F_RUN}`;
const DESC_3 = `Description three ${F_RUN}`;
const F_ISBN = isbn13From('9798' + String(Date.now() % 1e8).padStart(8, '0'));
const F_ISBN_2 = isbn13From('9789' + String(Date.now() % 1e8).padStart(8, '0'));
let F_BOOK_ID = '';

const F_HEADER = 'titolo;isbn13;autori;traduttore;genere;editore;parole_chiave;descrizione';
const F_CSV_1 = `${F_HEADER}\n${TITLE_1};${F_ISBN};${AUTHOR_A};${TRANSLATOR_1};${GENRE_1};${PUBLISHER_1};${KW_1};${DESC_1}\n`;
const F_CSV_2 = `${F_HEADER}\n${TITLE_2};${F_ISBN};${AUTHOR_B};;${GENRE_2};${PUBLISHER_2};${KW_2};${DESC_2}\n`;

function fieldsCleanup() {
  // FK-safe order: children of libri first, then the book, then the entities
  // this test created. Test-only rows — hard delete is fine.
  const bookWhere = `l.isbn13 IN ('${sqlEscape(F_ISBN)}','${sqlEscape(F_ISBN_2)}') OR l.titolo IN ('${sqlEscape(TITLE_1)}','${sqlEscape(TITLE_2)}','${sqlEscape(TITLE_3)}')`;
  dbQuery(
    `DELETE lais FROM libri_autori_import_sources lais JOIN libri l ON l.id=lais.libro_id WHERE ${bookWhere};`
    + `DELETE la FROM libri_autori la JOIN libri l ON l.id=la.libro_id WHERE ${bookWhere};`
    + `DELETE le FROM libri_editori le JOIN libri l ON l.id=le.libro_id WHERE ${bookWhere};`
    + `DELETE c FROM copie c JOIN libri l ON l.id=c.libro_id WHERE ${bookWhere};`
    + `DELETE FROM libri WHERE isbn13 IN ('${sqlEscape(F_ISBN)}','${sqlEscape(F_ISBN_2)}') OR titolo IN ('${sqlEscape(TITLE_1)}','${sqlEscape(TITLE_2)}','${sqlEscape(TITLE_3)}');`
    + `DELETE FROM autori WHERE nome IN ('${sqlEscape(AUTHOR_A)}','${sqlEscape(AUTHOR_B)}','${sqlEscape(AUTHOR_C)}','${sqlEscape(TRANSLATOR_1)}','${sqlEscape(TRANSLATOR_3)}');`
    + `DELETE FROM generi WHERE nome IN ('${sqlEscape(GENRE_1)}','${sqlEscape(GENRE_2)}','${sqlEscape(GENRE_3)}');`
    + `DELETE FROM editori WHERE nome IN ('${sqlEscape(PUBLISHER_1)}','${sqlEscape(PUBLISHER_2)}','${sqlEscape(PUBLISHER_3)}');`,
  );
}

/**
 * Upload one in-memory CSV through the real browser flow (same page/JS as the
 * 20-book test above), unchecking the given update_<family> checkboxes first
 * (they are all checked by default), and wait for the chunked import to
 * report completion.
 */
async function runFieldsImport(page, content, uncheck = []) {
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

  // All family checkboxes render checked; uncheck only the requested ones.
  for (const family of uncheck) {
    await page.uncheck(`#update_${family}`);
    await expect(page.locator(`#update_${family}`)).not.toBeChecked();
  }

  await page.setInputFiles('#csv_file', {
    name: 'fields-mode-test.csv',
    mimeType: 'text/csv',
    buffer: Buffer.from(content, 'utf-8'),
  });
  await page.evaluate(() => { const b = document.getElementById('submitBtn'); if (b) b.disabled = false; });
  await page.click('#submitBtn');

  await expect
    .poll(() => (lastChunk && lastChunk.complete === true) ? true : false, { timeout: 120000, intervals: [2000] })
    .toBe(true);

  expect(statuses.length).toBeGreaterThan(0);
  expect(statuses.every((s) => s === 200)).toBeTruthy();
  expect(lastChunk.errors).toBe(0);
  return lastChunk;
}

const bookField = (field) => dbQuery(
  `SELECT ${field} FROM libri WHERE isbn13='${sqlEscape(F_ISBN)}' AND deleted_at IS NULL LIMIT 1`,
);

const bookGenre = () => dbQuery(
  `SELECT g.nome FROM libri l JOIN generi g ON g.id=l.genere_id WHERE l.isbn13='${sqlEscape(F_ISBN)}' AND l.deleted_at IS NULL`,
);

const bookPublisher = () => dbQuery(
  `SELECT e.nome FROM libri l JOIN editori e ON e.id=l.editore_id WHERE l.isbn13='${sqlEscape(F_ISBN)}' AND l.deleted_at IS NULL`,
);

const principalAuthors = () => dbQuery(
  "SELECT a.nome FROM libri_autori la JOIN autori a ON a.id=la.autore_id JOIN libri l ON l.id=la.libro_id"
  + ` WHERE l.isbn13='${sqlEscape(F_ISBN)}' AND l.deleted_at IS NULL AND la.ruolo='principale'`
  + ' ORDER BY la.ordine_credito, a.nome',
).split('\n').filter(Boolean);

test.describe.serial('CSV import update-fields checkboxes (#380)', () => {
  test.skip(FIELDS_SKIP, 'E2E credentials not configured');
  test.beforeAll(() => fieldsCleanup());
  test.afterAll(() => fieldsCleanup());

  test('first import (all checked) creates the book with author A, genre 1, keyword 1', async ({ page }) => {
    test.setTimeout(150000);
    await loginAsAdmin(page);

    const chunk = await runFieldsImport(page, F_CSV_1);
    expect(chunk.imported).toBe(1);

    expect(principalAuthors()).toEqual([AUTHOR_A]);
    expect(bookField('titolo')).toBe(TITLE_1);
    expect(bookField('parole_chiave')).toBe(KW_1);
    expect(bookField('descrizione')).toBe(DESC_1);
    expect(bookField('traduttore')).toBe(TRANSLATOR_1);
    expect(bookGenre()).toBe(GENRE_1);
    expect(bookPublisher()).toBe(PUBLISHER_1);
    F_BOOK_ID = bookField('id');
    expect(Number(F_BOOK_ID)).toBeGreaterThan(0);
  });

  test('re-import with Autori UNCHECKED preserves authors, updates the rest', async ({ page }) => {
    test.setTimeout(150000);
    await loginAsAdmin(page);

    const chunk = await runFieldsImport(page, F_CSV_2, ['authors']);
    expect(chunk.updated).toBe(1);

    // Authors family unchecked → the existing principal is untouched and the
    // CSV author is NOT linked (nor created).
    expect(principalAuthors()).toEqual([AUTHOR_A]);
    expect(dbQuery(`SELECT COUNT(*) FROM autori WHERE nome='${sqlEscape(AUTHOR_B)}'`)).toBe('0');

    // Every checked family updated exactly as before.
    expect(bookField('titolo')).toBe(TITLE_2);
    expect(bookGenre()).toBe(GENRE_2);
    expect(bookPublisher()).toBe(PUBLISHER_2);
    expect(bookField('parole_chiave')).toBe(KW_2);
  });

  test('re-import with ALL checked overwrites the authors too (default behavior)', async ({ page }) => {
    test.setTimeout(150000);
    await loginAsAdmin(page);

    const chunk = await runFieldsImport(page, F_CSV_2);
    expect(chunk.updated).toBe(1);

    // Principal authors rewritten from the CSV: only B remains.
    expect(principalAuthors()).toEqual([AUTHOR_B]);
    expect(bookGenre()).toBe(GENRE_2);
    expect(bookPublisher()).toBe(PUBLISHER_2);
    expect(bookField('titolo')).toBe(TITLE_2);
    expect(bookField('parole_chiave')).toBe(KW_2);
  });

  test('unchecked families are true no-ops and do not create orphan entities', async ({ page }) => {
    test.setTimeout(150000);
    await loginAsAdmin(page);

    const csv = 'id;titolo;isbn13;autori;traduttore;genere;editore;parole_chiave;descrizione\n'
      + `${F_BOOK_ID};${TITLE_3};${F_ISBN_2};${AUTHOR_C};${TRANSLATOR_3};${GENRE_3};${PUBLISHER_3};${KW_3};${DESC_3}\n`;
    const chunk = await runFieldsImport(page, csv, [
      'authors',
      'contributors',
      'publisher',
      'genre',
      'keywords',
      'description',
      'bibliographic',
    ]);
    expect(chunk.updated).toBe(1);

    expect(bookField('isbn13')).toBe(F_ISBN);
    expect(bookField('titolo')).toBe(TITLE_2);
    expect(principalAuthors()).toEqual([AUTHOR_B]);
    expect(bookField('traduttore')).toBe('NULL');
    expect(bookGenre()).toBe(GENRE_2);
    expect(bookPublisher()).toBe(PUBLISHER_2);
    expect(bookField('parole_chiave')).toBe(KW_2);
    expect(bookField('descrizione')).toBe(DESC_2);

    // Values from unchecked relational families must not leak into standalone
    // catalogue rows either.
    expect(dbQuery(`SELECT COUNT(*) FROM autori WHERE nome IN ('${sqlEscape(AUTHOR_C)}','${sqlEscape(TRANSLATOR_3)}')`)).toBe('0');
    expect(dbQuery(`SELECT COUNT(*) FROM generi WHERE nome='${sqlEscape(GENRE_3)}'`)).toBe('0');
    expect(dbQuery(`SELECT COUNT(*) FROM editori WHERE nome='${sqlEscape(PUBLISHER_3)}'`)).toBe('0');
  });
});
