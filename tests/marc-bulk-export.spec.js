// E2E: bulk MARC export from the book list (selection like #77 and the whole
// catalogue) in MARC 21 .mrc, MARCXML and UNIMARC, read back with the shared
// MARC library, and re-imported from the UI without creating duplicates.
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { BASE, ROOT, dbQuery, sqlEscape, loginAsAdmin, marcFile, cleanupRun, uploadForPreview, confirmImport } = require('./helpers/marc-e2e');

const RUN = 'MX' + Date.now().toString(36);
const SKIP = !process.env.E2E_ADMIN_EMAIL || !process.env.E2E_DB_NAME;
const inspect = (file) => JSON.parse(execFileSync('php', [path.join(ROOT, 'tests', 'helpers', 'marc-inspect.php'), file], { encoding: 'utf-8' }));
const downloads = {};
let coverFile = null;

async function selectRunBooks(page) {
  await page.goto(`${BASE}/admin/books?keywords=${encodeURIComponent(`Soggetto${RUN}`)}`);
  await expect(page.locator('.row-select')).toHaveCount(3, { timeout: 15000 });
  await page.locator('#select-all').check();
  await expect(page.locator('#selected-count')).toHaveText('3');
}

async function exportSelection(page, format) {
  await selectRunBooks(page);
  await page.click('#bulk-export-marc');
  await page.locator('.swal2-select').selectOption(format);
  const [download] = await Promise.all([page.waitForEvent('download'), page.click('.swal2-confirm')]);
  const file = path.join(fs.mkdtempSync(path.join(require('os').tmpdir(), 'pinakes-export-')), download.suggestedFilename());
  await download.saveAs(file);
  return { file, name: download.suggestedFilename() };
}

