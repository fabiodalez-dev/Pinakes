// @ts-check
/**
 * features.rda_types: the three RDA selects of the book form, the RDA row of
 * the record and the MARC 336/337/338 overrides follow the module (Settings
 * → Modules). Off, the stored overrides are kept but not applied: exports
 * derive the RDA types from the material, as before 0.9.0.
 *
 * Run: /Users/fabio/Documents/GitHub/run-e2e-wt3.sh tests/feature-toggle-rda-types.spec.js --config=tests/playwright.config.js --workers=1
 */
const { test, expect } = require('@playwright/test');
const ft = require('./helpers/feature-toggles');
const mf = require('./helpers/minor-features-090');

test.skip(!ft.configured, 'E2E credentials not configured');

const TITLE = `ZZ_FT_RDA_${Date.now().toString(36)}`;

/** The $b of the first <datafield tag="336"> of a MARCXML export. */
function contentType(xml) {
  const field = /<(?:marc:)?datafield[^>]*tag="336"[^>]*>([\s\S]*?)<\/(?:marc:)?datafield>/.exec(xml);
  const code = field ? /<(?:marc:)?subfield code="b">([^<]*)</.exec(field[1]) : null;
  return code ? code[1] : null;
}

test.describe.serial('Feature toggle: RDA types', () => {
  test.describe.configure({ timeout: 180000 });
  /** @type {import('@playwright/test').Page} */
  let page;
  let snapshot;
  let bookId = 0;

  test.beforeAll(async ({ browser }) => {
    snapshot = ft.readFeatures(['rda_types', 'marc']);
    await ft.writeFeatures({ marc: '1' });
    bookId = mf.seedBook({ titolo: TITLE, tipo_media: 'libro', copie_totali: 1, copie_disponibili: 1 });
    ft.dbQuery(`UPDATE libri SET rda_content_type='prm', rda_media_type='n', rda_carrier_type='nc' WHERE id=${bookId}`);
    page = await browser.newPage();
    await ft.loginAdmin(page);
  });

  test.afterAll(async () => {
    await ft.restoreFeatures(snapshot);
    mf.deleteBooks([bookId]);
    await page?.close();
  });

  test('1. switched off: no RDA selects in the form, no RDA row in the record', async () => {
    await ft.setModuleInUi(page, 'rda_types', false);
    await page.goto(`${ft.BASE}/admin/books/edit/${bookId}`);
    await expect(page.locator('#bookForm')).toBeVisible();
    await expect(page.locator('#rda-types')).toHaveCount(0);
    await expect(page.locator('#rda_content_type')).toHaveCount(0);
    await page.goto(`${ft.BASE}/admin/books/${bookId}`);
    await expect(page.locator('h1').first()).toContainText(TITLE);
    await expect(page.locator('#book-rda-types')).toHaveCount(0);
  });

  test('2. switched off: saving the book keeps its stored overrides', async () => {
    await page.goto(`${ft.BASE}/admin/books/edit/${bookId}`);
    await page.fill('#titolo', `${TITLE} edited`);
    await mf.submitBookForm(page);
    expect(ft.dbQuery(`SELECT CONCAT_WS('/', rda_content_type, rda_media_type, rda_carrier_type) FROM libri WHERE id=${bookId}`)).toBe('prm/n/nc');
  });

  test('3. switched off: the MARC export uses the types derived from the material', async () => {
    const res = await page.request.get(`${ft.BASE}/admin/books/export/marc?format=marcxml&ids=${bookId}`);
    expect(res.status()).toBe(200);
    expect(contentType(await res.text())).toBe('txt');
  });

  test('4. switched back on: selects, record row and export show the override again', async () => {
    await ft.setModuleInUi(page, 'rda_types', true);
    await page.goto(`${ft.BASE}/admin/books/edit/${bookId}`);
    await expect(page.locator('#rda_content_type')).toHaveValue('prm');
    await page.goto(`${ft.BASE}/admin/books/${bookId}`);
    await expect(page.locator('#book-rda-types')).toHaveAttribute('data-rda', 'prm/n/nc');
    const res = await page.request.get(`${ft.BASE}/admin/books/export/marc?format=marcxml&ids=${bookId}`);
    expect(contentType(await res.text())).toBe('prm');
  });
});
