// E2E: Emeroteca plugin through the real browser.
// The unit suite (tests/emeroteca.unit.php) covers schema + kardex logic;
// THIS spec proves the user-visible surface: bundled-plugin activation from
// the real /admin/plugins UI, testata creation from the real form, annata +
// fascicoli creation from the issues page, and the public /emeroteca
// frontend (index, testata grid, fascicolo page). Conventions follow
// activity-feed-374.spec.js (env parsing, MYSQL_PWD-based dbQuery, skip
// guard, console-error guard, FK-safe cleanup).
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';

const e2e = (key) => {
  const v = process.env[key];
  return v === undefined || v === 'undefined' ? '' : v;
};
const DB_USER = e2e('E2E_DB_USER');
const DB_PASS = e2e('E2E_DB_PASS');
const DB_HOST = e2e('E2E_DB_HOST');
const DB_PORT = e2e('E2E_DB_PORT');
const DB_SOCKET = e2e('E2E_DB_SOCKET');
const DB_NAME = e2e('E2E_DB_NAME');

function dbQuery(sql) {
  // Password via MYSQL_PWD env, never as a -p argv (visible in ps//proc).
  const args = ['-u', DB_USER, DB_NAME, '-N', '-B', '-e', sql];
  if (DB_HOST) {
    args.splice(2, 0, '-h', DB_HOST);
    if (DB_PORT) args.splice(4, 0, '-P', DB_PORT);
  } else if (DB_SOCKET) {
    args.splice(2, 0, '-S', DB_SOCKET);
  }
  return execFileSync('mysql', args, {
    encoding: 'utf-8',
    timeout: 10000,
    env: { ...process.env, MYSQL_PWD: DB_PASS },
  }).trim();
}

const EM_SKIP = !e2e('E2E_ADMIN_EMAIL') || !e2e('E2E_ADMIN_PASS') || !DB_USER || !DB_PASS || !DB_NAME;

const sqlEscape = (s) => String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
const RUN = Date.now().toString(36) + 'em';
const TITLE = `EmerotecaE2E Rivista ${RUN}`;
const ANNO = 2024;
let testataId = '';
let annataId = '';
let fascicoloIds = [];

async function loginAsAdmin(page) {
  await page.goto(`${BASE}/admin/dashboard`);
  const email = page.locator('input[name="email"]');
  if (await email.isVisible({ timeout: 3000 }).catch(() => false)) {
    await email.fill(process.env.E2E_ADMIN_EMAIL);
    await page.fill('input[name="password"]', process.env.E2E_ADMIN_PASS);
    await page.click('button[type="submit"]');
    await page.waitForURL(/.*(?:dashboard|admin).*/, { timeout: 15000 });
  }
}

// Console-error guard, same filters as activity-feed-374.spec.js:
// resource-load 404s and the pre-existing "Applying inline style" CSP
// notices are page-wide noise; only real JS errors should fail the test.
function attachConsoleGuard(page, errors) {
  page.on('console', (m) => {
    if (m.type() !== 'error') return;
    const t = m.text();
    if (/Failed to load resource|Applying inline style/i.test(t)) return;
    errors.push(t);
  });
}

// FK-safe cleanup: articoli → fascicoli → annate → testate, all scoped to
// the fixture title. Tolerates missing tables (plugin never activated).
function emCleanup() {
  const t = sqlEscape(TITLE);
  try {
    dbQuery(
      `DELETE ar FROM emeroteca_articoli ar JOIN emeroteca_fascicoli f ON ar.fascicolo_id=f.id JOIN emeroteca_annate a ON f.annata_id=a.id JOIN emeroteca_testate tt ON a.testata_id=tt.id WHERE tt.titolo='${t}';`
      + `DELETE f FROM emeroteca_fascicoli f JOIN emeroteca_annate a ON f.annata_id=a.id JOIN emeroteca_testate tt ON a.testata_id=tt.id WHERE tt.titolo='${t}';`
      + `DELETE a FROM emeroteca_annate a JOIN emeroteca_testate tt ON a.testata_id=tt.id WHERE tt.titolo='${t}';`
      + `DELETE FROM emeroteca_testate WHERE titolo='${t}';`,
    );
  } catch (e) {
    // Tables absent → nothing to clean.
  }
}

