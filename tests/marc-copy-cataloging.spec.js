// E2E: interactive copy cataloguing (/admin/books/copy-cataloging). The SRU
// server searched is this installation's own (/api/sru, the "Questa
// installazione" entry), answered in-process: no external network, so the
// results are deterministic. The source books are made by a MARC import; to
// import them back they are withdrawn after the search, as a library that
// re-catalogues from a copy would have them gone.
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { BASE, ROOT, dbQuery, sqlEscape, loginAsAdmin, marcFile, cleanupRun, uploadForPreview, confirmImport } = require('./helpers/marc-e2e');

const RUN = 'MC' + Date.now().toString(36);
const SKIP = !process.env.E2E_ADMIN_EMAIL || !process.env.E2E_DB_NAME;
let coverFile = null;
let createdCovers = [];
let isbn02 = '';
/** import_logs rows written by this run's copy-cataloguing imports (named after the servers, not the run). */
const importIds = [];

async function openPage(page) {
  await page.goto(`${BASE}/admin/books/copy-cataloging`);
  await expect(page.locator('#copySearchForm')).toBeVisible();
  // Only this installation: never the network
  for (const box of await page.locator('input[name="servers[]"]').all()) {
    if (await box.isChecked()) await box.uncheck();
  }
  await page.locator('input[name="servers[]"][value="local"]').check();
}

async function search(page, index, term) {
  await page.selectOption('#copy_index', index);
  await page.fill('#copy_term', term);
  const answered = page.waitForResponse((r) => r.url().includes('/admin/books/copy-cataloging/search'));
  await page.click('#copySearchBtn');
  return (await answered).json();
}

async function importSelected(page, duplicates = 'skip') {
  await page.check(`#copy_duplicates_${duplicates}`);
  const answered = page.waitForResponse((r) => r.url().includes('/admin/books/copy-cataloging/import'));
  await page.click('#copyImportBtn');
  const json = await (await answered).json();
  if (json && json.import_id) importIds.push(String(json.import_id));
  await expect(page.locator('#copy-import-result')).toBeVisible();
  return json;
}

