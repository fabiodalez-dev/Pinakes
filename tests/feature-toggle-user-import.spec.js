// @ts-check
/**
 * features.user_import: the CSV import of patrons follows the module
 * (Settings → Modules): the two buttons of the users list, the wizard and its
 * upload.
 *
 * Run: /Users/fabio/Documents/GitHub/run-e2e-wt3.sh tests/feature-toggle-user-import.spec.js --config=tests/playwright.config.js --workers=1
 */
const { test, expect } = require('@playwright/test');
const ft = require('./helpers/feature-toggles');

test.skip(!ft.configured, 'E2E credentials not configured');

const RUN = Date.now().toString(36);
const CSV = `nome;cognome;email\nZz;Import${RUN};zz-ft-imp-${RUN}@test.local\n`;

test.describe.serial('Feature toggle: user import', () => {
  test.describe.configure({ timeout: 180000 });
  /** @type {import('@playwright/test').Page} */
  let page;
  let snapshot;

  test.beforeAll(async ({ browser }) => {
    snapshot = ft.readFeatures(['user_import']);
    page = await browser.newPage();
    await ft.loginAdmin(page);
  });

  test.afterAll(async () => {
    await ft.restoreFeatures(snapshot);
    ft.removeUsers('zz-ft-imp-%@test.local');
    await page?.close();
  });

  test('1. switched off: the users list has no import button and the wizard is refused', async () => {
    await ft.setModuleInUi(page, 'user_import', false);
    await page.goto(`${ft.BASE}/admin/users`);
    await expect(page.locator('a[href$="/admin/users/create"]').first()).toBeAttached();
    await expect(page.locator('a[href$="/admin/users/import"]')).toHaveCount(0);
    await page.goto(`${ft.BASE}/admin/users/import`);
    await expect(page).toHaveURL(/\/admin\/dashboard/);
    await expect(page.locator('body')).toContainText('Funzione disattivata nelle impostazioni.');
  });

  test('2. switched off: an upload is refused and no account is created', async () => {
    const csrf = await ft.csrfToken(page);
    const res = await page.request.post(`${ft.BASE}/admin/users/import/upload`, {
      headers: { Accept: 'application/json' },
      multipart: { csrf_token: csrf, csv_file: { name: 'u.csv', mimeType: 'text/csv', buffer: Buffer.from(CSV) } },
      maxRedirects: 0,
    });
    expect(res.status()).toBe(404);
    expect((await res.json()).code).toBe('feature_disabled');
    expect(ft.dbQuery(`SELECT COUNT(*) FROM utenti WHERE email LIKE 'zz-ft-imp-%@test.local'`)).toBe('0');
    const example = await page.request.get(`${ft.BASE}/admin/users/import/example`, { maxRedirects: 0 });
    expect(example.status()).toBe(302);
  });

  test('3. switched back on: the button leads to the wizard and the upload is accepted', async () => {
    await ft.setModuleInUi(page, 'user_import', true);
    await page.goto(`${ft.BASE}/admin/users`);
    await page.locator('a[href$="/admin/users/import"]:visible').first().click();
    await expect(page).toHaveURL(/\/admin\/users\/import$/);
    await page.setInputFiles('#user_import_file', { name: 'u.csv', mimeType: 'text/csv', buffer: Buffer.from(CSV) });
    const upload = page.waitForResponse((r) => r.url().includes('/admin/users/import/upload'), { timeout: 20000 });
    await page.locator('#user-import-upload-btn').click();
    const res = await upload;
    expect(res.status()).toBe(200);
  });
});
