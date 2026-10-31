// @ts-check
/**
 * features.visitor_log: the visitor log is opt-in (personal data, off on a
 * fresh install too). The sidebar entry, the kiosk, its scans, the
 * statistics section and the retention purge follow the module (Settings →
 * Modules).
 *
 * Run: /Users/fabio/Documents/GitHub/run-e2e-wt3.sh tests/feature-toggle-visitor-log.spec.js --config=tests/playwright.config.js --workers=1
 */
const { test, expect } = require('@playwright/test');
const ft = require('./helpers/feature-toggles');

test.skip(!ft.configured, 'E2E credentials not configured');

const RUN = Date.now().toString(36);
const MEMBER = `zz-ft-visit-${RUN}@test.local`;

test.describe.serial('Feature toggle: visitor log', () => {
  test.describe.configure({ timeout: 180000 });
  /** @type {import('@playwright/test').Page} */
  let page;
  let snapshot;
  let memberId = 0;
  let card = '';

  test.beforeAll(async ({ browser }) => {
    snapshot = ft.readFeatures(['visitor_log']);
    ft.removeUsers('zz-ft-visit-%@test.local');
    memberId = ft.createUser(MEMBER, 'Test1234!', 'standard');
    card = ft.dbQuery(`SELECT codice_tessera FROM utenti WHERE id=${memberId}`);
    page = await browser.newPage();
    await ft.loginAdmin(page);
  });

  test.afterAll(async () => {
    await ft.restoreFeatures(snapshot);
    ft.dbQuery(`DELETE FROM presenze WHERE utente_id=${memberId}`);
    ft.removeUsers('zz-ft-visit-%@test.local');
    await page?.close();
  });

  test('1. switched off (the fresh-install default): no Presenze entry, the kiosk is refused', async () => {
    await ft.setModuleInUi(page, 'visitor_log', false);
    await expect(page.locator('#feature_visitor_log')).not.toBeChecked();
    await page.goto(`${ft.BASE}/admin/dashboard`);
    await expect(page.locator('a.nav-link[href$="/admin/attendance"]')).toHaveCount(0);
    await page.goto(`${ft.BASE}/admin/attendance`);
    await expect(page).toHaveURL(/\/admin\/dashboard/);
    // The Italian alias goes the same way.
    await page.goto(`${ft.BASE}/admin/presenze`);
    await expect(page).toHaveURL(/\/admin\/dashboard/);
  });

  test('2. switched off: a card scan posted to the kiosk records nothing', async () => {
    const csrf = await ft.csrfToken(page);
    const res = await page.request.post(`${ft.BASE}/admin/attendance/scan`, { form: { csrf_token: csrf, codice_tessera: card }, maxRedirects: 0 });
    expect(res.status()).toBe(302);
    expect(String(res.headers().location || '')).toContain('/admin/dashboard');
    expect(ft.dbQuery(`SELECT COUNT(*) FROM presenze WHERE utente_id=${memberId}`)).toBe('0');
  });

  test('3. switched off: the statistics page has no visitors section', async () => {
    ft.dbQuery(`INSERT INTO presenze (utente_id, tipo) VALUES (${memberId}, 'socio')`);
    await page.goto(`${ft.BASE}/admin/statistics`);
    await expect(page.locator('h1, h2').first()).toBeVisible();
    await expect(page.locator('#stats-visitors')).toHaveCount(0);
    ft.dbQuery(`DELETE FROM presenze WHERE utente_id=${memberId}`);
  });

  test('4. switched on: the entry opens the kiosk and a card scan records the visit', async () => {
    await ft.setModuleInUi(page, 'visitor_log', true);
    await page.goto(`${ft.BASE}/admin/dashboard`);
    await page.locator('a.nav-link[href$="/admin/attendance"]').click();
    await expect(page).toHaveURL(/\/admin\/attendance/);
    await page.fill('#codice_tessera', card);
    await page.locator('#attendance-scan-form button[type="submit"]').click();
    await page.waitForLoadState('domcontentloaded');
    await expect.poll(() => ft.dbQuery(`SELECT COUNT(*) FROM presenze WHERE utente_id=${memberId}`)).toBe('1');
    await page.goto(`${ft.BASE}/admin/statistics`);
    await expect(page.locator('#stats-visitors')).toBeVisible();
  });

  test('5. switched off again: the visits stay stored, and come back with the module', async () => {
    await ft.setModuleInUi(page, 'visitor_log', false);
    expect(ft.dbQuery(`SELECT COUNT(*) FROM presenze WHERE utente_id=${memberId}`)).toBe('1');
    await page.goto(`${ft.BASE}/admin/statistics`);
    await expect(page.locator('#stats-visitors')).toHaveCount(0);
    await ft.setModuleInUi(page, 'visitor_log', true);
    await page.goto(`${ft.BASE}/admin/statistics`);
    await expect(page.locator('#stats-visitors [data-visitors="soci"]')).not.toHaveText('0');
  });
});
