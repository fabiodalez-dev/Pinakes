// @ts-check
// The book list, the book page and the book form, opened on a phone (iPhone
// 13, 390 px): nothing scrolls sideways, the row actions of the list sit inside
// the table and a long title reads in full, the copies and loan history tables
// show the borrower, the copy actions and the due date without scrolling, the
// editor toolbar shows every button, the remove cross of a name chip can be
// seen, the chip search field stays in its box, and a book whose cover is gone
// shows no empty "current cover" card or "Rimuovi" link.
//
// Run: /tmp/run-e2e.sh tests/mobile-admin-books-layout.spec.js --config=tests/playwright.config.js --workers=1
const { test, expect, devices } = require('@playwright/test');
const F = require('./helpers/circulation-fixtures');

test.skip(!F.CONFIGURED, 'E2E credentials not configured');
test.use({ ...devices['iPhone 13'], defaultBrowserType: 'chromium' });

const TAG = `MOBBK${String(Date.now()).slice(-6)}`;
const LONG_TITLE_TAIL = 'un titolo abbastanza lungo da occupare più di due righe nella colonna informazioni del telefono';
// No hyphen or space to break at, as a real upload name: copertina_<uniqid>.jpg
const MISSING_COVER = `/uploads/copertine/copertina_${TAG.toLowerCase()}a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8.45883099.jpg`;

/** Blocks that scroll sideways, and how wide the page is, measured in the page. */
async function sideways(page) {
  return page.evaluate(() => {
    const out = [];
    for (const el of document.querySelectorAll('main *')) {
      const r = el.getBoundingClientRect();
      if (!r.width || !r.height || el.closest('.tox')) continue; // TinyMCE's own internals
      const o = getComputedStyle(el).overflowX;
      if ((o === 'auto' || o === 'scroll') && el.clientWidth > 100 && el.scrollWidth > el.clientWidth + 4) {
        out.push(`${el.id ? '#' + el.id : el.tagName.toLowerCase() + '.' + String(el.className).split(/\s+/).slice(0, 3).join('.')} ${el.clientWidth}/${el.scrollWidth}`);
      }
    }
    return { pageWidth: document.documentElement.scrollWidth, vw: window.innerWidth, blocks: out };
  });
}

async function expectNoSideways(page) {
  const d = await sideways(page);
  expect(d.pageWidth, 'page wider than the phone').toBeLessThanOrEqual(d.vw);
  expect(d.blocks, 'blocks that scroll sideways').toEqual([]);
}

/** The element's box lies inside the viewport (left and right edge) and is shown. */
async function expectOnScreen(locator, what) {
  await expect(locator, what).toBeVisible();
  const box = await locator.boundingBox();
  const vw = locator.page().viewportSize()?.width || 390;
  expect(box && box.x >= 0 && box.x + box.width <= vw + 0.5, `${what} inside the screen (${JSON.stringify(box)})`).toBe(true);
}