test.describe.serial('MARC bulk export', () => {
  test.skip(SKIP, 'E2E credentials not set');
  test.afterAll(() => {
    cleanupRun(RUN);
    if (coverFile && fs.existsSync(coverFile)) fs.unlinkSync(coverFile);
  });

  test('setup: three books imported from MARC, one with a cover', async ({ page }) => {
    test.setTimeout(90000);
    await loginAsAdmin(page);
    await uploadForPreview(page, marcFile(RUN, 3, 'mrc'));
    const result = await confirmImport(page);
    expect(result.imported).toBe(3);
    // A local cover on book 01, as the book form would have saved it
    coverFile = path.join(ROOT, 'public', 'uploads', 'copertine', `marc-export-${RUN}.jpg`);
    fs.mkdirSync(path.dirname(coverFile), { recursive: true });
    fs.copyFileSync(path.join(ROOT, 'tests', 'fixtures', 'archive-test-cover.jpg'), coverFile);
    dbQuery(`UPDATE libri SET copertina_url='/uploads/copertine/marc-export-${RUN}.jpg' WHERE titolo='Libro MARC ${RUN} 01'`);
  });

  test('the selection exports as MARC 21 (.mrc): three UTF-8 records with ISBN and cover', async ({ page }) => {
    await loginAsAdmin(page);
    const { file, name } = await exportSelection(page, 'marc21');
    downloads.marc21 = file;
    expect(name).toMatch(/marc21.*\.mrc$/);
    const read = inspect(file);
    expect(read.container).toBe('iso2709');
    expect(read.errors).toBe(0);
    expect(read.records.map((r) => r.titolo).sort()).toEqual([1, 2, 3].map((i) => `Libro MARC ${RUN} 0${i}`));
    expect(read.records.every((r) => r.flavour === 'marc21' && r.leader9 === 'a' && /^97[89]\d{10}$/.test(r.isbn13))).toBe(true);
    const one = read.records.find((r) => r.titolo === `Libro MARC ${RUN} 01`);
    expect(one.cover).toMatch(new RegExp(`/uploads/copertine/marc-export-${RUN}\\.jpg$`));
    expect(one.autori).toEqual([`Mario Autore${RUN} 01`]);
  });

  test('the selection exports as a MARCXML collection', async ({ page }) => {
    await loginAsAdmin(page);
    const { file, name } = await exportSelection(page, 'marcxml');
    downloads.marcxml = file;
    expect(name).toMatch(/\.xml$/);
    const xml = fs.readFileSync(file, 'utf-8');
    expect(xml.startsWith('<?xml')).toBe(true);
    expect(xml).toContain('<collection');
    expect((xml.match(/<record>/g) || []).length).toBe(3);
    const read = inspect(file);
    expect(read.container).toBe('xml');
    expect(read.records.length).toBe(3);
  });

  test('the selection exports as UNIMARC (.mrc)', async ({ page }) => {
    await loginAsAdmin(page);
    const { file } = await exportSelection(page, 'unimarc');
    downloads.unimarc = file;
    const read = inspect(file);
    expect(read.records.length).toBe(3);
    expect(read.records.every((r) => r.flavour === 'unimarc')).toBe(true);
    expect(read.records.map((r) => r.titolo).sort()).toEqual([1, 2, 3].map((i) => `Libro MARC ${RUN} 0${i}`));
    expect(read.records.find((r) => r.titolo === `Libro MARC ${RUN} 01`).cover).toContain(`marc-export-${RUN}.jpg`);
  });

  test('each export re-imports with no duplicates', async ({ page }) => {
    test.setTimeout(120000);
    await loginAsAdmin(page);
    for (const format of ['marc21', 'marcxml', 'unimarc']) {
      const upload = await uploadForPreview(page, downloads[format]);
      expect(upload.total_rows).toBe(3);
      expect(upload.preview.every((r) => r.existing_id > 0)).toBe(true);
      const result = await confirmImport(page);
      expect([format, result.imported, result.skipped, result.failed]).toEqual([format, 0, 3, 0]);
    }
    expect(dbQuery(`SELECT COUNT(*) FROM libri WHERE titolo LIKE 'Libro MARC ${RUN} %' AND deleted_at IS NULL`)).toBe('3');
  });

  test('an export brings a catalogue back: books, authors and the cover from 856', async ({ page }) => {
    test.setTimeout(90000);
    await loginAsAdmin(page);
    const before = dbQuery(`SELECT CONCAT_WS('|', titolo, sottotitolo, anno_pubblicazione, numero_pagine, collana, classificazione_dewey, isbn13) FROM libri WHERE titolo='Libro MARC ${RUN} 01' AND deleted_at IS NULL`);
    // The book is withdrawn (soft delete clears its identifiers), then the export is imported
    dbQuery(`UPDATE libri SET deleted_at=NOW(), isbn13=NULL, isbn10=NULL, ean=NULL WHERE titolo='Libro MARC ${RUN} 01' AND deleted_at IS NULL`);
    await uploadForPreview(page, downloads.marc21, { covers: true });
    const result = await confirmImport(page);
    expect(result.imported).toBe(1);
    expect(result.skipped).toBe(2);
    expect(result.covers).toBe(1);
    const after = dbQuery(`SELECT CONCAT_WS('|', titolo, sottotitolo, anno_pubblicazione, numero_pagine, collana, classificazione_dewey, isbn13) FROM libri WHERE titolo='Libro MARC ${RUN} 01' AND deleted_at IS NULL`);
    expect(after).toBe(before);
    const cover = dbQuery(`SELECT copertina_url FROM libri WHERE titolo='Libro MARC ${RUN} 01' AND deleted_at IS NULL`);
    expect(cover).toMatch(/^\/uploads\/copertine\/copertina_.+\.jpg$/);
    expect(fs.existsSync(path.join(ROOT, 'public', cover))).toBe(true);
    fs.unlinkSync(path.join(ROOT, 'public', cover));
  });

  test('the whole catalogue exports from the Export menu, without deleted books', async ({ page }) => {
    test.setTimeout(120000);
    await loginAsAdmin(page);
    await page.goto(`${BASE}/admin/books`);
    await page.click('.export-btn');
    const [download] = await Promise.all([page.waitForEvent('download'), page.click('a[data-marc-export="marc21"]')]);
    const file = path.join(fs.mkdtempSync(path.join(require('os').tmpdir(), 'pinakes-export-')), download.suggestedFilename());
    await download.saveAs(file);
    const read = inspect(file);
    expect(read.errors).toBe(0);
    expect(String(read.records.length)).toBe(dbQuery('SELECT COUNT(*) FROM libri WHERE deleted_at IS NULL'));
    // the withdrawn copy of book 01 is not exported, its re-imported twin is: one record
    expect(read.records.filter((r) => r.titolo === `Libro MARC ${RUN} 01`).length).toBe(1);
  });
});
