// @ts-check
/**
 * features.catalogue_cards: the ISBD catalogue card of the book record and
 * of the list selection follows the module (Settings → Modules).
 *
 * Run: /Users/fabio/Documents/GitHub/run-e2e-wt3.sh tests/feature-toggle-catalogue-cards.spec.js --config=tests/playwright.config.js --workers=1
 */
const { test, expect } = require('@playwright/test');
const ft = require('./helpers/feature-toggles');
const mf = require('./helpers/minor-features-090');

test.skip(!ft.configured, 'E2E credentials not configured');

const TITLE = `ZZ_FT_CARD_${Date.now().toString(36)}`;

test.describe.serial('Feature toggle: catalogue cards', () => {
  test.describe.configure({ timeout: 180000 });
  /** @type {import('@playwright/test').Page} */
  let page;
  let snapshot;
  let bookId = 0;

  test.beforeAll(async ({ browser }) => {
    snapshot = ft.readFeatures(['catalogue_cards']);
    bookId = mf.seedBook({ titolo: TITLE, copie_totali: 1, copie_disponibili: 1 });
    page = await browser.newPage();
    await ft.loginAdmin(page);
  });

  test.afterAll(async () => {
    await ft.restoreFeatures(snapshot);
    mf.deleteBooks([bookId]);
    await page?.close();
  });

  test('1. switched off: no catalogue card button on the book record nor in the selection bar', async () => {
    await ft.setModuleInUi(page, 'catalogue_cards', false);
    await page.goto(`${ft.BASE}/admin/books/${bookId}`);
    await expect(page.locator('h1').first()).toContainText(TITLE);
    await expect(page.locator('#catalogue-card-link')).toHaveCount(0);
    await page.goto(`${ft.BASE}/admin/books`);
    await expect(page.locator('#bulk-export')).toBeAttached();
    await expect(page.locator('#bulk-catalogue-cards')).toHaveCount(0);
  });

  test('2. switched off: the card addresses are refused, no PDF', async () => {
    for (const url of [`/admin/books/${bookId}/catalogue-card`, `/admin/books/catalogue-cards?ids=${bookId}`]) {
      const res = await page.request.get(`${ft.BASE}${url}`, { maxRedirects: 0 });
      expect(res.status()).toBe(302);
      expect(String(res.headers()['content-type'] || '')).not.toContain('pdf');
    }
  });

  test('3. switched back on: the button opens the card of this book', async () => {
    await ft.setModuleInUi(page, 'catalogue_cards', true);
    await page.goto(`${ft.BASE}/admin/books/${bookId}`);
    await expect(page.locator('#catalogue-card-link')).toBeVisible();
    const href = await page.locator('#catalogue-card-link').getAttribute('href');
    const res = await page.request.get(`${ft.BASE}${String(href).replace(/^https?:\/\/[^/]+/, '')}`);
    expect(res.status()).toBe(200);
    expect(String(res.headers()['content-type'] || '')).toContain('application/pdf');
    expect(mf.pdfText(await res.body())).toContain(TITLE);
    await page.goto(`${ft.BASE}/admin/books`);
    await expect(page.locator('#bulk-catalogue-cards')).toBeAttached();
  });
});
