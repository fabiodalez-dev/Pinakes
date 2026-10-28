// @ts-check
/**
 * features.circulation_rules (Settings → Loans): patron types, the rules
 * matrix and consultation-only copies. Off, only the global loan rules of the
 * Loans tab apply, as before 0.9.0; the data (types, assignments, flags) is
 * kept and comes back with the module.
 *
 * Run: /Users/fabio/Documents/GitHub/run-e2e-wt3.sh tests/feature-toggle-circulation-rules.spec.js --config=tests/playwright.config.js --workers=1
 */
const { test, expect } = require('@playwright/test');
const ft = require('./helpers/feature-toggles');
const mf = require('./helpers/minor-features-090');

test.skip(!ft.configured, 'E2E credentials not configured');

const RUN = Date.now().toString(36);
const TITLE = `ZZ_FT_CONS_${RUN}`;
const READER = `zz-ft-rules-${RUN}@test.local`;
const TYPE_CODE = `zzft-${RUN}`;

test.describe.serial('Feature toggle: rules by patron type', () => {
  test.describe.configure({ timeout: 180000 });
  /** @type {import('@playwright/test').Page} */
  let page;
  let snapshot;
  let bookId = 0;
  let copyId = 0;
  let readerId = 0;
  let typeId = 0;

  test.beforeAll(async ({ browser }) => {
    snapshot = ft.readFeatures(['circulation_rules']);
    await ft.writeFeatures({ circulation_rules: '1' });
    ft.removeUsers('zz-ft-rules-%@test.local');
    bookId = mf.seedBook({ titolo: TITLE, copie_totali: 1, copie_disponibili: 1 });
    ft.dbQuery(`INSERT INTO copie (libro_id, numero_inventario, stato, solo_consultazione) VALUES (${bookId}, ${ft.sqlString(`ZZFTC-${RUN}`)}, 'disponibile', 1)`);
    copyId = Number(ft.dbQuery(`SELECT id FROM copie WHERE libro_id=${bookId}`));
    ft.dbQuery(`INSERT INTO tipologie_lettore (codice, nome, ordinamento) VALUES (${ft.sqlString(TYPE_CODE)}, ${ft.sqlString(`ZZ FT ${RUN}`)}, 99)`);
    typeId = Number(ft.dbQuery(`SELECT id FROM tipologie_lettore WHERE codice=${ft.sqlString(TYPE_CODE)}`));
    readerId = ft.createUser(READER, 'Test1234!', 'standard');
    ft.dbQuery(`UPDATE utenti SET tipologia_id=${typeId} WHERE id=${readerId}`);
    page = await browser.newPage();
    await ft.loginAdmin(page);
  });

  test.afterAll(async () => {
    await ft.restoreFeatures(snapshot);
    ft.removeUsers('zz-ft-rules-%@test.local');
    ft.dbQuery(`DELETE FROM tipologie_lettore WHERE id=${typeId}`);
    mf.deleteBooks([bookId]);
    await page?.close();
  });

  test('1. switched off from Settings → Loans: the Circulation section and its pages are gone', async () => {
    await page.goto(`${ft.BASE}/admin/settings?tab=loans`);
    await expect(page.locator('#loans-circulation-rules')).toBeVisible();
    await ft.setModuleInUi(page, 'circulation_rules', false);
    await expect(page.locator('#feature_circulation_rules')).not.toBeChecked();
    await expect(page.locator('a[data-settings-section="circulation"]')).toHaveCount(0);
    await page.goto(`${ft.BASE}/admin/settings?tab=circulation`);
    await expect(page.locator('section[data-settings-panel="general"]')).toBeVisible();
    await page.goto(`${ft.BASE}/admin/settings/circulation`);
    await expect(page).toHaveURL(/\/admin\/dashboard/);
  });

  test('2. switched off: no patron type in the user forms, the record or the users filter', async () => {
    await page.goto(`${ft.BASE}/admin/users/create`);
    await expect(page.locator('input[name="email"]')).toBeVisible();
    await expect(page.locator('#tipologia_id')).toHaveCount(0);
    await page.goto(`${ft.BASE}/admin/users/details/${readerId}`);
    await expect(page.locator('[data-testid="patron-type-name"]')).toHaveCount(0);
    await page.goto(`${ft.BASE}/admin/users`);
    await expect(page.locator('#patron_type_filter')).toHaveCount(0);
  });

  test('3. switched off: a consultation-only copy is an ordinary lendable copy', async () => {
    await page.goto(`${ft.BASE}/admin/books/${bookId}`);
    await expect(page.locator('h1').first()).toContainText(TITLE);
    await expect(page.locator('[data-testid="copy-consultation-badge"]')).toHaveCount(0);
    await expect(page.locator('[data-testid="copy-consultation-toggle"]')).toHaveCount(0);
    await page.goto(`${ft.BASE}/libro/${bookId}`);
    await expect(page.locator('#consultation-only')).toHaveCount(0);
    const csrf = await ft.csrfToken(page);
    const res = await page.request.post(`${ft.BASE}/admin/books/copies/${copyId}/consultation`, { form: { csrf_token: csrf, solo_consultazione: '0' }, maxRedirects: 0 });
    expect(res.status()).toBe(302);
    expect(ft.dbQuery(`SELECT solo_consultazione FROM copie WHERE id=${copyId}`)).toBe('1');
  });

  test('4. switched off: saving the reader from the edit form keeps the patron type', async () => {
    await page.goto(`${ft.BASE}/admin/users/edit/${readerId}`);
    await page.locator('#user-form button[type="submit"]').click();
    await page.waitForURL((u) => !u.pathname.includes('/edit/'), { timeout: 20000 });
    expect(ft.dbQuery(`SELECT IFNULL(tipologia_id, 0) FROM utenti WHERE id=${readerId}`)).toBe(String(typeId));
  });

  test('5. switched back on: section, select, badge and pill are back, with the data kept', async () => {
    await ft.setModuleInUi(page, 'circulation_rules', true);
    await expect(page.locator('a[data-settings-section="circulation"]').first()).toBeAttached();
    await page.goto(`${ft.BASE}/admin/users/edit/${readerId}`);
    await expect(page.locator('#tipologia_id')).toHaveValue(String(typeId));
    await page.goto(`${ft.BASE}/admin/books/${bookId}`);
    await expect(page.locator('[data-testid="copy-consultation-badge"]')).toBeVisible();
    await page.goto(`${ft.BASE}/libro/${bookId}`);
    await expect(page.locator('#consultation-only')).toBeVisible();
  });
});
