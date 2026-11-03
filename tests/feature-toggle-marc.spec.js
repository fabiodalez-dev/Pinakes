// @ts-check
/**
 * features.marc: MARC bulk import/export and copy cataloguing follow the
 * module an administrator switches in Settings → Modules.
 *
 * Run: /Users/fabio/Documents/GitHub/run-e2e-wt3.sh tests/feature-toggle-marc.spec.js --config=tests/playwright.config.js --workers=1
 */
const { test, expect } = require('@playwright/test');
const ft = require('./helpers/feature-toggles');

test.skip(!ft.configured, 'E2E credentials not configured');

test.describe.serial('Feature toggle: MARC import and export', () => {
  test.describe.configure({ timeout: 180000 });
  /** @type {import('@playwright/test').Page} */
  let page;
  let snapshot;
  let z39Active = false;

  test.beforeAll(async ({ browser }) => {
    snapshot = ft.readFeatures(['marc']);
    z39Active = ft.dbQuery("SELECT COALESCE(MAX(is_active), 0) FROM plugins WHERE name='z39-server'") === '1';
    page = await browser.newPage();
    await ft.loginAdmin(page);
  });

  test.afterAll(async () => {
    await ft.restoreFeatures(snapshot);
    await page?.close();
  });

  test('1. switched off: the book list has no MARC entry in Export, Import nor the selection bar', async () => {
    await ft.setModuleInUi(page, 'marc', false);
    await page.goto(`${ft.BASE}/admin/books`);
    await expect(page.locator('.export-btn')).toBeVisible();
    await expect(page.locator('a[href*="/admin/books/export/marc"]')).toHaveCount(0);
    await expect(page.locator('a[href$="/admin/books/import/marc"]')).toHaveCount(0);
    await expect(page.locator('a[href$="/admin/books/copy-cataloging"]')).toHaveCount(0);
    await expect(page.locator('#bulk-export-marc')).toHaveCount(0);
    // The CSV and LibraryThing entries are still there.
    await expect(page.locator('a[href$="/admin/books/export/csv"]').first()).toBeAttached();
  });

  test('2. switched off: the import page and its upload are refused', async () => {
    await page.goto(`${ft.BASE}/admin/books/import/marc`);
    await expect(page).toHaveURL(/\/admin\/dashboard/);
    await expect(page.locator('body')).toContainText('Funzione disattivata nelle impostazioni.');
    const csrf = await ft.csrfToken(page);
    const res = await page.request.post(`${ft.BASE}/admin/books/import/marc/upload`, { form: { csrf_token: csrf }, maxRedirects: 0 });
    expect(res.status()).toBe(302);
  });

  test('3. switched off: the export downloads nothing', async () => {
    const res = await page.request.get(`${ft.BASE}/admin/books/export/marc?format=marcxml`, { maxRedirects: 0 });
    expect(res.status()).toBe(302);
    expect(String(res.headers().location || '')).toContain('/admin/dashboard');
    expect(String(res.headers()['content-type'] || '')).not.toContain('xml');
  });

  test('4. switched off: copy cataloguing (z39-server) is refused too', async () => {
    test.skip(!z39Active, 'z39-server plugin not active on this installation');
    const res = await page.request.get(`${ft.BASE}/admin/books/copy-cataloging`, { maxRedirects: 0 });
    expect(res.status()).toBe(302);
    const csrf = await ft.csrfToken(page);
    const search = await page.request.post(`${ft.BASE}/admin/books/copy-cataloging/search`, {
      headers: { Accept: 'application/json' }, form: { csrf_token: csrf, term: 'x' }, maxRedirects: 0,
    });
    expect(search.status()).toBe(404);
    expect((await search.json()).code).toBe('feature_disabled');
  });

  test('5. switched back on: menu entries, import page and the export file are back', async () => {
    await ft.setModuleInUi(page, 'marc', true);
    await page.goto(`${ft.BASE}/admin/books`);
    await expect(page.locator('[data-marc-export="marcxml"]')).toBeAttached();
    await expect(page.locator('a[href$="/admin/books/import/marc"]').first()).toBeAttached();
    await page.goto(`${ft.BASE}/admin/books/import/marc`);
    await expect(page).toHaveURL(/\/admin\/books\/import\/marc/);
    const id = ft.dbQuery('SELECT MIN(id) FROM libri WHERE deleted_at IS NULL');
    const res = await page.request.get(`${ft.BASE}/admin/books/export/marc?format=marcxml&ids=${id}`);
    expect(res.status()).toBe(200);
    expect(String(res.headers()['content-type'] || '')).toContain('xml');
    expect(await res.text()).toContain('<record');
  });
});