test.describe.serial('book pages on a phone', () => {
  let book;
  let bareBook;
  let patron;
  let authorId = 0;
  let publisherId = 0;

  test.beforeAll(async () => {
    book = F.createTitle(TAG, 'libro', 2);
    book.title = `${book.title} ${LONG_TITLE_TAIL}`;
    F.dbQuery(`UPDATE libri SET titolo=${F.sqlStr(book.title)}, search_index=${F.sqlStr(book.title)} WHERE id=${book.id} AND deleted_at IS NULL`);
    bareBook = F.createTitle(`${TAG}B`, 'libro', 1);
    patron = F.createPatron(TAG);
    F.dbQuery(`INSERT INTO autori (nome) VALUES ('${TAG} Autrice Mobile')`);
    authorId = F.dbInt(`SELECT id FROM autori WHERE nome='${TAG} Autrice Mobile'`);
    F.dbQuery(`INSERT INTO editori (nome) VALUES ('${TAG} Editore Mobile')`);
    publisherId = F.dbInt(`SELECT id FROM editori WHERE nome='${TAG} Editore Mobile'`);
    F.dbQuery(`INSERT INTO libri_autori (libro_id, autore_id, ruolo, ordine_credito) VALUES (${book.id}, ${authorId}, 'principale', 1)`);
    F.dbQuery(`UPDATE libri SET editore_id=${publisherId}, copertina_url='${MISSING_COVER}', copie_disponibili=1, stato='prestato' WHERE id=${book.id} AND deleted_at IS NULL`);
    // An active loan on the first copy: borrower, due date and history row.
    F.dbQuery(`UPDATE copie SET stato='prestato' WHERE id=${book.copyIds[0]}`);
    F.dbQuery(`INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo, note) VALUES (${book.id}, ${book.copyIds[0]}, ${patron.id}, DATE_SUB(CURDATE(), INTERVAL 3 DAY), DATE_ADD(CURDATE(), INTERVAL 27 DAY), 'in_corso', 'diretto', 1, '${TAG} nota')`);
    // A change-log entry with a long unbreakable value (the cover path).
    const adminId = F.dbInt(`SELECT id FROM utenti WHERE email='${process.env.E2E_ADMIN_EMAIL}'`);
    F.dbQuery(`INSERT INTO log_modifiche (tabella, record_id, azione, dati_precedenti, dati_nuovi, utente_id) VALUES ('libri', ${book.id}, 'aggiornamento', '{"copertina_url":""}', '{"copertina_url":"${MISSING_COVER}","_activity":{"type":"edit","event":"book.updated","source":"manual"}}', ${adminId || 'NULL'})`);
  });

  test.afterAll(async () => {
    try { F.dbQuery(`DELETE FROM log_modifiche WHERE tabella='libri' AND record_id IN (${book.id}, ${bareBook.id})`); } catch { /* */ }
    try { F.dbQuery(`DELETE FROM libri_autori WHERE libro_id=${book.id}`); } catch { /* */ }
    F.deleteTitles([book?.id, bareBook?.id]);
    try { F.dbQuery(`DELETE FROM autori WHERE id=${authorId}`); } catch { /* */ }
    try { F.dbQuery(`DELETE FROM editori WHERE id=${publisherId}`); } catch { /* */ }
    if (patron) F.deletePatrons([patron.id]);
  });

  for (const [label, path] of [['books list', '/admin/books'], ['catalogue cards with no selection', '/admin/books/catalogue-cards']]) {
    test(`${label}: the row actions sit inside the table and a long title reads in full`, async ({ page }) => {
      await F.loginAsAdmin(page);
      await page.goto(path);
      await page.locator('#search_text').fill(TAG);
      const row = page.locator('#libri-table tbody tr', { hasText: LONG_TITLE_TAIL });
      await expect(row).toBeVisible({ timeout: 15000 });
      await expectNoSideways(page);

      const table = await page.locator('#libri-table').boundingBox();
      for (const name of ['Visualizza', 'Modifica', 'Elimina']) {
        const button = row.getByRole(name === 'Elimina' ? 'button' : 'link', { name, exact: true });
        await expectOnScreen(button, `${name} action`);
        const box = await button.boundingBox();
        expect(box && table && box.x + box.width <= table.x + table.width + 0.5, `${name} inside the table border`).toBe(true);
        expect(box && box.width >= 34 && box.height >= 34, `${name} keeps the row action size of the admin lists (${JSON.stringify(box)})`).toBe(true);
      }
      const title = row.locator('td:nth-child(5) a').first();
      const cut = await title.evaluate((a) => a.scrollHeight > a.clientHeight + 1);
      expect(cut, 'the title is cut off').toBe(false);
    });
  }

  test('book page: copies, loan history and change log fit the phone', async ({ page }) => {
    await F.loginAsAdmin(page);
    const response = await page.goto(`/admin/books/${book.id}`, { waitUntil: 'networkidle' });
    expect(response?.status()).toBe(200);
    await expectNoSideways(page);

    const borrowed = page.locator('table tbody tr', { hasText: F.copyCodeOf(book.copyIds[0]) }).first();
    await expectOnScreen(borrowed.getByText(patron.email), 'borrower of the copy');
    await expectOnScreen(borrowed.getByRole('link', { name: 'Stampa etichetta' }), 'print label action');
    const free = page.locator('table tbody tr', { hasText: F.copyCodeOf(book.copyIds[1]) }).first();
    await expectOnScreen(free.getByRole('button', { name: 'Modifica stato' }), 'edit copy action');
    await expect(free.locator('td').nth(3)).toHaveAttribute('data-label', /Utente/i);

    const history = page.locator('.card', { has: page.getByRole('heading', { name: /Storico Prestiti/ }) });
    const loan = history.locator('tbody tr', { hasText: `Circ ${TAG}` }).first();
    const due = loan.locator('td').nth(2);
    await expect(due).toHaveAttribute('data-label', /Scadenza/i);
    await expectOnScreen(due.locator('span'), 'loan due date');

    const change = page.locator('#activity-feed .pk-activity-change__value', { hasText: MISSING_COVER }).first();
    await expectOnScreen(change, 'long change-log value');
  });

  test('book form: whole editor toolbar, visible chip cross, field in its box, no empty cover card', async ({ page }) => {
    await F.loginAsAdmin(page);
    await page.goto(`/admin/books/edit/${book.id}`, { waitUntil: 'networkidle' });
    const editor = page.locator('.tox-tinymce');
    await expect(editor).toBeVisible({ timeout: 15000 });
    await expectNoSideways(page);

    // Every toolbar button is shown inside the editor box, none hidden behind a sideways scroll.
    const toolbar = await editor.evaluate((ed) => {
      const box = ed.getBoundingClientRect();
      return [...ed.querySelectorAll('.tox-toolbar__group > button, .tox-toolbar__group > .tox-split-button')]
        .map((b) => ({ name: b.getAttribute('aria-label') || b.textContent.trim(), r: b.getBoundingClientRect() }))
        .filter((b) => b.r.width === 0 || b.r.left < box.left - 0.5 || b.r.right > box.right + 0.5)
        .map((b) => b.name);
    });
    const count = await editor.locator('.tox-toolbar__group > button, .tox-toolbar__group > .tox-split-button').count();
    expect(count, 'toolbar buttons rendered').toBeGreaterThanOrEqual(12);
    expect(toolbar, 'toolbar buttons out of sight').toEqual([]);

    for (const name of [`${TAG} Autrice Mobile`, `${TAG} Editore Mobile`]) {
      const chip = page.locator('.choices__list--multiple .choices__item', { hasText: name }).first();
      await expect(chip, `${name} chip`).toBeVisible();
      const cross = chip.locator('.choices__button');
      await expectOnScreen(cross, `${name} remove cross`);
      // Contrast between the cross (its icon's opaque pixels, at its opacity) and the chip.
      const ratio = await cross.evaluate(async (btn) => {
        const lum = ([r, g, b]) => { const c = [r, g, b].map((v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; }); return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2]; };
        const rgb = (s) => (s.match(/[\d.]+/g) || []).slice(0, 3).map(Number);
        let bgEl = btn.parentElement; let bg = getComputedStyle(bgEl).backgroundColor;
        while (bgEl && /rgba\(0, 0, 0, 0\)|transparent/.test(bg)) { bgEl = bgEl.parentElement; bg = bgEl ? getComputedStyle(bgEl).backgroundColor : 'rgb(255,255,255)'; }
        const url = (getComputedStyle(btn).backgroundImage.match(/url\("?(.*?)"?\)$/) || [])[1];
        if (!url) return 0;
        const img = new Image(); img.src = url; await img.decode();
        const canvas = document.createElement('canvas'); canvas.width = 21; canvas.height = 21;
        const ctx = canvas.getContext('2d'); ctx.drawImage(img, 0, 0, 21, 21);
        const px = ctx.getImageData(0, 0, 21, 21).data; let n = 0; const sum = [0, 0, 0];
        for (let i = 0; i < px.length; i += 4) if (px[i + 3] > 200) { n++; sum[0] += px[i]; sum[1] += px[i + 1]; sum[2] += px[i + 2]; }
        if (!n) return 0;
        const op = parseFloat(getComputedStyle(btn).opacity); const back = rgb(bg);
        const ink = sum.map((v, i) => (v / n) * op + back[i] * (1 - op));
        const [a, b] = [lum(ink), lum(back)].sort((x, y) => y - x);
        return (a + 0.05) / (b + 0.05);
      });
      expect(ratio, `${name} cross contrast`).toBeGreaterThanOrEqual(3);
    }

    for (const input of await page.locator('.choices__inner .choices__input--cloned').all()) {
      if (!(await input.isVisible())) continue;
      const inside = await input.evaluate((el) => {
        // Inside the box's padding, as the chips above it, not over its edge.
        const inner = el.closest('.choices__inner'); const cs = getComputedStyle(inner);
        const box = inner.getBoundingClientRect(); const r = el.getBoundingClientRect();
        const left = box.left + parseFloat(cs.borderLeftWidth) + parseFloat(cs.paddingLeft);
        const right = box.right - parseFloat(cs.borderRightWidth) - parseFloat(cs.paddingRight);
        return r.left >= left - 0.5 && r.right <= right + 0.5;
      });
      expect(inside, 'chip search field inside its box').toBe(true);
    }

    // The cover file is gone: no "current cover" card and no "Rimuovi" for an empty frame.
    await expect(page.getByText('Copertina Attuale', { exact: true })).toBeHidden();
    await expect(page.locator('#cover-preview-container').getByRole('button', { name: 'Rimuovi' })).toBeHidden();
  });

  test('book form of a book with no cover: no empty "current cover" card', async ({ page }) => {
    await F.loginAsAdmin(page);
    await page.goto(`/admin/books/edit/${bareBook.id}`, { waitUntil: 'networkidle' });
    await expect(page.locator('#titolo')).toBeVisible();
    await expect(page.getByText('Copertina Attuale', { exact: true })).toHaveCount(0);
    await expect(page.getByText('Nessuna copertina caricata', { exact: true })).toHaveCount(0);
    await expect(page.locator('#cover-preview-container').getByRole('button', { name: 'Rimuovi' })).toHaveCount(0);
  });
});
