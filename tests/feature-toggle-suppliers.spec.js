// @ts-check
/**
 * features.suppliers: the supplier register, the book form field, the book
 * record row and the import choice follow the module (Settings → Modules).
 * A book saved while the module is off keeps the supplier it had.
 *
 * Run: /Users/fabio/Documents/GitHub/run-e2e-wt3.sh tests/feature-toggle-suppliers.spec.js --config=tests/playwright.config.js --workers=1
 */
const { test, expect } = require('@playwright/test');
const ft = require('./helpers/feature-toggles');
const mf = require('./helpers/minor-features-090');

test.skip(!ft.configured, 'E2E credentials not configured');

const STAMP = Date.now().toString(36);
const TITLE = `ZZ_FT_SUP_${STAMP}`;
const SUPPLIER = `ZZ_FT_SUP Libreria ${STAMP}`;

test.describe.serial('Feature toggle: suppliers', () => {
  test.describe.configure({ timeout: 180000 });
  /** @type {import('@playwright/test').Page} */
  let page;
  let snapshot;
  let bookId = 0;
  let supplierId = 0;

  test.beforeAll(async ({ browser }) => {
    snapshot = ft.readFeatures(['suppliers']);
    ft.dbQuery(`INSERT INTO fornitori (nome) VALUES (${ft.sqlString(SUPPLIER)})`);
    supplierId = Number(ft.dbQuery(`SELECT MAX(id) FROM fornitori WHERE nome=${ft.sqlString(SUPPLIER)}`));
    bookId = mf.seedBook({ titolo: TITLE, copie_totali: 1, copie_disponibili: 1 });
    ft.dbQuery(`UPDATE libri SET fornitore_id=${supplierId} WHERE id=${bookId}`);
    page = await browser.newPage();
    await ft.loginAdmin(page);
  });

  test.afterAll(async () => {
    await ft.restoreFeatures(snapshot);
    mf.deleteBooks([bookId]);
    ft.dbQuery(`DELETE FROM fornitori WHERE id=${supplierId}`);
    await page?.close();
  });

  test('1. switched off: no Fornitori entry in the sidebar, the register is refused', async () => {
    await ft.setModuleInUi(page, 'suppliers', false);
    await page.goto(`${ft.BASE}/admin/dashboard`);
    await expect(page.locator('a.nav-link[href$="/admin/suppliers"]')).toHaveCount(0);
    await page.goto(`${ft.BASE}/admin/suppliers`);
    await expect(page).toHaveURL(/\/admin\/dashboard/);
    const csrf = await ft.csrfToken(page);
    const res = await page.request.post(`${ft.BASE}/admin/suppliers/create`, { form: { csrf_token: csrf, nome: `${SUPPLIER} refused` }, maxRedirects: 0 });
    expect(res.status()).toBe(302);
    expect(ft.dbQuery(`SELECT COUNT(*) FROM fornitori WHERE nome=${ft.sqlString(SUPPLIER + ' refused')}`)).toBe('0');
  });

  test('2. switched off: neither the book form nor the book record show a supplier', async () => {
    await page.goto(`${ft.BASE}/admin/books/edit/${bookId}`);
    await expect(page.locator('#bookForm')).toBeVisible();
    await expect(page.locator('#fornitore_id')).toHaveCount(0);
    await page.goto(`${ft.BASE}/admin/books/${bookId}`);
    await expect(page.locator('#book-supplier')).toHaveCount(0);
    await page.goto(`${ft.BASE}/admin/books`);
    await expect(page.locator('#fornitore_filter')).toHaveCount(0);
  });

  test('3. switched off: saving the book from the form keeps the supplier it had', async () => {
    await page.goto(`${ft.BASE}/admin/books/edit/${bookId}`);
    await page.fill('#titolo', `${TITLE} edited`);
    await mf.submitBookForm(page);
    expect(ft.dbQuery(`SELECT titolo FROM libri WHERE id=${bookId}`)).toBe(`${TITLE} edited`);
    expect(ft.dbQuery(`SELECT fornitore_id FROM libri WHERE id=${bookId}`)).toBe(String(supplierId));
  });

  test('4. switched off: the CSV and MARC import pages offer no supplier for the batch', async () => {
    await page.goto(`${ft.BASE}/admin/books/import`);
    await expect(page.locator('input[type="file"]').first()).toBeAttached();
    await expect(page.locator('#fornitore_id')).toHaveCount(0);
    await page.goto(`${ft.BASE}/admin/books/import/marc`);
    await expect(page.locator('#fornitore_id')).toHaveCount(0);
  });

  test('5. switched back on: entry, field and record row are back, with the supplier preserved', async () => {
    await ft.setModuleInUi(page, 'suppliers', true);
    await page.goto(`${ft.BASE}/admin/dashboard`);
    await page.locator('a.nav-link[href$="/admin/suppliers"]').click();
    await expect(page).toHaveURL(/\/admin\/suppliers$/);
    await expect(page.locator('body')).toContainText(SUPPLIER);
    await page.goto(`${ft.BASE}/admin/books/edit/${bookId}`);
    await expect(page.locator('#fornitore_id')).toHaveValue(String(supplierId));
    await page.goto(`${ft.BASE}/admin/books/${bookId}`);
    await expect(page.locator('#book-supplier')).toContainText(SUPPLIER);
    await page.goto(`${ft.BASE}/admin/books/import`);
    await expect(page.locator('#fornitore_id')).toBeAttached();
  });
});