test.describe.serial('Emeroteca plugin (E2E)', () => {
  test.skip(EM_SKIP, 'E2E credentials not configured');
  test.beforeAll(() => emCleanup());
  test.afterAll(() => emCleanup());

  test('activate the emeroteca plugin from the admin UI (if inactive)', async ({ page }) => {
    test.setTimeout(120000);
    await loginAsAdmin(page);

    // emeroteca IS in App\Support\BundledPlugins::LIST, so visiting
    // /admin/plugins auto-registers it (same flow as full-test 5.2b for
    // book-club). Then activate through the real UI when inactive.
    await page.goto(`${BASE}/admin/plugins`);
    await page.waitForLoadState('domcontentloaded');
    const emId = Number(String(dbQuery(
      `SELECT id FROM plugins WHERE name='emeroteca' LIMIT 1`,
    )).trim() || '0');
    expect(emId, 'emeroteca must be auto-registered as a bundled plugin').toBeGreaterThan(0);

    await page.waitForSelector('[data-plugin-id]', { timeout: 10000 }).catch(() => {});
    const card = page.locator(`[data-plugin-id="${emId}"]`).first();
    expect(await card.isVisible({ timeout: 3000 }).catch(() => false)).toBeTruthy();

    // Async activation POST + racy swal timing: retry the whole
    // click→confirm→settle up to 3 times and gate on the DB truth
    // (is_active=1), exactly like full-test 5.2b.
    const isActive = () =>
      String(dbQuery(`SELECT is_active FROM plugins WHERE id=${emId}`)).trim() === '1';
    for (let attempt = 0; attempt < 3 && !isActive(); attempt++) {
      const activateBtn = card.locator('button:has-text("Attiva")');
      if (!await activateBtn.isVisible({ timeout: 1500 }).catch(() => false)) {
        await page.goto(`${BASE}/admin/plugins`);
        await page.waitForLoadState('domcontentloaded');
        continue;
      }
      await activateBtn.click();
      const confirmBtn = page.locator('.swal2-confirm:visible');
      if (await confirmBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
        await confirmBtn.click();
      }
      const sawError = await page.locator('.swal2-popup .swal2-icon.swal2-error')
        .isVisible({ timeout: 4000 }).catch(() => false);
      expect(sawError, 'emeroteca activation must not raise a schema error').toBeFalsy();
      await page.waitForFunction(
        () => document.querySelector('.swal2-popup .swal2-icon.swal2-success') !== null,
        { timeout: 10000 },
      ).catch(() => {});
      await page.keyboard.press('Enter').catch(() => {});
      await page.waitForLoadState('domcontentloaded');
      await page.waitForTimeout(500);
    }
    expect(String(dbQuery(`SELECT is_active FROM plugins WHERE id=${emId}`)).trim()).toBe('1');

    // Every plugin table exists after activation.
    for (const t of ['emeroteca_testate', 'emeroteca_annate', 'emeroteca_fascicoli', 'emeroteca_articoli']) {
      const exists = String(dbQuery(
        `SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='${t}'`,
      )).trim();
      expect(exists, `${t} must exist after emeroteca activation`).toBe('1');
    }
  });

  test('create a testata from the real admin form', async ({ page }) => {
    test.setTimeout(90000);
    await loginAsAdmin(page);

    await page.goto(`${BASE}/admin/periodicals/create`);
    await expect(page.locator('#titolo')).toBeVisible({ timeout: 10000 });
    await page.fill('#titolo', TITLE);
    await page.selectOption('#tipo', 'rivista');
    // 'mensile' so the Kardex quick-action is enabled on the issues page.
    await page.selectOption('#periodicita', 'mensile');
    await page.selectOption('#stato_raccolta', 'attiva');
    await page.locator('form button[type="submit"]:has-text("Crea testata")').click();

    // createSubmit redirects (303) to /admin/periodicals/{id}/issues.
    await page.waitForURL(/\/admin\/periodicals\/\d+\/issues/, { timeout: 15000 });

    testataId = String(dbQuery(
      `SELECT id FROM emeroteca_testate WHERE titolo='${sqlEscape(TITLE)}' LIMIT 1`,
    )).trim();
    expect(Number(testataId)).toBeGreaterThan(0);
    const periodicita = String(dbQuery(
      `SELECT periodicita FROM emeroteca_testate WHERE id=${Number(testataId)}`,
    )).trim();
    expect(periodicita).toBe('mensile');
  });

  test('add an annata and two fascicoli from the issues page', async ({ page }) => {
    test.setTimeout(90000);
    await loginAsAdmin(page);

    await page.goto(`${BASE}/admin/periodicals/${testataId}/issues`);
    await expect(page.locator('#ann-anno')).toBeVisible({ timeout: 10000 });

    // Add the annata through the real quick-action form.
    await page.fill('#ann-anno', String(ANNO));
    await page.locator('form:has(input[name="action"][value="add_annata"]) button[type="submit"]').click();
    await page.waitForLoadState('domcontentloaded');

    annataId = String(dbQuery(
      `SELECT id FROM emeroteca_annate WHERE testata_id=${Number(testataId)} AND anno=${ANNO} LIMIT 1`,
    )).trim();
    expect(Number(annataId)).toBeGreaterThan(0);

    // Add two fascicoli (n. 1 and n. 2) through the per-annata form.
    for (const numero of ['1', '2']) {
      const numInput = page.locator(`#fsc-num-${annataId}`);
      await expect(numInput).toBeVisible({ timeout: 10000 });
      await numInput.fill(numero);
      await page.locator(`form:has(#fsc-num-${annataId}) button[type="submit"]`).click();
      await page.waitForLoadState('domcontentloaded');
    }

    const rows = dbQuery(
      `SELECT id, numero FROM emeroteca_fascicoli WHERE annata_id=${Number(annataId)} ORDER BY CAST(numero AS UNSIGNED)`,
    ).split('\n').filter(Boolean);
    expect(rows.length).toBe(2);
    fascicoloIds = rows.map((r) => r.split('\t')[0]);
    expect(rows.map((r) => r.split('\t')[1])).toEqual(['1', '2']);

    // The manage page shows both issues in the annata card.
    await page.goto(`${BASE}/admin/periodicals/${testataId}/issues`);
    await expect(page.locator('details', { hasText: String(ANNO) }).first()).toContainText('2 fascicoli');
  });

  test('public frontend: index, testata grid and fascicolo page', async ({ page }) => {
    test.setTimeout(90000);
    const errors = [];
    attachConsoleGuard(page, errors);

    // /emeroteca index lists the testata.
    await page.goto(`${BASE}/emeroteca`);
    await expect(page.locator('#emeroteca-index')).toBeVisible({ timeout: 10000 });
    await expect(page.locator('#emeroteca-index')).toContainText(TITLE);

    // Testata page shows the covers grid with the 2 fascicoli of 2024.
    // Each owned issue renders TWO links (cover box + caption), so count
    // the issue cards (one caption per fascicolo), then check that both
    // fascicolo detail pages are linked.
    await page.goto(`${BASE}/emeroteca/${testataId}`);
    await expect(page.locator('h1', { hasText: TITLE })).toBeVisible({ timeout: 10000 });
    await expect(page.locator('.emeroteca-issue-caption')).toHaveCount(2);
    for (const fid of fascicoloIds) {
      await expect(
        page.locator(`a[href$="/emeroteca/fascicolo/${fid}"]`).first(),
      ).toBeVisible();
    }

    // Fascicolo page responds 200 with the right issue number.
    const resp = await page.goto(`${BASE}/emeroteca/fascicolo/${fascicoloIds[0]}`);
    expect(resp.status()).toBe(200);
    await expect(page.locator('h1')).toContainText('n. 1');
    await expect(page.locator('h1')).toContainText(TITLE);

    // Unknown fascicolo → 404 rendered inside the public layout.
    const missing = await page.goto(`${BASE}/emeroteca/fascicolo/99999999`);
    expect(missing.status()).toBe(404);

    expect(errors).toEqual([]);
  });
});
