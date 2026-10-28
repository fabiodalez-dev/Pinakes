// @ts-check
/**
 * Optional modules (features.*): the Settings → Modules tab and the three
 * circulation switches of Settings → Loans, driven like an administrator.
 * Every value is read back through HTTP (the rendered settings page), never
 * from the database alone: a switch saved but never mapped by ConfigStore
 * would look saved in the DB and stay inert (the allowlist trap).
 *
 * Run: /Users/fabio/Documents/GitHub/run-e2e-wt3.sh tests/feature-toggles-settings.spec.js --config=tests/playwright.config.js --workers=1
 */
const { test, expect } = require('@playwright/test');
const ft = require('./helpers/feature-toggles');
const { flushCache } = require('./helpers/flush-cache');

test.skip(!ft.configured, 'E2E credentials not configured');

const RUN = Date.now().toString(36);
const STAFF_EMAIL = `zz-ft-staff-${RUN}@test.local`;
const PASS = 'Test1234!';
const MODULE_KEYS = ft.FEATURE_KEYS.filter((k) => !ft.LOANS_TAB_KEYS.includes(k));

test.describe.serial('Feature toggles: settings', () => {
  test.describe.configure({ timeout: 180000 });
  /** @type {import('@playwright/test').Page} */
  let page;
  let snapshot;
  let catalogueMode;

  test.beforeAll(async ({ browser }) => {
    snapshot = ft.readFeatures();
    catalogueMode = ft.dbQuery("SELECT COALESCE(MAX(setting_value), '0') FROM system_settings WHERE category='system' AND setting_key='catalogue_mode'");
    ft.removeUsers('zz-ft-staff-%@test.local');
    ft.createUser(STAFF_EMAIL, PASS, 'staff');
    page = await browser.newPage();
    await ft.loginAdmin(page);
  });

  test.afterAll(async () => {
    ft.dbQuery(`INSERT INTO system_settings (category, setting_key, setting_value) VALUES ('system', 'catalogue_mode', ${ft.sqlString(catalogueMode)}) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)`);
    await ft.restoreFeatures(snapshot);
    ft.removeUsers('zz-ft-staff-%@test.local');
    await page?.close();
  });

  test('1. the administrator finds the Modules section with one switch per module', async () => {
    await page.goto(`${ft.BASE}/admin/settings`);
    await page.locator('a[data-settings-section="modules"]').first().click();
    await expect(page.locator('section[data-settings-panel="modules"]')).toBeVisible();
    for (const key of MODULE_KEYS) {
      await expect(page.locator(`#feature_${key}`)).toHaveCount(1);
    }
    for (const group of ['catalogue', 'users', 'public']) {
      await expect(page.locator(`[data-modules-group="${group}"]`)).toBeVisible();
    }
    // The circulation modules sit in the Loans tab, next to their settings.
    await page.goto(`${ft.BASE}/admin/settings?tab=loans`);
    for (const key of ft.LOANS_TAB_KEYS) {
      await expect(page.locator(`#feature_${key}`)).toBeEnabled();
    }
  });

  test('2. every switch is labelled and described, and the keyboard toggles it', async () => {
    await page.goto(`${ft.BASE}/admin/settings?tab=modules`);
    for (const key of MODULE_KEYS) {
      const box = page.locator(`#feature_${key}`);
      await expect(box).toHaveAttribute('aria-labelledby', `feature_${key}_label`);
      await expect(box).toHaveAttribute('aria-describedby', `feature_${key}_desc`);
      await expect(page.locator(`#feature_${key}_label`)).not.toBeEmpty();
      await expect(page.locator(`#feature_${key}_desc`)).not.toBeEmpty();
    }
    const box = page.locator('#feature_marc');
    const before = await box.isChecked();
    await box.focus();
    await page.keyboard.press('Space');
    expect(await box.isChecked()).toBe(!before);
    await page.keyboard.press('Space');
    expect(await box.isChecked()).toBe(before);
  });

  test('3. each module saved off and back on reads back from the page (HTTP round trip)', async () => {
    // All Modules-tab switches off in one save.
    await page.goto(`${ft.BASE}/admin/settings?tab=modules`);
    for (const key of MODULE_KEYS) {
      if (await page.locator(`#feature_${key}`).isChecked()) await page.locator(`label:has(#feature_${key})`).click();
    }
    await page.locator('#modules-settings-form button[type="submit"]').click();
    await page.waitForURL(/tab=modules/);
    await expect(page.locator('body')).toContainText('Moduli aggiornati correttamente.');
    for (const key of MODULE_KEYS) {
      await expect(page.locator(`#feature_${key}`)).not.toBeChecked();
    }
    // The circulation switches from the Loans tab.
    for (const key of ft.LOANS_TAB_KEYS) {
      await ft.setModuleInUi(page, key, false);
      await expect(page.locator(`#feature_${key}`)).not.toBeChecked();
    }
    // Back on, every one.
    await page.goto(`${ft.BASE}/admin/settings?tab=modules`);
    for (const key of MODULE_KEYS) {
      await page.locator(`label:has(#feature_${key})`).click();
    }
    await page.locator('#modules-settings-form button[type="submit"]').click();
    await page.waitForURL(/tab=modules/);
    for (const key of MODULE_KEYS) {
      await expect(page.locator(`#feature_${key}`)).toBeChecked();
    }
    for (const key of ft.LOANS_TAB_KEYS) {
      await ft.setModuleInUi(page, key, true);
      await expect(page.locator(`#feature_${key}`)).toBeChecked();
    }
    expect(Object.values(ft.readFeatures())).toEqual(ft.FEATURE_KEYS.map(() => '1'));
  });

  test('4. staff reaches the settings but neither sees the Modules section nor changes a module', async ({ browser }) => {
    const staff = await browser.newPage();
    try {
      await ft.loginAs(staff, STAFF_EMAIL, PASS);
      await staff.goto(`${ft.BASE}/admin/settings?tab=modules`);
      await expect(staff.locator('a[data-settings-section="modules"]')).toHaveCount(0);
      await expect(staff.locator('section[data-settings-panel="modules"]')).toHaveCount(0);
      // ?tab=modules falls back to the default section, like an unknown tab.
      await expect(staff.locator('section[data-settings-panel="general"]')).toBeVisible();
      // In the Loans tab the circulation switches are shown, locked.
      await staff.goto(`${ft.BASE}/admin/settings?tab=loans`);
      await expect(staff.locator('#feature_fines')).toBeDisabled();
      await expect(staff.locator('[data-testid="modules-admin-only"]')).toBeVisible();
      // A hand-built POST changes nothing.
      const csrf = await staff.locator('input[name="csrf_token"]').first().inputValue();
      const res = await staff.request.post(`${ft.BASE}/admin/settings/modules`, { form: { csrf_token: csrf }, maxRedirects: 0 });
      expect(res.status()).toBe(302);
    } finally {
      await staff.close();
    }
    expect(Object.values(ft.readFeatures())).toEqual(ft.FEATURE_KEYS.map(() => '1'));
  });

  test('5. catalogue mode hides visitor log and closures calendar even with their modules on', async () => {
    await page.goto(`${ft.BASE}/admin/dashboard`);
    await expect(page.locator('a.nav-link[href$="/admin/attendance"]')).toHaveCount(1);
    await expect(page.locator('a.nav-link[href$="/admin/calendar"]')).toHaveCount(1);
    ft.dbQuery("INSERT INTO system_settings (category, setting_key, setting_value) VALUES ('system', 'catalogue_mode', '1') ON DUPLICATE KEY UPDATE setting_value='1'");
    await flushCache();
    await page.goto(`${ft.BASE}/admin/dashboard`);
    await expect(page.locator('a.nav-link[href$="/admin/attendance"]')).toHaveCount(0);
    await expect(page.locator('a.nav-link[href$="/admin/calendar"]')).toHaveCount(0);
    await page.goto(`${ft.BASE}/admin/attendance`);
    await expect(page).toHaveURL(/\/admin\/dashboard/);
    // The switches themselves stay as saved.
    await page.goto(`${ft.BASE}/admin/settings?tab=loans`);
    await expect(page.locator('#feature_fines')).toBeChecked();
    ft.dbQuery(`UPDATE system_settings SET setting_value=${ft.sqlString(catalogueMode)} WHERE category='system' AND setting_key='catalogue_mode'`);
    await flushCache();
    await page.goto(`${ft.BASE}/admin/dashboard`);
    await expect(page.locator('a.nav-link[href$="/admin/attendance"]')).toHaveCount(1);
  });

  test('6. the labels are translated: an English administrator sees no Italian base string', async ({ browser }) => {
    const email = `zz-ft-staff-en-${RUN}@test.local`;
    const id = ft.createUser(email, PASS, 'admin');
    ft.dbQuery(`UPDATE utenti SET locale='en_US' WHERE id=${id}`);
    const en = await browser.newPage();
    try {
      await ft.loginAs(en, email, PASS);
      await en.goto(`${ft.BASE}/admin/settings?tab=modules`);
      const panel = en.locator('section[data-settings-panel="modules"]');
      await expect(panel).toBeVisible();
      const text = await panel.innerText();
      if (text.includes('MARC import and export')) {
        expect(text).toContain('Visitor log');
        expect(text).not.toContain('Registro presenze');
        expect(text).not.toContain('Salva moduli');
      } else {
        // The installation renders one language for everybody: still no raw key.
        expect(text).toContain('Import ed export MARC');
      }
    } finally {
      await en.close();
    }
  });
});
