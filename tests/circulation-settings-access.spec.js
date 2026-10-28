// @ts-check
/**
 * Circulation policy — who may change it. Patron types and the rules matrix
 * are library policy: a staff account reaches the admin area but every
 * circulation settings action (pages and forms) sends it back to the
 * dashboard with nothing changed; the administrator keeps full access.
 *
 * Run: /tmp/run-e2e.sh tests/circulation-settings-access.spec.js --config=tests/playwright.config.js --workers=1
 */
const { test, expect } = require('@playwright/test');
const F = require('./helpers/circulation-fixtures');

test.skip(!F.CONFIGURED, 'E2E credentials not configured');

const RUN = String(Date.now()).slice(-6);
const PREFIX = `e2eacc${RUN}`;

test.describe.serial('Circulation settings: administrators only', () => {
  /** @type {import('@playwright/test').Page} */
  let staffPage;
  /** @type {import('@playwright/test').Page} */
  let adminPage;
  let staff;
  let typeId = 0;
  let snapshot = '';
  const snap = () => F.dbQuery("SELECT CONCAT(COUNT(*),'|',COALESCE(SUM(CRC32(CONCAT_WS('|',id,codice,nome,IFNULL(max_prestiti,''),predefinita,ordinamento,attiva))),0)) FROM tipologie_lettore")
    + '#' + F.dbQuery("SELECT CONCAT(COUNT(*),'|',COALESCE(SUM(CRC32(CONCAT_WS('|',tipologia_id,tipo_media,IFNULL(prestabile,''),IFNULL(max_prestiti,'')))),0)) FROM regole_prestito");

  test.beforeAll(async ({ browser }) => {
    adminPage = await (await browser.newContext()).newPage();
    await F.loginAsAdmin(adminPage);
    // A type the staff will try to change, made by the administrator.
    typeId = await F.createTypeViaUi(adminPage, { nome: `Accesso ${RUN}`, codice: `${PREFIX}-t`, max_prestiti: 3 });
    expect(typeId).toBeGreaterThan(0);
    staff = F.createPatron(`AC${RUN}`, { role: 'staff' });
    staffPage = await (await browser.newContext()).newPage();
    await F.loginAs(staffPage, staff.email, staff.password);
    snapshot = snap();
  });

  test.afterAll(async () => {
    F.deletePatrons([staff?.id]);
    F.deleteTypes(PREFIX);
    await staffPage?.context().close();
    await adminPage?.context().close();
  });

  test('staff opening the circulation pages lands on the dashboard with the admin-only message', async () => {
    for (const path of ['/admin/settings/circulation', `/admin/settings/circulation/types/${typeId}`]) {
      await staffPage.goto(`${F.BASE}${path}`);
      await expect(staffPage).toHaveURL(/\/admin\/dashboard/);
      await expect(staffPage.locator('body')).toContainText(/riservata agli amministratori|reserved to administrators/i);
    }
    await expect(staffPage.locator('[data-testid="circulation-type-edit"]')).toHaveCount(0);
  });

  test('staff opening the settings page on the circulation tab gets the default section, without the panel or the menu entry', async () => {
    await staffPage.goto(`${F.BASE}/admin/settings?tab=circulation`);
    await expect(staffPage).toHaveURL(/\/admin\/settings/);
    await expect(staffPage.locator('[data-settings-panel="general"]')).toBeVisible();
    await expect(staffPage.locator('[data-settings-panel="circulation"]')).toHaveCount(0);
    await expect(staffPage.locator('[data-testid="circulation-type-create"]')).toHaveCount(0);
    await expect(staffPage.locator('form[action$="/admin/settings/circulation/rules"]')).toHaveCount(0);
    await expect(staffPage.locator('[data-settings-section="circulation"]')).toHaveCount(0);
    await expect(staffPage.locator('[data-settings-section="loans"]').first()).toBeAttached();
  });

  test('every circulation form sent by staff is refused and changes nothing', async () => {
    await staffPage.goto(`${F.BASE}/admin/dashboard`);
    const csrf = await staffPage.locator('meta[name="csrf-token"]').getAttribute('content');
    expect(csrf).toBeTruthy();
    const posts = [
      ['/admin/settings/circulation/types', { nome: 'Intruso', codice: `${PREFIX}-x`, max_prestiti: '99' }],
      [`/admin/settings/circulation/types/${typeId}`, { nome: 'Modificato', codice: `${PREFIX}-t`, max_prestiti: '99' }],
      [`/admin/settings/circulation/types/${typeId}/default`, {}],
      [`/admin/settings/circulation/types/${typeId}/move`, { direction: 'up' }],
      ['/admin/settings/circulation/rules', { [`rules[${typeId}][dvd][prestabile]`]: '0' }],
      [`/admin/settings/circulation/types/${typeId}/delete`, {}],
    ];
    for (const [path, fields] of posts) {
      const r = await staffPage.request.post(`${F.BASE}${path}`, { form: { csrf_token: String(csrf), ...fields }, maxRedirects: 0 });
      expect(r.status(), path).toBe(302);
      expect(r.headers()['location'] || '', path).toMatch(/\/admin\/dashboard/);
    }
    expect(snap()).toBe(snapshot);
    expect(F.dbQuery(`SELECT nome FROM tipologie_lettore WHERE id=${typeId}`)).toBe(`Accesso ${RUN}`);
  });

  test('the administrator still edits the type from its page', async () => {
    await adminPage.goto(`${F.BASE}/admin/settings/circulation/types/${typeId}`);
    const form = adminPage.locator('[data-testid="circulation-type-edit"]');
    await expect(form).toBeVisible();
    await form.locator('[name="max_prestiti"]').fill('4');
    await form.locator('button[type="submit"]').first().click();
    await adminPage.waitForURL(/tab=circulation/);
    expect(F.dbQuery(`SELECT max_prestiti FROM tipologie_lettore WHERE id=${typeId}`)).toBe('4');
  });

  test('the administrator still opens the circulation tab and deletes the type', async () => {
    await F.openCirculationTab(adminPage);
    await expect(adminPage.locator(`#circulation-types-table tr[data-type-code="${PREFIX}-t"]`)).toBeVisible();
    const csrf = await adminPage.locator('meta[name="csrf-token"]').getAttribute('content');
    const r = await adminPage.request.post(`${F.BASE}/admin/settings/circulation/types/${typeId}/delete`, { form: { csrf_token: String(csrf) }, maxRedirects: 0 });
    expect(r.status()).toBe(302);
    expect(r.headers()['location'] || '').not.toMatch(/\/admin\/dashboard/);
    expect(F.dbInt(`SELECT COUNT(*) FROM tipologie_lettore WHERE id=${typeId}`)).toBe(0);
  });

  test('staff still reaches the rest of the admin area (loans list)', async () => {
    await staffPage.goto(`${F.BASE}/admin/loans`);
    await expect(staffPage).toHaveURL(/\/admin\/loans/);
  });
});
