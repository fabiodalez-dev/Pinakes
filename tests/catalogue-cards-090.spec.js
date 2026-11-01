// @ts-check
// ISBD catalogue cards (0.9.0): 125 × 75 mm PDF from the book page and from a
// selection in the book list, with the ISBD content; soft-deleted books are
// never printed.
const { test, expect } = require('@playwright/test');
const h = require('./helpers/minor-features-090');

test.skip(!h.configured, 'E2E credentials not configured');

const STAMP = Date.now().toString(36);
const TOKEN = `cardz${STAMP}`;
const AUTHOR = `Ottavia Schedari${STAMP}`;
const TITLE_A = `Schedario A ${TOKEN}`;
const TITLE_B = `Schedario B ${TOKEN}`;
const TITLE_DELETED = `Schedario cancellato ${TOKEN}`;

/** 125 × 75 mm landscape = 354.33 × 212.60 pt, on every page of the PDF. */
function expectCardSize(buffer) {
  const boxes = [...buffer.toString('latin1').matchAll(/\/MediaBox\s*\[\s*0(?:\.0+)?\s+0(?:\.0+)?\s+([\d.]+)\s+([\d.]+)\s*\]/g)];
  expect(boxes.length, 'the PDF declares a MediaBox').toBeGreaterThan(0);
  for (const [box, width, height] of boxes) {
    expect(Math.abs(parseFloat(width) - 354.33), `${box} is 125 mm wide`).toBeLessThan(0.1);
    expect(Math.abs(parseFloat(height) - 212.6), `${box} is 75 mm high`).toBeLessThan(0.1);
  }
}

