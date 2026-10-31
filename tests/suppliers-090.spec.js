// @ts-check
// Suppliers (0.9.0): CRUD from the admin pages, assignment in the book form,
// the book-list filter and "delete supplier → books keep existing, link NULL".
const { test, expect } = require('@playwright/test');

// 0.9.0 optional modules (Settings → Modules / Loans): this suite tests
// suppliers, so it switches it on for its run and puts back what was there.
const featureToggles = require('./helpers/feature-toggles');
let featureSnapshot;
test.beforeAll(async () => { featureSnapshot = await featureToggles.enableFeatures(['suppliers']); });
test.afterAll(async () => { await featureToggles.restoreFeatures(featureSnapshot); });
const h = require('./helpers/minor-features-090');

test.skip(!h.configured, 'E2E credentials not configured');

const STAMP = Date.now().toString(36);
const SUPPLIER = `E2E Fornitore ${STAMP}`;
const SUPPLIER_RENAMED = `E2E Fornitore Rinominato ${STAMP}`;
const OTHER_SUPPLIER = `E2E Altro Fornitore ${STAMP}`;
const BOOK_WITH = `E2E Libro fornito ${STAMP}`;
const BOOK_WITHOUT = `E2E Libro senza fornitore ${STAMP}`;

test.describe.serial('Suppliers (fornitori)', () => {
  let context;
  let page;
  let supplierId = 0;
  let otherSupplierId = 0;
  let bookId = 0;
  let otherBookId = 0;

  test.beforeAll(async ({ browser }) => {
    context = await browser.newContext();
    page = await context.newPage();
    await h.loginAsAdmin(page);
  });

  test.afterAll(async () => {
    h.deleteBooks([bookId, otherBookId]);
    try { h.dbQuery(`DELETE FROM fornitori WHERE nome LIKE 'E2E %${STAMP}'`); } catch (_) { /* cleanup */ }
    await context?.close();
  });

  test('1. A librarian creates a supplier from the Suppliers page', async () => {
    await page.goto(`${h.BASE}/admin/suppliers`);
    await page.getByRole('link', { name: /Nuovo Fornitore/ }).first().click();
    await expect(page.locator('#form-fornitore')).toBeVisible();
    await page.fill('#nome', SUPPLIER);
    await page.fill('#partita_iva', 'IT01234567890');
    await page.fill('#email', 'ordini@example.org');
    await page.fill('#sito_web', 'www.example.org');
    await Promise.all([page.waitForURL(/\/admin\/suppliers$/), page.click('#form-fornitore button[type="submit"]')]);

    const row = h.dbQuery(`SELECT id, partita_iva, email, sito_web FROM fornitori WHERE nome = ${h.sqlString(SUPPLIER)}`);
    const [id, piva, email, site] = row.split('\t');
    supplierId = parseInt(id, 10);
    expect(supplierId).toBeGreaterThan(0);
    expect([piva, email, site]).toEqual(['IT01234567890', 'ordini@example.org', 'https://www.example.org']);
    await expect(page.locator('[data-supplier-row]', { hasText: SUPPLIER })).toBeVisible();
    // A second supplier, to prove the filter is selective.
    await page.goto(`${h.BASE}/admin/suppliers/create`);
    await page.fill('#nome', OTHER_SUPPLIER);
    await Promise.all([page.waitForURL(/\/admin\/suppliers$/), page.click('#form-fornitore button[type="submit"]')]);
    otherSupplierId = parseInt(h.dbQuery(`SELECT id FROM fornitori WHERE nome = ${h.sqlString(OTHER_SUPPLIER)}`), 10);
    expect(otherSupplierId).toBeGreaterThan(0);
  });

  test('2. A blank name or an invalid website is refused and the supplier can be edited', async () => {
    await page.goto(`${h.BASE}/admin/suppliers/edit/${supplierId}`);
    await expect(page.locator('#nome')).toHaveValue(SUPPLIER);
    // Spaces pass the browser "required" check; the server must still refuse.
    await page.fill('#nome', '   ');
    await Promise.all([page.waitForURL(new RegExp(`/admin/suppliers/edit/${supplierId}$`)), page.click('#form-fornitore button[type="submit"]')]);
    await expect(page.getByText('Il nome del fornitore è obbligatorio.')).toBeVisible();
    expect(h.dbQuery(`SELECT nome FROM fornitori WHERE id = ${supplierId}`)).toBe(SUPPLIER);

    // A website that is not a URL is refused with a message, nothing is saved
    // (it used to be silently stored as NULL under a success message).
    await page.fill('#nome', SUPPLIER);
    await page.fill('#referente', 'Non salvato');
    await page.fill('#sito_web', 'not a url');
    await Promise.all([page.waitForURL(new RegExp(`/admin/suppliers/edit/${supplierId}$`)), page.click('#form-fornitore button[type="submit"]')]);
    await expect(page.getByText('Il sito web del fornitore non è un indirizzo valido')).toBeVisible();
    expect(h.dbQuery(`SELECT CONCAT(IFNULL(referente, 'NULL'), '|', IFNULL(sito_web, 'NULL')) FROM fornitori WHERE id = ${supplierId}`)).toBe('NULL|https://www.example.org');
    // The refused form gives back what was typed, so nothing has to be re-entered.
    await expect(page.locator('#referente')).toHaveValue('Non salvato');
    await expect(page.locator('#sito_web')).toHaveValue('not a url');

    await page.fill('#nome', SUPPLIER_RENAMED);
    await page.fill('#referente', 'Giulia Bianchi');
    await page.fill('#sito_web', 'https://www.example.org');
    await Promise.all([page.waitForURL(/\/admin\/suppliers$/), page.click('#form-fornitore button[type="submit"]')]);
    expect(h.dbQuery(`SELECT CONCAT(nome, '|', referente) FROM fornitori WHERE id = ${supplierId}`)).toBe(`${SUPPLIER_RENAMED}|Giulia Bianchi`);
    await expect(page.locator('[data-supplier-row]', { hasText: SUPPLIER_RENAMED })).toContainText('Giulia Bianchi');
  });

  test('3. The book form assigns the supplier and the book page shows it', async () => {
    test.setTimeout(90000);
    await page.goto(`${h.BASE}/admin/books/create`);
    await expect(page.locator('#bookForm')).toBeVisible({ timeout: 15000 });
    await page.fill('#titolo', BOOK_WITH);
    await page.selectOption('#fornitore_id', String(supplierId));
    await h.submitBookForm(page);
    await expect.poll(() => h.dbQuery(`SELECT COUNT(*) FROM libri WHERE titolo = ${h.sqlString(BOOK_WITH)} AND deleted_at IS NULL`), { timeout: 30000 }).toBe('1');
    bookId = parseInt(h.dbQuery(`SELECT id FROM libri WHERE titolo = ${h.sqlString(BOOK_WITH)} AND deleted_at IS NULL`), 10);
    expect(h.dbQuery(`SELECT fornitore_id FROM libri WHERE id = ${bookId}`)).toBe(String(supplierId));

    // A second book without a supplier.
    await page.goto(`${h.BASE}/admin/books/create`);
    await expect(page.locator('#bookForm')).toBeVisible({ timeout: 15000 });
    await page.fill('#titolo', BOOK_WITHOUT);
    await h.submitBookForm(page);
    await expect.poll(() => h.dbQuery(`SELECT COUNT(*) FROM libri WHERE titolo = ${h.sqlString(BOOK_WITHOUT)} AND deleted_at IS NULL`), { timeout: 30000 }).toBe('1');
    otherBookId = parseInt(h.dbQuery(`SELECT id FROM libri WHERE titolo = ${h.sqlString(BOOK_WITHOUT)} AND deleted_at IS NULL`), 10);
    expect(h.dbQuery(`SELECT IFNULL(fornitore_id, 'NULL') FROM libri WHERE id = ${otherBookId}`)).toBe('NULL');

    await page.goto(`${h.BASE}/admin/books/${bookId}`);
    await expect(page.locator('#book-supplier')).toHaveText(SUPPLIER_RENAMED);
    await page.goto(`${h.BASE}/admin/books/edit/${bookId}`);
    await expect(page.locator('#fornitore_id')).toHaveValue(String(supplierId));
  });

  test('4. The book list filters by supplier', async () => {
    await page.goto(`${h.BASE}/admin/books`);
    await page.click('#toggle-advanced');
    await page.selectOption('#fornitore_filter', String(supplierId));
    await page.fill('#search_text', `E2E Libro`);
    const rows = page.locator('#libri-table tbody tr');
    await expect(rows.filter({ hasText: BOOK_WITH })).toHaveCount(1, { timeout: 15000 });
    await expect(rows.filter({ hasText: BOOK_WITHOUT })).toHaveCount(0);
    await expect(page.locator('#active-filters')).toContainText(SUPPLIER_RENAMED);

    await page.selectOption('#fornitore_filter', String(otherSupplierId));
    await expect(rows.filter({ hasText: BOOK_WITH })).toHaveCount(0, { timeout: 15000 });

    // The "books of this supplier" shortcut on the suppliers list lands on the same filter.
    await page.goto(`${h.BASE}/admin/suppliers`);
    await expect(page.locator('[data-supplier-row]', { hasText: SUPPLIER_RENAMED }).locator('[data-supplier-count]')).toHaveText('1');
    await page.locator('[data-supplier-row]', { hasText: SUPPLIER_RENAMED }).getByRole('link', { name: 'Libri di questo fornitore' }).click();
    await expect(page.locator('#fornitore_filter')).toHaveValue(String(supplierId));
    await expect(page.locator('#libri-table tbody tr').filter({ hasText: BOOK_WITH })).toHaveCount(1, { timeout: 15000 });
  });

  test('5. Deleting a supplier keeps its books and clears their supplier', async () => {
    await page.goto(`${h.BASE}/admin/suppliers`);
    const row = page.locator('[data-supplier-row]', { hasText: SUPPLIER_RENAMED });
    await row.locator('.supplier-delete-form button[type="submit"]').click();
    await expect(page.locator('.swal2-popup')).toContainText('1 libri collegati');
    await Promise.all([page.waitForURL(/\/admin\/suppliers$/), page.locator('.swal2-confirm').click()]);

    expect(h.dbQuery(`SELECT COUNT(*) FROM fornitori WHERE id = ${supplierId}`)).toBe('0');
    expect(h.dbQuery(`SELECT CONCAT(IFNULL(fornitore_id, 'NULL'), '|', IF(deleted_at IS NULL, 'live', 'gone')) FROM libri WHERE id = ${bookId}`)).toBe('NULL|live');
    await expect(page.locator('[data-supplier-row]', { hasText: SUPPLIER_RENAMED })).toHaveCount(0);

    await page.goto(`${h.BASE}/admin/books/edit/${bookId}`);
    await expect(page.locator('#fornitore_id')).toHaveValue('');
    await page.goto(`${h.BASE}/admin/books/${bookId}`);
    await expect(page.locator('#book-supplier')).toHaveCount(0);
  });

  test('6. A supplier name with markup is shown literally, never executed', async () => {
    const XSS = `E2E <img src=x onerror=window.__xss=1> ${STAMP}`;
    await page.goto(`${h.BASE}/admin/suppliers/create`);
    await page.fill('#nome', XSS);
    await Promise.all([page.waitForURL(/\/admin\/suppliers$/), page.click('#form-fornitore button[type="submit"]')]);
    expect(h.dbQuery(`SELECT COUNT(*) FROM fornitori WHERE nome = ${h.sqlString(XSS)}`)).toBe('1');
    const row = page.locator('[data-supplier-row]', { hasText: XSS });
    await expect(row).toHaveCount(1);
    await row.locator('.supplier-delete-form button[type="submit"]').click();
    const popup = page.locator('.swal2-popup');
    await expect(popup).toBeVisible();
    await expect(popup).toContainText(XSS);
    await expect(popup.locator('img[src="x"]')).toHaveCount(0);
    expect(await page.evaluate(() => window.__xss)).toBeUndefined();
    await page.locator('.swal2-cancel').click();
    // The book form select and the list filter show it as text too.
    await page.goto(`${h.BASE}/admin/books`);
    await expect(page.locator('#fornitore_filter option', { hasText: XSS })).toHaveCount(1);
    expect(await page.evaluate(() => window.__xss)).toBeUndefined();
  });
});