test.describe.serial('Copy cataloging', () => {
  test.skip(SKIP, 'E2E credentials not set');
  test.afterAll(() => {
    if (importIds.length) {
      dbQuery(`DELETE FROM import_logs WHERE import_id IN (${importIds.map((id) => `'${sqlEscape(id)}'`).join(',')})`);
    }
    cleanupRun(RUN);
    for (const f of [coverFile, ...createdCovers]) if (f && fs.existsSync(f)) fs.unlinkSync(f);
  });

  test('setup: three source books in the catalogue, one with a cover', async ({ page }) => {
    test.setTimeout(90000);
    await loginAsAdmin(page);
    await uploadForPreview(page, marcFile(RUN, 3, 'mrc'));
    expect((await confirmImport(page)).imported).toBe(3);
    coverFile = path.join(ROOT, 'public', 'uploads', 'copertine', `copy-cat-${RUN}.jpg`);
    fs.copyFileSync(path.join(ROOT, 'tests', 'fixtures', 'archive-test-cover.jpg'), coverFile);
    dbQuery(`UPDATE libri SET copertina_url='/uploads/copertine/copy-cat-${RUN}.jpg' WHERE titolo='Libro MARC ${RUN} 02'`);
    isbn02 = dbQuery(`SELECT isbn13 FROM libri WHERE titolo='Libro MARC ${RUN} 02' AND deleted_at IS NULL`);
    expect(isbn02).toMatch(/^97[89]\d{10}$/);
  });

  test('the page offers the configured servers, the national presets and this installation', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`${BASE}/admin/books/copy-cataloging`);
    const names = await page.locator('input[name="servers[]"]').evaluateAll((els) => els.map((e) => e.dataset.serverName));
    expect(names.some((n) => n.includes('Library of Congress'))).toBe(true);
    expect(names.some((n) => n.includes('BnF'))).toBe(true);
    expect(names.some((n) => n.includes('DNB'))).toBe(true);
    await expect(page.locator('input[name="servers[]"][value="local"]')).toHaveCount(1);
    // Reachable from the book list's Import menu
    await page.goto(`${BASE}/admin/books`);
    await page.click('.import-btn');
    await page.click(`a[href$="/admin/books/copy-cataloging"]`);
    await expect(page).toHaveURL(/\/admin\/books\/copy-cataloging$/);
  });

  test('search by title: several records, flagged as already in the catalogue, with the MARC detail', async ({ page }) => {
    await loginAsAdmin(page);
    await openPage(page);
    const json = await search(page, 'title', `Libro MARC ${RUN}`);
    expect(json.success).toBe(true);
    expect(json.results.length).toBe(3);
    await expect(page.locator('#copy-result-rows tr[data-result-row]')).toHaveCount(3);
    await expect(page.locator('#copy-server-report')).toContainText('3');
    await expect(page.locator('#copy-result-rows')).toContainText('Già in catalogo');
    // The detail shows the record as the server sent it, cover in 856 included
    const first = page.locator('#copy-result-rows tr[data-result-row]').filter({ hasText: `Libro MARC ${RUN} 02` });
    await first.locator('[data-detail-toggle]').click();
    const detail = page.locator('#copy-result-rows tr[data-detail-row]:not(.hidden)');
    await expect(detail).toContainText('245');
    await expect(detail).toContainText('856');
    await expect(detail).toContainText(`copy-cat-${RUN}.jpg`);
  });

  test('search by author and by ISBN', async ({ page }) => {
    await loginAsAdmin(page);
    await openPage(page);
    let json = await search(page, 'author', `Autore${RUN} 01`);
    expect(json.results.map((r) => r.titolo)).toEqual([`Libro MARC ${RUN} 01`]);
    json = await search(page, 'isbn', isbn02);
    expect(json.results.length).toBe(1);
    expect(json.results[0].isbn).toBe(isbn02);
    await expect(page.locator('#copy-result-rows tr[data-result-row]')).toHaveCount(1);
    json = await search(page, 'any', `Collana${RUN}`);
    expect(json.results.length).toBe(3);
  });

  test('multi-select import: two records become books, with their authors and the cover', async ({ page }) => {
    test.setTimeout(90000);
    await loginAsAdmin(page);
    await openPage(page);
    await search(page, 'title', `Libro MARC ${RUN}`);
    for (const n of ['02', '03']) {
      await page.locator('#copy-result-rows tr[data-result-row]').filter({ hasText: `Libro MARC ${RUN} ${n}` }).locator('input[data-result-id]').check();
    }
    await expect(page.locator('#copy-selected-count')).toHaveText('2');
    // The two source books leave the catalogue (withdrawn) before the import
    dbQuery(`UPDATE libri SET deleted_at=NOW(), isbn13=NULL, isbn10=NULL, ean=NULL WHERE titolo IN ('Libro MARC ${RUN} 02','Libro MARC ${RUN} 03') AND deleted_at IS NULL`);
    const json = await importSelected(page, 'skip');
    expect([json.imported, json.skipped, json.failed]).toEqual([2, 0, 0]);
    expect(json.covers).toBe(1);
    await expect(page.locator('#copy-import-items li[data-import-item="created"]')).toHaveCount(2);
    expect(dbQuery(`SELECT COUNT(*) FROM libri WHERE titolo IN ('Libro MARC ${RUN} 02','Libro MARC ${RUN} 03') AND deleted_at IS NULL`)).toBe('2');
    expect(dbQuery(`SELECT isbn13 FROM libri WHERE titolo='Libro MARC ${RUN} 02' AND deleted_at IS NULL`)).toBe(isbn02);
    expect(dbQuery(`SELECT a.nome FROM libri l JOIN libri_autori la ON la.libro_id=l.id AND la.ruolo='principale' JOIN autori a ON a.id=la.autore_id WHERE l.titolo='Libro MARC ${RUN} 02' AND l.deleted_at IS NULL`)).toBe(`Mario Autore${RUN} 02`);
    const cover = dbQuery(`SELECT copertina_url FROM libri WHERE titolo='Libro MARC ${RUN} 02' AND deleted_at IS NULL`);
    expect(cover).toMatch(/^\/uploads\/copertine\/copertina_/);
    createdCovers.push(path.join(ROOT, 'public', cover));
    expect(fs.existsSync(path.join(ROOT, 'public', cover))).toBe(true);
    // The run is in the import history
    expect(dbQuery(`SELECT CONCAT(import_type,':',imported) FROM import_logs WHERE file_name LIKE 'Copy cataloging:%' ORDER BY id DESC LIMIT 1`)).toBe('marc:2');
    // The link opens the new book
    await page.locator('#copy-import-items a').first().click();
    await expect(page).toHaveURL(/\/admin\/books\/\d+$/);
  });

  test('importing again: skip leaves the copies alone, overwrite updates them', async ({ page }) => {
    test.setTimeout(90000);
    await loginAsAdmin(page);
    await openPage(page);
    await search(page, 'title', `Libro MARC ${RUN}`);
    await page.locator('#copy-select-all').check();
    await expect(page.locator('#copy-selected-count')).toHaveText('3');
    let json = await importSelected(page, 'skip');
    expect([json.imported, json.skipped]).toEqual([0, 3]);
    dbQuery(`UPDATE libri SET numero_pagine=1 WHERE titolo='Libro MARC ${RUN} 01' AND deleted_at IS NULL`);
    json = await importSelected(page, 'overwrite');
    expect(json.updated).toBe(3);
    expect(dbQuery(`SELECT numero_pagine FROM libri WHERE titolo='Libro MARC ${RUN} 01' AND deleted_at IS NULL`)).toBe('121');
    expect(dbQuery(`SELECT COUNT(*) FROM libri WHERE titolo LIKE 'Libro MARC ${RUN} %' AND deleted_at IS NULL`)).toBe('3');
  });

  test('a server that cannot be reached is reported without stopping the others', async ({ page }) => {
    await loginAsAdmin(page);
    await openPage(page);
    // A configured server on a refused address (the SSRF guard blocks it)
    const original = dbQuery("SELECT setting_value FROM plugin_settings ps JOIN plugins p ON p.id=ps.plugin_id WHERE p.name='z39-server' AND ps.setting_key='servers'");
    const broken = JSON.stringify([{ name: `Rotto${RUN}`, url: 'http://127.0.0.1:9/sru', version: '1.1', syntax: 'marcxml', enabled: true, indexes: { isbn: 'bath.isbn' } }]);
    dbQuery(`UPDATE plugin_settings ps JOIN plugins p ON p.id=ps.plugin_id SET ps.setting_value='${sqlEscape(broken)}' WHERE p.name='z39-server' AND ps.setting_key='servers'`);
    try {
      await openPage(page);
      await page.locator(`input[name="servers[]"][data-server-name="Rotto${RUN}"]`).check();
      const json = await search(page, 'title', `Libro MARC ${RUN}`);
      const report = Object.fromEntries(json.servers.map((s) => [s.key, s]));
      expect(report['cfg:0'].error).toBeTruthy();
      expect(report.local.count).toBe(3);
      await expect(page.locator('#copy-server-report')).toContainText(`Rotto${RUN}`);
      await expect(page.locator('#copy-result-rows tr[data-result-row]')).toHaveCount(3);
    } finally {
      dbQuery(`UPDATE plugin_settings ps JOIN plugins p ON p.id=ps.plugin_id SET ps.setting_value='${sqlEscape(original)}' WHERE p.name='z39-server' AND ps.setting_key='servers'`);
    }
  });
});