test.describe.serial('Catalogue cards (schede catalografiche)', () => {
  let context;
  let page;
  let authorId = 0;
  let publisherId = 0;
  let bookA = 0;
  let bookB = 0;
  let deletedBook = 0;

  test.beforeAll(async ({ browser }) => {
    // Setup: an author, a publisher and three records (one soft-deleted).
    h.dbQuery(`INSERT INTO autori (nome) VALUES (${h.sqlString(AUTHOR)})`);
    authorId = parseInt(h.dbQuery(`SELECT MAX(id) FROM autori WHERE nome = ${h.sqlString(AUTHOR)}`), 10);
    h.dbQuery(`INSERT INTO editori (nome) VALUES (${h.sqlString('Editrice Schede ' + STAMP)})`);
    publisherId = parseInt(h.dbQuery(`SELECT MAX(id) FROM editori WHERE nome = ${h.sqlString('Editrice Schede ' + STAMP)}`), 10);
    bookA = h.seedBook({
      titolo: TITLE_A, sottotitolo: 'una prova', editore_id: String(publisherId), luogo_pubblicazione: 'Bologna',
      anno_pubblicazione: '2021', numero_pagine: '312', dimensioni: '22 cm', isbn13: `97888${String(Date.now()).slice(-8)}`,
      parole_chiave: 'Biblioteconomia, Cataloghi', classificazione_dewey: '025.3', search_index: TITLE_A.toLowerCase(),
    });
    bookB = h.seedBook({ titolo: TITLE_B, anno_pubblicazione: '1999', search_index: TITLE_B.toLowerCase() });
    deletedBook = h.seedBook({ titolo: TITLE_DELETED, search_index: TITLE_DELETED.toLowerCase() });
    h.dbQuery(`UPDATE libri SET deleted_at = NOW() WHERE id = ${deletedBook}`);
    h.dbQuery(`INSERT INTO libri_autori (libro_id, autore_id, ruolo, ordine_credito) VALUES (${bookA}, ${authorId}, 'principale', 1)`);

    context = await browser.newContext({ acceptDownloads: true });
    page = await context.newPage();
    await h.loginAsAdmin(page);
  });

  test.afterAll(async () => {
    h.deleteBooks([bookA, bookB, deletedBook]);
    try { h.dbQuery(`DELETE FROM autori WHERE id = ${authorId}`); } catch (_) { /* cleanup */ }
    try { h.dbQuery(`DELETE FROM editori WHERE id = ${publisherId}`); } catch (_) { /* cleanup */ }
    await context?.close();
  });

  test('1. The book page offers a catalogue card that is a 125 × 75 mm PDF', async () => {
    await page.goto(`${h.BASE}/admin/books/${bookA}`);
    const link = page.locator('#catalogue-card-link');
    await expect(link).toBeVisible();
    await expect(link).toHaveAttribute('href', new RegExp(`/admin/books/${bookA}/catalogue-card$`));
    const [popup] = await Promise.all([page.waitForEvent('popup'), link.click()]);
    await popup.close();
    const resp = await page.request.get(`${h.BASE}/admin/books/${bookA}/catalogue-card`);
    expect(resp.status()).toBe(200);
    expect(resp.headers()['content-type']).toContain('application/pdf');
    const pdf = await resp.body();
    expect(pdf.subarray(0, 5).toString()).toBe('%PDF-');
    expect(h.pdfPageCount(pdf)).toBe(1);
    expectCardSize(pdf);
  });

  test('2. The card carries the ISBD description', async () => {
    const pdf = await (await page.request.get(`${h.BASE}/admin/books/${bookA}/catalogue-card`)).body();
    const text = h.pdfText(pdf);
    // Main entry: surname first.
    expect(text).toContain(`Schedari${STAMP}, Ottavia`);
    // Area 1 and the publication area. ISBD punctuation is non-ASCII and
    // the PDF wraps long lines, so check the individual elements.
    for (const part of [TITLE_A, 'una prova', 'Bologna', `Editrice Schede ${STAMP}`, '2021', '312 p.', '22 cm', 'ISBN 97888', 'Biblioteconomia', 'Cataloghi', '025.3']) {
      expect(text, `card contains "${part}"`).toContain(part);
    }
  });

  test('3. Selecting books in the list prints one card per book', async () => {
    await page.goto(`${h.BASE}/admin/books`);
    await page.fill('#search_text', TOKEN);
    const rows = page.locator('#libri-table tbody tr');
    await expect(rows.filter({ hasText: TITLE_A })).toHaveCount(1, { timeout: 15000 });
    await expect(rows.filter({ hasText: TITLE_B })).toHaveCount(1);
    await expect(rows.filter({ hasText: TITLE_DELETED })).toHaveCount(0);
    await rows.filter({ hasText: TITLE_A }).locator('.row-select').check();
    await rows.filter({ hasText: TITLE_B }).locator('.row-select').check();
    await expect(page.locator('#bulk-actions-bar')).toBeVisible();

    // The PDF opens in a new tab (headless Chromium turns it into a
    // download), so read the URL from the request the click issues.
    const [request] = await Promise.all([
      context.waitForEvent('request', (r) => r.url().includes('/admin/books/catalogue-cards')),
      page.click('#bulk-catalogue-cards'),
    ]);
    const url = request.url();
    for (const p of context.pages()) { if (p !== page) await p.close().catch(() => {}); }
    expect(url).toMatch(/\/admin\/books\/catalogue-cards\?ids=/);
    const ids = decodeURIComponent(url.split('ids=')[1]).split(',').map(Number).sort();
    expect(ids).toEqual([bookA, bookB].sort());

    const resp = await page.request.get(url);
    expect(resp.headers()['content-type']).toContain('application/pdf');
    const pdf = await resp.body();
    expect(h.pdfPageCount(pdf)).toBe(2);
    expectCardSize(pdf);
    const text = h.pdfText(pdf);
    expect(text).toContain(TITLE_A);
    expect(text).toContain(TITLE_B);
  });

  test('4. A soft-deleted book is never printed', async () => {
    const pdf = await (await page.request.get(`${h.BASE}/admin/books/catalogue-cards?ids=${deletedBook},${bookB}`)).body();
    expect(h.pdfPageCount(pdf)).toBe(1);
    const text = h.pdfText(pdf);
    expect(text).toContain(TITLE_B);
    expect(text).not.toContain(TITLE_DELETED);

    await page.goto(`${h.BASE}/admin/books/${deletedBook}/catalogue-card`);
    await expect(page).toHaveURL(/\/admin\/books$/);
    await expect(page.getByText('Libro non trovato.')).toBeVisible();
  });

  test('5. A selection with nothing printable goes back to the list with a message', async () => {
    await page.goto(`${h.BASE}/admin/books/catalogue-cards?ids=${deletedBook},abc`);
    await expect(page).toHaveURL(/\/admin\/books$/);
    await expect(page.getByText('Seleziona almeno un libro per stampare le schede catalografiche.')).toBeVisible();
  });
  test('6. Selecting more books than one print allows warns instead of silently dropping some', async () => {
    const BULK = `cardbulk${STAMP}`;
    // 205 plain records, more than CatalogueCardController::MAX_BULK (200).
    h.dbQuery(`INSERT INTO libri (titolo, search_index, copie_totali, copie_disponibili)
      SELECT CONCAT('Scheda massiva ', ${h.sqlString(BULK)}, ' ', n), CONCAT('scheda massiva ', ${h.sqlString(BULK)}, ' ', n), 1, 1
      FROM (SELECT a.d + 10 * b.d + 100 * c.d AS n FROM
        (SELECT 0 d UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) a,
        (SELECT 0 d UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) b,
        (SELECT 0 d UNION SELECT 1 UNION SELECT 2) c) t WHERE n < 205`);
    const bulkIds = h.dbQuery(`SELECT id FROM libri WHERE titolo LIKE ${h.sqlString('Scheda massiva ' + BULK + ' %')}`).split('\n').filter(Boolean).map(Number);
    try {
      expect(bulkIds.length).toBe(205);
      await page.goto(`${h.BASE}/admin/books`);
      await page.locator('.dt-length select, .dataTables_length select').first().selectOption('100');
      await page.fill('#search_text', BULK);
      // The search box also reloads the table on "change", i.e. when it loses
      // focus: leave it now and let that reload land before paging.
      const settled = page.waitForResponse((r) => r.url().includes('/api/libri') && r.url().includes(BULK));
      await page.locator('#search_text').press('Tab');
      await settled;
      const rows = page.locator('#libri-table tbody tr .row-select');
      // Tick "select all" on each of the three result pages, as a librarian would.
      for (let pageNo = 1; pageNo <= 3; pageNo++) {
        await expect(rows.first()).toBeVisible({ timeout: 15000 });
        await expect(page.locator('#libri-table tbody tr').filter({ hasNotText: BULK })).toHaveCount(0, { timeout: 15000 });
        if (pageNo === 3) await expect(rows).toHaveCount(5, { timeout: 15000 });
        else await expect(rows).toHaveCount(100, { timeout: 15000 });
        await page.locator('#select-all').check();
        // The bulk actions bar is fixed over the bottom pager once books are
        // selected, so the click goes straight to the pager's "next" button.
        if (pageNo < 3) {
          await page.getByRole('navigation', { name: 'pagination' }).getByRole('link', { name: 'Next' }).dispatchEvent('click');
          await expect(page.locator('.dt-info, .dataTables_info').first()).toContainText(`${pageNo * 100 + 1}`, { timeout: 15000 });
        }
      }
      await expect(page.locator('#selected-count')).toHaveText('205');
      // The PDF opens in a new tab: watch every page of the context, and give
      // a refused print the time a real one would need to start.
      let printed = false;
      const onRequest = (r) => { if (r.url().includes('/admin/books/catalogue-cards')) printed = true; };
      page.context().on('request', onRequest);
      let opened = false;
      page.context().on('page', () => { opened = true; });
      await page.click('#bulk-catalogue-cards');
      await expect(page.locator('.swal2-popup')).toBeVisible();
      await expect(page.locator('.swal2-html-container')).toContainText('200');
      await page.locator('.swal2-confirm').click();
      await page.context().waitForEvent('request', { predicate: (r) => r.url().includes('/admin/books/catalogue-cards'), timeout: 3000 }).catch(() => null);
      page.context().off('request', onRequest);
      expect(printed, 'no PDF request is sent for an oversized selection').toBe(false);
      expect(opened, 'no PDF tab is opened for an oversized selection').toBe(false);
    } finally {
      h.deleteBooks(bulkIds);
    }
  });
});
