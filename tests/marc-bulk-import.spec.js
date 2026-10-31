// E2E: bulk MARC import (/admin/books/import/marc) through the real browser:
// upload, preview, confirm, chunked processing, duplicates, error report.
// Files are generated per run (tests/helpers/marc-fixtures.php), so titles and
// ISBNs never collide with an earlier run; afterAll removes what the run made.
const { test, expect } = require('@playwright/test');
const { listServerDir, readServerFile, writeServerFile } = require('./helpers/server-files');
const path = require('path');
const { BASE, ROOT, dbQuery, sqlEscape, loginAsAdmin, marcFile, cleanupRun, uploadForPreview, confirmImport } = require('./helpers/marc-e2e');

const RUN = 'MI' + Date.now().toString(36);
const SKIP = !process.env.E2E_ADMIN_EMAIL || !process.env.E2E_DB_NAME;

test.describe.serial('MARC bulk import', () => {
  test.skip(SKIP, 'E2E credentials not set');
  test.afterAll(() => cleanupRun(RUN));

  test('a 50-record MARC 21 .mrc file: preview, confirm, books in the database and the catalogue', async ({ page }) => {
    test.setTimeout(180000);
    await loginAsAdmin(page);
    const file = marcFile(RUN, 50, 'mrc');
    const upload = await uploadForPreview(page, file);
    expect(upload.success).toBe(true);
    expect(upload.total_rows).toBe(50);

    // The preview lists the first ten mapped records, all new
    await expect(page.locator('#marc-preview')).toBeVisible();
    await expect(page.locator('#marc-preview-summary')).toContainText('50');
    await expect(page.locator('#marc-preview-rows tr[data-preview-row="record"]')).toHaveCount(10);
    await expect(page.locator('#marc-preview-rows')).toContainText(`Libro MARC ${RUN} 01`);
    await expect(page.locator('#marc-preview-rows')).toContainText(`Autore${RUN} 01, Mario`);
    // Nothing is written before the librarian confirms
    expect(dbQuery(`SELECT COUNT(*) FROM libri WHERE titolo LIKE '%${RUN}%' AND deleted_at IS NULL`)).toBe('0');

    const result = await confirmImport(page);
    expect(result.imported).toBe(50);
    expect(result.failed).toBe(0);
    await expect(page.locator('#marc-result-summary')).toContainText('50');

    expect(dbQuery(`SELECT COUNT(*) FROM libri WHERE titolo LIKE 'Libro MARC ${RUN} %' AND deleted_at IS NULL`)).toBe('50');
    // Field by field on one book
    const row = dbQuery(`SELECT CONCAT_WS('|', titolo, sottotitolo, edizione, luogo_pubblicazione, anno_pubblicazione, numero_pagine, dimensioni, collana, numero_serie, classificazione_dewey, lingua) FROM libri WHERE titolo = 'Libro MARC ${RUN} 07' AND deleted_at IS NULL`);
    expect(row).toBe(`Libro MARC ${RUN} 07|romanzo di prova|2. ed.|Milano|2019|127|21 cm|Collana${RUN}|7|853.92|Italiano`);
    expect(dbQuery(`SELECT a.nome FROM libri l JOIN libri_autori la ON la.libro_id=l.id AND la.ruolo='principale' JOIN autori a ON a.id=la.autore_id WHERE l.titolo='Libro MARC ${RUN} 07'`)).toBe(`Mario Autore${RUN} 07`);
    // The 700 named in 245 $c as "traduzione di" is the translator, not an author
    expect(dbQuery(`SELECT GROUP_CONCAT(la.ruolo) FROM libri l JOIN libri_autori la ON la.libro_id=l.id JOIN autori a ON a.id=la.autore_id WHERE l.titolo='Libro MARC ${RUN} 07' AND a.nome LIKE 'Anna Traduttrice%'`)).toBe('traduttore');
    expect(dbQuery(`SELECT e.nome FROM libri l JOIN editori e ON e.id=l.editore_id WHERE l.titolo='Libro MARC ${RUN} 07'`)).toBe(`Editrice${RUN}`);
    // One copy per book, as the CSV import does
    expect(dbQuery(`SELECT COUNT(*) FROM copie c JOIN libri l ON l.id=c.libro_id WHERE l.titolo='Libro MARC ${RUN} 07'`)).toBe('1');
    // The import is in the history as a MARC import
    expect(dbQuery(`SELECT CONCAT(import_type,':',imported,':',status) FROM import_logs WHERE file_name LIKE '%${RUN}-mrc%' ORDER BY id DESC LIMIT 1`)).toBe('marc:50:completed');

    // And in the catalogue: the admin book list and the public catalogue find it
    await page.goto(`${BASE}/admin/books`);
    await page.fill('#search_text', `Libro MARC ${RUN} 07`);
    await expect(page.locator('#books-table, #table-view').first()).toContainText(`Libro MARC ${RUN} 07`, { timeout: 15000 });
    await page.goto(`${BASE}/catalogo?q=${encodeURIComponent(`Libro MARC ${RUN} 07`)}`);
    await expect(page.locator('main, body').first()).toContainText(`Libro MARC ${RUN} 07`, { timeout: 15000 });
  });

  test('a staged file that ends early stops the import with an error instead of looping', async ({ page }) => {
    test.setTimeout(120000);
    await loginAsAdmin(page);
    // writable/uploads belongs to the PHP user (www-data in CI).
    const uploads = path.join(ROOT, 'writable', 'uploads');
    const staged = () => listServerDir(uploads).filter((n) => n.startsWith('marc_import_') && n.endsWith('.jsonl'));
    const before = new Set(staged());
    const upload = await uploadForPreview(page, marcFile(RUN + 'T', 20, 'mrc'));
    expect(upload.total_rows).toBe(20);
    const added = staged().filter((n) => !before.has(n));
    expect(added.length, 'the upload stages one file').toBe(1);
    // Cut the staged copy to its first three records, as a full disk or a
    // cleanup run would leave it.
    const file = path.join(uploads, added[0]);
    writeServerFile(file, readServerFile(file).toString('utf-8').split('\n').slice(0, 3).join('\n') + '\n');
    let chunks = 0;
    page.on('request', (r) => { if (r.url().includes('/admin/books/import/marc/chunk')) chunks++; });
    await page.click('#confirmImportBtn');
    await expect(page.locator('.swal2-popup')).toBeVisible({ timeout: 30000 });
    await expect(page.locator('.swal2-popup')).toContainText('incompleto');
    expect(chunks, 'the page stops asking for chunks').toBeLessThanOrEqual(2);
    expect(dbQuery(`SELECT COUNT(*) FROM libri WHERE titolo LIKE '%${RUN}T%' AND deleted_at IS NULL`)).toBe('3');
  });

  test('a MARCXML collection imports the same way', async ({ page }) => {
    test.setTimeout(90000);
    await loginAsAdmin(page);
    const file = marcFile(RUN, 4, 'xml', 101);
    const upload = await uploadForPreview(page, file);
    expect(upload.container).toBe('xml');
    expect(upload.total_rows).toBe(4);
    await expect(page.locator('#marc-preview-rows tr[data-preview-row="record"]')).toHaveCount(4);
    const result = await confirmImport(page);
    expect(result.imported).toBe(4);
    expect(dbQuery(`SELECT COUNT(*) FROM libri WHERE titolo LIKE 'Libro MARC ${RUN} 10_' AND deleted_at IS NULL`)).toBe('4');
    expect(dbQuery(`SELECT isbn13 IS NOT NULL FROM libri WHERE titolo = 'Libro MARC ${RUN} 102'`)).toBe('1');
  });

  test('duplicates: skip leaves books alone, fill completes empty fields, overwrite replaces', async ({ page }) => {
    test.setTimeout(180000);
    await loginAsAdmin(page);
    const file = marcFile(RUN, 3, 'mrc');
    const title1 = `Libro MARC ${RUN} 01`;
    // The librarian edited book 01 after the first import, and book 02 lost its description.
    dbQuery(`UPDATE libri SET titolo='${sqlEscape(title1)} (rivisto)', numero_pagine=999 WHERE titolo='${sqlEscape(title1)}' AND deleted_at IS NULL`);
    dbQuery(`UPDATE libri SET descrizione=NULL, descrizione_plain=NULL, edizione=NULL WHERE titolo='Libro MARC ${RUN} 02' AND deleted_at IS NULL`);

    // skip: the preview flags all three as already in the catalogue; nothing changes
    let upload = await uploadForPreview(page, file, { duplicates: 'skip' });
    expect(upload.preview.every((r) => r.existing_id > 0)).toBe(true);
    await expect(page.locator('#marc-preview-rows')).toContainText('#');
    let result = await confirmImport(page);
    expect([result.imported, result.updated, result.skipped]).toEqual([0, 0, 3]);
    expect(dbQuery(`SELECT COUNT(*) FROM libri WHERE titolo LIKE 'Libro MARC ${RUN} 01%' AND deleted_at IS NULL`)).toBe('1');
    expect(dbQuery(`SELECT IFNULL(edizione,'') FROM libri WHERE titolo='Libro MARC ${RUN} 02'`)).toBe('');

    // fill: book 02 gets its description and edition back; book 01 keeps the librarian's edits
    upload = await uploadForPreview(page, file, { duplicates: 'fill' });
    result = await confirmImport(page);
    expect([result.imported, result.updated, result.skipped]).toEqual([0, 3, 0]);
    expect(dbQuery(`SELECT CONCAT(edizione,'|',descrizione) FROM libri WHERE titolo='Libro MARC ${RUN} 02'`)).toBe(`2. ed.|Descrizione del libro 02 della serie ${RUN}.`);
    expect(dbQuery(`SELECT CONCAT(titolo,'|',numero_pagine) FROM libri WHERE titolo LIKE '${sqlEscape(title1)}%'`)).toBe(`${title1} (rivisto)|999`);

    // overwrite: the record's fields replace the book's
    upload = await uploadForPreview(page, file, { duplicates: 'overwrite' });
    result = await confirmImport(page);
    expect(result.updated).toBe(3);
    expect(dbQuery(`SELECT CONCAT(titolo,'|',numero_pagine) FROM libri WHERE titolo LIKE '${sqlEscape(title1)}%' AND deleted_at IS NULL`)).toBe(`${title1}|121`);
    // still one book per ISBN, one copy each
    expect(dbQuery(`SELECT COUNT(*) FROM libri WHERE titolo LIKE '${sqlEscape(title1)}%' AND deleted_at IS NULL`)).toBe('1');
    expect(dbQuery(`SELECT COUNT(*) FROM copie c JOIN libri l ON l.id=c.libro_id WHERE l.titolo='${sqlEscape(title1)}'`)).toBe('1');

    // overwrite updates the media type too: the DVD record of book 03 (same ISBN,
    // leader/06 g) makes the printed book a DVD; its format is left alone
    const title3 = `Libro MARC ${RUN} 03`;
    const mediaOf3 = () => dbQuery(`SELECT CONCAT(tipo_media,'|',formato) FROM libri WHERE titolo='${sqlEscape(title3)}' AND deleted_at IS NULL`);
    expect(mediaOf3()).toBe('libro|cartaceo');
    upload = await uploadForPreview(page, marcFile(RUN, 1, 'dvd', 3), { duplicates: 'overwrite' });
    expect(upload.preview[0].existing_id).toBeGreaterThan(0);
    result = await confirmImport(page);
    expect([result.imported, result.updated, result.skipped]).toEqual([0, 1, 0]);
    expect(mediaOf3()).toBe('dvd|cartaceo');
  });

  test('a supplier chosen on the import page goes on new and overwritten books, never on skipped ones', async ({ page }) => {
    test.setTimeout(180000);
    await loginAsAdmin(page);
    dbQuery(`INSERT INTO fornitori (nome) VALUES ('Fornitore ${RUN} A'), ('Fornitore ${RUN} B')`);
    const [a, b] = dbQuery(`SELECT id FROM fornitori WHERE nome LIKE 'Fornitore ${RUN} %' ORDER BY nome`).split('\n').map(Number);
    expect(a).toBeGreaterThan(0);
    const file = marcFile(RUN, 2, 'mrc', 501);
    const suppliers = () => dbQuery(`SELECT GROUP_CONCAT(DISTINCT IFNULL(fornitore_id, 'NULL')) FROM libri WHERE titolo IN ('Libro MARC ${RUN} 501', 'Libro MARC ${RUN} 502') AND deleted_at IS NULL`);

    // New books get supplier A
    let upload = await uploadForPreview(page, file, { supplier: a });
    expect(upload.total_rows).toBe(2);
    let result = await confirmImport(page);
    expect(result.imported).toBe(2);
    expect(suppliers()).toBe(String(a));

    // skip: the duplicates keep A although B is chosen
    await uploadForPreview(page, file, { duplicates: 'skip', supplier: b });
    result = await confirmImport(page);
    expect(result.skipped).toBe(2);
    expect(suppliers()).toBe(String(a));

    // fill: only empty fields are completed; the supplier is not one of them
    await uploadForPreview(page, file, { duplicates: 'fill', supplier: b });
    result = await confirmImport(page);
    expect(result.updated).toBe(2);
    expect(suppliers()).toBe(String(a));

    // overwrite: the record replaces the book's data, the supplier included
    await uploadForPreview(page, file, { duplicates: 'overwrite', supplier: b });
    result = await confirmImport(page);
    expect(result.updated).toBe(2);
    expect(suppliers()).toBe(String(b));
  });

  test('a deleted book does not count as a duplicate: its ISBN was cleared', async ({ page }) => {
    test.setTimeout(90000);
    await loginAsAdmin(page);
    const title = `Libro MARC ${RUN} 03`;
    // Delete book 03 through the admin bulk delete (LibriApiController::bulkDelete),
    // the path the book list uses: its soft delete clears the ISBN
    const id = Number(dbQuery(`SELECT id FROM libri WHERE titolo='${sqlEscape(title)}' AND deleted_at IS NULL`));
    expect(id).toBeGreaterThan(0);
    await page.goto(`${BASE}/admin/books`);
    const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    const del = await page.request.post(`${BASE}/api/libri/bulk-delete`, {
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf || '' },
      data: JSON.stringify({ ids: [id] }),
    });
    expect(del.ok(), await del.text()).toBe(true);
    expect(dbQuery(`SELECT CONCAT(deleted_at IS NOT NULL, '|', COALESCE(isbn13, ''), '|', COALESCE(isbn10, ''), '|', COALESCE(ean, '')) FROM libri WHERE id=${id}`)).toBe('1|||');
    const file = marcFile(RUN, 1, 'mrc', 3);
    const upload = await uploadForPreview(page, file, { duplicates: 'skip' });
    expect(upload.preview[0].existing_id).toBeNull();
    const result = await confirmImport(page);
    expect(result.imported).toBe(1);
    expect(dbQuery(`SELECT COUNT(*) FROM libri WHERE titolo='${sqlEscape(title)}' AND deleted_at IS NULL`)).toBe('1');
  });

  test('malformed records do not stop the batch and the error report can be downloaded', async ({ page }) => {
    test.setTimeout(90000);
    await loginAsAdmin(page);
    const file = marcFile(RUN, 6, 'malformed', 201);
    const upload = await uploadForPreview(page, file);
    expect(upload.total_rows).toBe(6);
    expect(upload.unreadable).toBe(2);
    await expect(page.locator('#marc-preview-rows tr[data-preview-row="error"]')).toHaveCount(2);
    const result = await confirmImport(page);
    expect(result.imported).toBe(4);
    expect(result.failed).toBe(2);
    await expect(page.locator('#marc-result-errors')).toContainText('runs past the end');
    const link = page.locator('#marc-errors-link');
    await expect(link).toBeVisible();
    const [download] = await Promise.all([page.waitForEvent('download'), link.click()]);
    const csv = require('fs').readFileSync(await download.path(), 'utf-8');
    expect(csv).toContain('runs past the end of the record');
    expect(csv.split('\n').filter((l) => l.includes('runs past the end')).length).toBe(2);
    expect(dbQuery(`SELECT COUNT(*) FROM libri WHERE titolo LIKE 'Libro MARC ${RUN} 20_' AND deleted_at IS NULL`)).toBe('4');
  });

  test('MARC-8 records are decoded; one in an unsupported character set is reported', async ({ page }) => {
    test.setTimeout(90000);
    await loginAsAdmin(page);
    const file = marcFile(RUN, 3, 'marc8', 301);
    const upload = await uploadForPreview(page, file);
    expect(upload.unreadable).toBe(1);
    await expect(page.locator('#marc-preview-rows')).toContainText(`Città MARC8 ${RUN} 301`);
    const result = await confirmImport(page);
    expect(result.imported).toBe(2);
    expect(dbQuery(`SELECT COUNT(*) FROM libri WHERE titolo = 'Città MARC8 ${RUN} 301' AND deleted_at IS NULL`)).toBe('1');
    expect(dbQuery(`SELECT a.nome FROM libri l JOIN libri_autori la ON la.libro_id=l.id JOIN autori a ON a.id=la.autore_id WHERE l.titolo='Città MARC8 ${RUN} 301'`)).toBe(`Jürgen Müller${RUN}`);
  });

  test('authors and publishers follow the exact-spelling rules of #501', async ({ page }) => {
    test.setTimeout(90000);
    await loginAsAdmin(page);
    // Both spellings already on file: the record's exact one must win
    dbQuery(`INSERT INTO autori (nome, created_at) VALUES ('Jürgen Haffner${RUN}', NOW()), ('Jürgen Häffner${RUN}', NOW())`);
    dbQuery(`INSERT INTO editori (nome, created_at) VALUES ('Ross${RUN}', NOW()), ('Röss${RUN}', NOW())`);
    const file = marcFile(RUN, 1, 'authors', 401);
    await uploadForPreview(page, file);
    const result = await confirmImport(page);
    expect(result.imported).toBe(1);
    expect(dbQuery(`SELECT a.nome FROM libri l JOIN libri_autori la ON la.libro_id=l.id JOIN autori a ON a.id=la.autore_id WHERE l.titolo='Libro esatto ${RUN} 401' COLLATE utf8mb4_bin`)).toBe(`Jürgen Häffner${RUN}`);
    expect(dbQuery(`SELECT e.nome FROM libri l JOIN editori e ON e.id=l.editore_id WHERE l.titolo='Libro esatto ${RUN} 401'`)).toBe(`Röss${RUN}`);
    expect(result.authors_created).toBe(0);
    expect(result.publishers_created).toBe(0);
  });
});
