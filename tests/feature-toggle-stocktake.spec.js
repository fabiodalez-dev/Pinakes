// @ts-check
/**
 * features.stocktake: an administrator switches the inventory stocktake off
 * in Settings → Modules and back on; menu, pages, form posts, statistics and
 * the custom-role navigation follow. DB only for setup and verification.
 *
 * Run: /Users/fabio/Documents/GitHub/run-e2e-wt3.sh tests/feature-toggle-stocktake.spec.js --config=tests/playwright.config.js --workers=1
 */
const { test, expect } = require('@playwright/test');
const ft = require('./helpers/feature-toggles');

test.skip(!ft.configured, 'E2E credentials not configured');

const RUN = Date.now().toString(36);
const NAME = `ZZ_FT_STOCK_${RUN}`;
const ROLE = `ZZ FT Revisore ${RUN}`;
const AUDITOR = `zz-ft-stock-${RUN}@test.local`;
const PASS = 'Test1234!';

function cleanup() {
  ft.dbQuery(`DELETE FROM inventario_sessioni WHERE nome LIKE 'ZZ\\_FT\\_STOCK\\_%'`);
  ft.removeUsers('zz-ft-stock-%@test.local');
  ft.dbQuery(`DELETE FROM ruoli WHERE nome LIKE 'ZZ FT Revisore %'`);
}

test.describe.serial('Feature toggle: inventory stocktake', () => {
  test.describe.configure({ timeout: 180000 });
  /** @type {import('@playwright/test').Page} */
  let page;
  let snapshot;

  test.beforeAll(async ({ browser }) => {
    snapshot = ft.readFeatures(['stocktake']);
    cleanup();
    // A closed session, so the statistics section has something to show when on.
    ft.dbQuery(`INSERT INTO inventario_sessioni (nome, stato, totale_attese, aperta_il, chiusa_il) VALUES (${ft.sqlString(NAME + '_closed')}, 'chiusa', 0, NOW() - INTERVAL 1 HOUR, NOW())`);
    page = await browser.newPage();
    await ft.loginAdmin(page);
  });

  test.afterAll(async () => {
    await ft.restoreFeatures(snapshot);
    cleanup();
    await page?.close();
  });

  test('1. switched off: no sidebar entry, the page sends back to the dashboard with the reason', async () => {
    await ft.setModuleInUi(page, 'stocktake', false);
    await expect(page.locator('#feature_stocktake')).not.toBeChecked();
    await page.goto(`${ft.BASE}/admin/dashboard`);
    await expect(page.locator('a.nav-link[href$="/admin/inventory"]')).toHaveCount(0);
    await page.goto(`${ft.BASE}/admin/inventory`);
    await expect(page).toHaveURL(/\/admin\/dashboard/);
    await expect(page.locator('body')).toContainText('Funzione disattivata nelle impostazioni.');
  });

  test('2. switched off: opening a session by form post is refused and nothing is written', async () => {
    const csrf = await ft.csrfToken(page);
    const res = await page.request.post(`${ft.BASE}/admin/inventory`, { form: { csrf_token: csrf, nome: `${NAME}_refused` }, maxRedirects: 0 });
    expect(res.status()).toBe(302);
    expect(String(res.headers().location || '')).toContain('/admin/dashboard');
    expect(ft.dbQuery(`SELECT COUNT(*) FROM inventario_sessioni WHERE nome=${ft.sqlString(NAME + '_refused')}`)).toBe('0');
    // A JSON caller (the scanner) gets the machine-readable refusal.
    const json = await page.request.get(`${ft.BASE}/admin/inventory/1/state`, { headers: { Accept: 'application/json' }, maxRedirects: 0 });
    expect(json.status()).toBe(404);
    expect((await json.json()).code).toBe('feature_disabled');
  });

  test('3. switched off: the statistics page has no stocktake section', async () => {
    await page.goto(`${ft.BASE}/admin/statistics`);
    await expect(page.locator('h1, h2').first()).toBeVisible();
    await expect(page.locator('#stats-stocktake')).toHaveCount(0);
  });

  test('4. switched back on: entry, page, form and statistics are back', async () => {
    await ft.setModuleInUi(page, 'stocktake', true);
    await page.goto(`${ft.BASE}/admin/dashboard`);
    await expect(page.locator('a.nav-link[href$="/admin/inventory"]')).toHaveCount(1);
    await page.locator('a.nav-link[href$="/admin/inventory"]').click();
    await expect(page).toHaveURL(/\/admin\/inventory$/);
    await page.fill('#inventory-name', `${NAME}_open`);
    await page.locator('#inventory-open-form button[type="submit"]').click();
    await expect(page).toHaveURL(/\/admin\/inventory\/\d+/);
    expect(ft.dbQuery(`SELECT COUNT(*) FROM inventario_sessioni WHERE nome=${ft.sqlString(NAME + '_open')}`)).toBe('1');
    await page.goto(`${ft.BASE}/admin/statistics`);
    await expect(page.locator('#stats-stocktake')).toBeVisible();
  });

  test('5. a custom stocktake role finds nothing to open while the module is off', async ({ browser }) => {
    ft.dbQuery(`INSERT INTO ruoli (nome, permessi) VALUES (${ft.sqlString(ROLE)}, '["backend.access","inventory.manage"]')`);
    const roleId = Number(ft.dbQuery(`SELECT id FROM ruoli WHERE nome=${ft.sqlString(ROLE)}`));
    ft.createUser(AUDITOR, PASS, 'standard', roleId);
    const auditor = await browser.newPage();
    try {
      await ft.loginAs(auditor, AUDITOR, PASS);
      await auditor.goto(`${ft.BASE}/admin/dashboard`);
      await expect(auditor.locator(`a[href$="/admin/inventory"]`).first()).toBeVisible();
      await ft.setModuleInUi(page, 'stocktake', false);
      await auditor.goto(`${ft.BASE}/admin/dashboard`);
      await expect(auditor.locator(`a[href$="/admin/inventory"]`)).toHaveCount(0);
      await auditor.goto(`${ft.BASE}/admin/inventory`);
      await expect(auditor).not.toHaveURL(/\/admin\/inventory/);
    } finally {
      await auditor.close();
      await ft.setModuleInUi(page, 'stocktake', true);
    }
  });
});
