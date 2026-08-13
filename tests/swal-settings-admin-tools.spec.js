// @ts-check
/**
 * SweetAlert coverage for the "settings-admin-tools" cluster.
 *
 * Proves every SweetAlert fired by the admin tools views is BOTH shown AND
 * functional. Covers:
 *   - app/Views/admin/integrity_report.php   (maintenance ops)
 *   - app/Views/admin/languages/{index,edit}.php  (language delete)
 *   - app/Views/admin/plugins.php            (activate/deactivate/uninstall/settings)
 *   - app/Views/admin/updates.php            (github token / backups / update)
 *   - app/Views/collocazione/index.php       (scaffale / mensola delete)
 *   - app/Views/settings/advanced-tab.php    (API key delete)
 *
 * Run with:
 *   /tmp/run-e2e.sh tests/swal-settings-admin-tools.spec.js --config=tests/playwright.config.js --workers=1
 *
 * NOTE: shared DB — the orchestrator runs the suite serially.
 */
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

// ── Env wiring copied VERBATIM from tests/series-cycles.spec.js ─────────────
const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';
const DB_HOST = process.env.E2E_DB_HOST || '';
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS ?? '';
const DB_NAME = process.env.E2E_DB_NAME || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';

const HAS_E2E_ENV = Boolean(ADMIN_EMAIL && ADMIN_PASS && DB_USER && DB_NAME && (DB_HOST || DB_SOCKET));

test.skip(
  !HAS_E2E_ENV,
  'Missing E2E env vars for settings-admin-tools SweetAlert tests'
);

function mysqlArgs(sql = '', batch = false) {
  const args = [];
  if (DB_HOST) args.push('-h', DB_HOST);
  if (DB_SOCKET) args.push('-S', DB_SOCKET);
  args.push('-u', DB_USER);
  if (DB_PASS !== '') args.push(`-p${DB_PASS}`);
  args.push(DB_NAME);
  if (batch) args.push('-N', '-B');
  if (sql !== '') args.push('-e', sql);
  return args;
}

function dbQuery(sql) {
  return execFileSync('mysql', mysqlArgs(sql, true), { encoding: 'utf-8', timeout: 10000 }).trim();
}

function dbExec(sql, timeout = 10000) {
  execFileSync('mysql', mysqlArgs(sql), { encoding: 'utf-8', timeout });
}

function escapeSql(value) {
  return String(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
}

// ── run-id / unique-suffix style copied from the template ──────────────────
const TAG = 'E2E_SWAL_AT_' + Date.now();

const ROOT = path.resolve(__dirname, '..');
const BACKUP_DIR = path.join(ROOT, 'storage', 'backups');

async function loginAsAdmin(page) {
  await page.goto(`${BASE}/admin/dashboard`);
  const emailField = page.locator('input[name="email"]');
  if (await emailField.isVisible({ timeout: 3000 }).catch(() => false)) {
    await emailField.fill(ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await Promise.all([
      page.waitForURL(/\/admin\//, { timeout: 15000 }),
      page.click('button[type="submit"]'),
    ]);
  }
}

// Wait for any SweetAlert popup and return its text content.
async function waitSwal(page, timeout = 15000) {
  const popup = page.locator('.swal2-popup');
  await popup.waitFor({ state: 'visible', timeout });
  return popup;
}

test.describe.serial('SweetAlert — settings-admin-tools cluster', () => {
  /** @type {import('@playwright/test').BrowserContext} */
  let context;
  /** @type {import('@playwright/test').Page} */
  let page;

  // Fixture identifiers (unique per run).
  const SCAFFALE_CODE = ('Z' + (Date.now() % 100000)).slice(0, 20);
  const LANG_CODE = 'zz_ZZ';
  const API_KEY_NAME = `${TAG}_apikey`;
  const API_KEY_VALUE = (TAG + '0000000000000000000000000000000000000000000000000000000000000000').slice(0, 64);
  const BACKUP_FIXTURE = 'update_e2eswal_' + (Date.now() % 1000000);

  // ids resolved in beforeAll
  let scaffaleId = 0;
  let mensolaId = 0;
  let apiKeyId = 0;

  test.beforeAll(async ({ browser }) => {
    // ── DB fixtures ─────────────────────────────────────────────────────
    // Scaffale + mensola (collocazione). `lettera` is NOT NULL — derive it.
    dbExec(`INSERT INTO scaffali (codice, nome, lettera, ordine) VALUES ('${escapeSql(SCAFFALE_CODE)}', '${escapeSql(TAG)} Scaffale', '${escapeSql(SCAFFALE_CODE.charAt(0))}', 0)`);
    scaffaleId = parseInt(dbQuery(`SELECT id FROM scaffali WHERE codice='${escapeSql(SCAFFALE_CODE)}' LIMIT 1`), 10);
    dbExec(`INSERT INTO mensole (scaffale_id, numero_livello, ordine) VALUES (${scaffaleId}, 99, 0)`);
    mensolaId = parseInt(dbQuery(`SELECT id FROM mensole WHERE scaffale_id=${scaffaleId} AND numero_livello=99 LIMIT 1`), 10);

    // Test language (non-default, non-active so the delete form renders).
    dbExec(`DELETE FROM languages WHERE code='${escapeSql(LANG_CODE)}'`);
    dbExec(`INSERT INTO languages (code, name, native_name, is_default, is_active) VALUES ('${escapeSql(LANG_CODE)}', 'E2E Lang', 'E2E Lang', 0, 1)`);

    // API key fixture.
    dbExec(`DELETE FROM api_keys WHERE name='${escapeSql(API_KEY_NAME)}'`);
    dbExec(`INSERT INTO api_keys (api_key, name, is_active) VALUES ('${escapeSql(API_KEY_VALUE)}', '${escapeSql(API_KEY_NAME)}', 1)`);
    apiKeyId = parseInt(dbQuery(`SELECT id FROM api_keys WHERE name='${escapeSql(API_KEY_NAME)}' LIMIT 1`), 10);

    // Backup fixture dir (for deleteBackup).
    try {
      const dir = path.join(BACKUP_DIR, BACKUP_FIXTURE);
      fs.mkdirSync(dir, { recursive: true });
      const fixture = path.join(dir, 'database.sql');
      fs.writeFileSync(fixture, '-- e2e fixture backup\n');
      // Apache owns the deletion request; the CI runner owns the fixture.
      fs.chmodSync(fixture, 0o666);
      fs.chmodSync(dir, 0o777);
    } catch { /* best effort */ }

    context = await browser.newContext();
    page = await context.newPage();
    await loginAsAdmin(page);
  });

  test.afterAll(async () => {
    // FK-safe order: mensole -> scaffali, then languages, api_keys.
    try { if (mensolaId) dbExec(`DELETE FROM mensole WHERE id=${mensolaId}`); } catch { /* */ }
    try { dbExec(`DELETE FROM mensole WHERE scaffale_id=${scaffaleId}`); } catch { /* */ }
    try { if (scaffaleId) dbExec(`DELETE FROM scaffali WHERE id=${scaffaleId}`); } catch { /* */ }
    try { dbExec(`DELETE FROM languages WHERE code='${escapeSql(LANG_CODE)}'`); } catch { /* */ }
    try { dbExec(`DELETE FROM api_keys WHERE name='${escapeSql(API_KEY_NAME)}'`); } catch { /* */ }
    // Remove any leftover backup fixture dir.
    try {
      const dir = path.join(BACKUP_DIR, BACKUP_FIXTURE);
      if (fs.existsSync(dir)) fs.rmSync(dir, { recursive: true, force: true });
    } catch { /* */ }
    // Remove any stray .deleted translation file backups for the test lang.
    try {
      const localeDir = path.join(ROOT, 'locale');
      if (fs.existsSync(localeDir)) {
        for (const f of fs.readdirSync(localeDir)) {
          if (f.startsWith(`${LANG_CODE}.json.deleted.`)) fs.rmSync(path.join(localeDir, f), { force: true });
        }
      }
    } catch { /* */ }
    await context?.close();
  });

  // ════════════════════════════════════════════════════════════════════
  // collocazione/index.php
  // ════════════════════════════════════════════════════════════════════

  // collocazione/index.php:327 — delete a mensola (confirm-destructive)
  // NOTE: the mensola is deleted FIRST, before its scaffale: deleting a
  // scaffale that still has a mensola is blocked by the FK constraint.
  test('collocazione:327 delete mensola — confirm-destructive', async () => {
    await page.goto(`${BASE}/admin/placement`);
    // mensola rows are hidden until a scaffale filter is chosen, so wait for the
    // row to be ATTACHED (in the DOM) rather than visible — the default
    // 'visible' state would time out on the hidden row. The delete form is still
    // in the DOM and submittable.
    await page.waitForSelector(`li[data-id="${mensolaId}"]`, { state: 'attached', timeout: 15000 });
    const form = page.locator(`form[action$="/admin/placement/shelves/${mensolaId}/delete"]`);
    // The submit button is hidden until a scaffale filter is chosen, but the
    // data-swal-confirm interceptor fires on the form's submit event —
    // requestSubmit() dispatches it regardless of the button's visibility.
    await form.evaluate(f => f.requestSubmit());
    const popup = await waitSwal(page);
    await expect(popup).toContainText(/mensola/i);
    await page.click('.swal2-confirm');
    await page.waitForLoadState('domcontentloaded');
    await page.waitForTimeout(800);
    const gone = dbQuery(`SELECT COUNT(*) FROM mensole WHERE id=${mensolaId}`);
    expect(parseInt(gone, 10)).toBe(0);
    mensolaId = 0;
  });

  // collocazione/index.php:225 — delete a scaffale (confirm-destructive)
  test('collocazione:225 delete scaffale — confirm-destructive', async () => {
    await page.goto(`${BASE}/admin/placement`);
    await page.waitForSelector(`li[data-id="${scaffaleId}"]`, { timeout: 15000 });
    const form = page.locator(`form[action$="/admin/placement/shelving-units/${scaffaleId}/delete"]`);
    await form.locator('button[type="submit"]').click();
    const popup = await waitSwal(page);
    await expect(popup).toContainText(/scaffale/i);
    await page.click('.swal2-confirm');
    await page.waitForLoadState('domcontentloaded');
    await page.waitForTimeout(800);
    const gone = dbQuery(`SELECT COUNT(*) FROM scaffali WHERE id=${scaffaleId}`);
    expect(parseInt(gone, 10)).toBe(0);
    scaffaleId = 0;
  });

  // ════════════════════════════════════════════════════════════════════
  // settings/advanced-tab.php
  // ════════════════════════════════════════════════════════════════════

  // settings/advanced-tab.php:774 — delete an API key (confirm-destructive)
  test('advanced-tab:774 delete API key — confirm-destructive', async () => {
    await page.goto(`${BASE}/admin/settings?tab=advanced`);
    const form = page.locator(`form[action$="/admin/settings/api/keys/${apiKeyId}/delete"]`);
    await form.scrollIntoViewIfNeeded();
    await expect(form).toBeVisible();
    await form.locator('button[type="submit"]').click();
    const popup = await waitSwal(page);
    await expect(popup).toContainText(/API key|irreversibile/i);
    await page.click('.swal2-confirm');
    await page.waitForLoadState('domcontentloaded');
    await page.waitForTimeout(800);
    const gone = dbQuery(`SELECT COUNT(*) FROM api_keys WHERE id=${apiKeyId}`);
    expect(parseInt(gone, 10)).toBe(0);
    apiKeyId = 0;
  });

  // ════════════════════════════════════════════════════════════════════
  // admin/languages/*.php
  // ════════════════════════════════════════════════════════════════════

  // admin/languages/edit.php:268 — delete language from edit page (confirm-destructive)
  // Tested BEFORE index delete so the fixture is freshly created and present.
  // We re-create the fixture after each delete to cover both views.
  test('languages/edit.php:268 delete language — confirm-destructive', async () => {
    await page.goto(`${BASE}/admin/languages/${encodeURIComponent(LANG_CODE)}/edit`);
    const form = page.locator(`form[action$="/admin/languages/${LANG_CODE}/delete"]`);
    await expect(form).toBeVisible();
    await form.locator('button[type="submit"]').click();
    const popup = await waitSwal(page);
    await expect(popup).toContainText(/lingua/i);
    await page.click('.swal2-confirm');
    await page.waitForLoadState('domcontentloaded');
    await page.waitForTimeout(800);
    const gone = dbQuery(`SELECT COUNT(*) FROM languages WHERE code='${escapeSql(LANG_CODE)}'`);
    expect(parseInt(gone, 10)).toBe(0);
  });

  // admin/languages/index.php:232 — delete language from list (confirm-destructive)
  test('languages/index.php:232 delete language — confirm-destructive', async () => {
    // Re-create fixture for the list-page delete.
    dbExec(`DELETE FROM languages WHERE code='${escapeSql(LANG_CODE)}'`);
    dbExec(`INSERT INTO languages (code, name, native_name, is_default, is_active) VALUES ('${escapeSql(LANG_CODE)}', 'E2E Lang', 'E2E Lang', 0, 1)`);

    await page.goto(`${BASE}/admin/languages`);
    const form = page.locator(`form[action$="/admin/languages/${LANG_CODE}/delete"]`);
    await expect(form).toBeVisible();
    await form.locator('button[type="submit"]').click();
    const popup = await waitSwal(page);
    await expect(popup).toContainText(/lingua/i);
    await page.click('.swal2-confirm');
    await page.waitForLoadState('domcontentloaded');
    await page.waitForTimeout(800);
    const gone = dbQuery(`SELECT COUNT(*) FROM languages WHERE code='${escapeSql(LANG_CODE)}'`);
    expect(parseInt(gone, 10)).toBe(0);
  });

  // ════════════════════════════════════════════════════════════════════
  // admin/integrity_report.php  (maintenance ops)
  // ════════════════════════════════════════════════════════════════════

  // integrity_report.php:419 — recalculateAvailability (info / loading + result)
  test('integrity:419 recalculateAvailability — result toast', async () => {
    await page.goto(`${BASE}/admin/maintenance/integrity-report`);
    await page.waitForSelector('button[onclick="recalculateAvailability()"]', { timeout: 15000 });
    await page.click('button[onclick="recalculateAvailability()"]');
    // Loading modal shows first, then the result popup (success/error).
    const popup = await waitSwal(page);
    // Wait for the result (loading auto-closes); the result has a confirm button.
    await page.waitForSelector('.swal2-confirm', { timeout: 20000 });
    await expect(popup).toContainText(/completata|fallita|Elaborazione/i);
    // dismiss without reloading-loop
    await page.click('.swal2-confirm').catch(() => {});
  });

  // integrity_report.php:443 — fixIssues (confirm-action)
  test('integrity:443 fixIssues — confirm-action then result', async () => {
    await page.goto(`${BASE}/admin/maintenance/integrity-report`);
    await page.waitForSelector('button[onclick="fixIssues()"]', { timeout: 15000 });
    await page.click('button[onclick="fixIssues()"]');
    const confirm = await waitSwal(page);
    await expect(confirm).toContainText(/integrità|Confermi/i);
    await page.click('.swal2-confirm');
    // Result popup (idempotent maintenance op).
    await page.waitForSelector('.swal2-confirm', { timeout: 20000 });
    const result = page.locator('.swal2-popup');
    await expect(result).toContainText(/completata|fallita/i);
    await page.click('.swal2-confirm').catch(() => {});
  });

  // integrity_report.php:477 — performMaintenance (confirm-action)
  test('integrity:477 performMaintenance — confirm-action then result', async () => {
    await page.goto(`${BASE}/admin/maintenance/integrity-report`);
    // There are two performMaintenance() buttons; the bottom card one is fine.
    const btn = page.locator('button[onclick="performMaintenance()"]').last();
    await btn.waitFor({ state: 'visible', timeout: 15000 });
    await btn.click();
    const confirm = await waitSwal(page);
    await expect(confirm).toContainText(/manutenzione|Confermi/i);
    await page.click('.swal2-confirm');
    await page.waitForSelector('.swal2-confirm', { timeout: 30000 });
    const result = page.locator('.swal2-popup');
    await expect(result).toContainText(/completata|fallita/i);
    await page.click('.swal2-confirm').catch(() => {});
  });

  // integrity_report.php:515 — applyConfigFix (confirm-action). Button is only
  // rendered when a config issue exists, so we invoke the exposed window fn and
  // CANCEL (it would persist APP_CANONICAL_URL otherwise).
  test('integrity:515 applyConfigFix — confirm-action (cancelled)', async () => {
    await page.goto(`${BASE}/admin/maintenance/integrity-report`);
    await page.waitForSelector('button[onclick="recalculateAvailability()"]', { timeout: 15000 });
    await page.evaluate(() => {
      // @ts-ignore — exposed at window scope by the view
      window.applyConfigFix('app_canonical_url', 'http://localhost:8081');
    });
    const popup = await waitSwal(page);
    await expect(popup).toContainText(/APP_CANONICAL_URL|Confermi/i);
    // Cancel — do NOT mutate the canonical URL on the shared install.
    await page.click('.swal2-cancel');
  });

  // integrity_report.php:558 — createMissingIndexes (confirm-action). Idempotent
  // (CREATE ... IF NOT EXISTS); invoke via window fn, confirm, assert result.
  test('integrity:558 createMissingIndexes — confirm-action then result', async () => {
    await page.goto(`${BASE}/admin/maintenance/integrity-report`);
    await page.waitForSelector('button[onclick="recalculateAvailability()"]', { timeout: 15000 });
    await page.evaluate(() => {
      // @ts-ignore
      window.createMissingIndexes();
    });
    const confirm = await waitSwal(page);
    await expect(confirm).toContainText(/indici|Confermi/i);
    await page.click('.swal2-confirm');
    await page.waitForSelector('.swal2-confirm', { timeout: 20000 });
    const result = page.locator('.swal2-popup');
    await expect(result).toContainText(/completata|fallita/i);
    await page.click('.swal2-confirm').catch(() => {});
  });

  // integrity_report.php:603 — createMissingSystemTables (confirm-action).
  // Idempotent (CREATE TABLE IF NOT EXISTS); invoke via window fn.
  test('integrity:603 createMissingSystemTables — confirm-action then result', async () => {
    await page.goto(`${BASE}/admin/maintenance/integrity-report`);
    await page.waitForSelector('button[onclick="recalculateAvailability()"]', { timeout: 15000 });
    await page.evaluate(() => {
      // @ts-ignore
      window.createMissingSystemTables();
    });
    const confirm = await waitSwal(page);
    await expect(confirm).toContainText(/tabelle|Confermi/i);
    await page.click('.swal2-confirm');
    await page.waitForSelector('.swal2-confirm', { timeout: 20000 });
    const result = page.locator('.swal2-popup');
    await expect(result).toContainText(/completata|fallita/i);
    await page.click('.swal2-confirm').catch(() => {});
  });

  // ════════════════════════════════════════════════════════════════════
  // admin/plugins.php
  // ════════════════════════════════════════════════════════════════════

  // Resolve an inactive plugin id we can safely toggle (prefer open-library).
  async function resolveTogglePluginId() {
    let id = dbQuery(`SELECT id FROM plugins WHERE name='open-library' LIMIT 1`).trim();
    if (!id) id = dbQuery(`SELECT id FROM plugins ORDER BY id LIMIT 1`).trim();
    return id ? parseInt(id, 10) : 0;
  }

  // plugins.php:1386 — activatePlugin (confirm-action)
  test('plugins:1386 activatePlugin — confirm-action then state change', async () => {
    const pid = await resolveTogglePluginId();
    test.skip(!pid, 'No plugin rows present to toggle');
    // Ensure starting state = inactive.
    dbExec(`UPDATE plugins SET is_active=0 WHERE id=${pid}`);
    await page.goto(`${BASE}/admin/plugins`);
    await page.waitForSelector(`[data-plugin-id="${pid}"]`, { timeout: 15000 });
    await page.evaluate((id) => {
      // @ts-ignore exposed by view
      window.activatePlugin ? window.activatePlugin(id) : activatePlugin(id);
    }, pid);
    const confirm = await waitSwal(page);
    await expect(confirm).toContainText(/attivare|Conferma/i);
    await page.click('.swal2-confirm');
    // success popup
    await page.waitForSelector('.swal2-confirm, .swal2-popup', { timeout: 20000 });
    await page.waitForTimeout(1000);
    const active = dbQuery(`SELECT is_active FROM plugins WHERE id=${pid}`).trim();
    expect(active).toBe('1');
  });

  // plugins.php:1439 — deactivatePlugin (confirm-action). Restores inactive state.
  test('plugins:1439 deactivatePlugin — confirm-action then state change', async () => {
    const pid = await resolveTogglePluginId();
    test.skip(!pid, 'No plugin rows present to toggle');
    dbExec(`UPDATE plugins SET is_active=1 WHERE id=${pid}`);
    await page.goto(`${BASE}/admin/plugins`);
    await page.waitForSelector(`[data-plugin-id="${pid}"]`, { timeout: 15000 });
    await page.evaluate((id) => {
      // @ts-ignore
      window.deactivatePlugin ? window.deactivatePlugin(id) : deactivatePlugin(id);
    }, pid);
    const confirm = await waitSwal(page);
    await expect(confirm).toContainText(/disattivare|Conferma/i);
    const responsePromise = page.waitForResponse(
      (response) => response.request().method() === 'POST'
        && response.url().endsWith(`/admin/plugins/${pid}/deactivate`),
      { timeout: 20000 }
    );
    await page.click('.swal2-confirm');
    const response = await responsePromise;
    expect(response.status()).toBe(200);
    expect((await response.json()).success).toBe(true);
    await expect.poll(
      () => dbQuery(`SELECT is_active FROM plugins WHERE id=${pid}`).trim(),
      { timeout: 8000 }
    ).toBe('0');
  });

  // plugins.php:1493 — uninstallPlugin (confirm-destructive). A real uninstall
  // wipes plugin files/data on the shared install, so we surface the destructive
  // confirm and CANCEL (assert the dialog is shown + destructive copy).
  test('plugins:1493 uninstallPlugin — confirm-destructive (cancelled)', async () => {
    const pid = await resolveTogglePluginId();
    test.skip(!pid, 'No plugin rows present');
    await page.goto(`${BASE}/admin/plugins`);
    await page.waitForSelector(`[data-plugin-id="${pid}"]`, { timeout: 15000 });
    await page.evaluate((id) => {
      // @ts-ignore
      uninstallPlugin(id, 'E2E Plugin');
    }, pid);
    const popup = await waitSwal(page);
    await expect(page.locator('.swal2-icon.swal2-error')).toBeVisible();
    await expect(popup).toContainText(/disinstallare|annullata/i);
    await page.click('.swal2-cancel');
    // Plugin must still exist (not uninstalled).
    const exists = dbQuery(`SELECT COUNT(*) FROM plugins WHERE id=${pid}`).trim();
    expect(parseInt(exists, 10)).toBe(1);
  });

  // plugins.php:1576 — saveGoogleBooksKey (form-prompt → success toast).
  test('plugins:1576 saveGoogleBooksKey — success toast', async () => {
    const olId = dbQuery(`SELECT id FROM plugins WHERE name='open-library' LIMIT 1`).trim();
    test.skip(!olId, 'open-library plugin not registered');
    await page.goto(`${BASE}/admin/plugins`);
    await page.waitForSelector(`[data-plugin-id="${olId}"]`, { timeout: 15000 });
    // Open the Google Books settings modal via its trigger button.
    const settingsBtn = page.locator(`button[data-plugin-id="${olId}"][data-plugin-type="open-library"]`).first();
    await expect(settingsBtn).toBeVisible();
    await settingsBtn.click();
    const keyInput = page.locator('#googleBooksKeyInput');
    await keyInput.waitFor({ state: 'visible', timeout: 8000 });
    await keyInput.fill('E2E-TEST-GOOGLE-BOOKS-KEY');
    await page.click('#pluginSettingsModal button[data-role="save-key"]');
    const popup = await waitSwal(page);
    await expect(popup).toContainText(/Successo|Google Books|aggiornata/i);
    await page.click('.swal2-confirm').catch(() => {});
    // Clear the test key so it doesn't linger.
    try { dbExec(`DELETE FROM plugin_settings WHERE plugin_id=${olId} AND setting_key='google_books_api_key'`); } catch { /* */ }
  });

  // plugins.php:1330 — plugin ZIP upload (form-prompt → error alert).
  // We trigger upload with no file selected to surface the validation error.
  test('plugins:1330 plugin ZIP upload — error alert (no file)', async () => {
    await page.goto(`${BASE}/admin/plugins`);
    await page.waitForSelector('#uploadButton', { timeout: 15000 }).catch(() => {});
    const hasBtn = await page.locator('#uploadButton').count();
    test.skip(hasBtn === 0, 'upload button not present');
    // Force-enable + click without a selected file → "Seleziona un file ZIP".
    await page.evaluate(() => {
      const b = document.getElementById('uploadButton');
      if (b) { b.removeAttribute('disabled'); b.click(); }
    });
    const popup = await waitSwal(page);
    await expect(page.locator('.swal2-icon.swal2-error')).toBeVisible();
    await expect(popup).toContainText(/ZIP|Errore/i);
    await page.click('.swal2-confirm').catch(() => {});
  });

  // plugins.php:1704 — saveApiBookScraperSettings (form-prompt → success toast).
  test('plugins:1704 saveApiBookScraperSettings — success toast', async () => {
    const apiId = dbQuery(`SELECT id FROM plugins WHERE name='api-book-scraper' LIMIT 1`).trim();
    test.skip(!apiId, 'api-book-scraper plugin not registered');
    await page.goto(`${BASE}/admin/plugins`);
    await page.waitForSelector(`[data-plugin-id="${apiId}"]`, { timeout: 15000 });
    const settingsBtn = page.locator(`button[data-plugin-id="${apiId}"][data-plugin-type="api-book-scraper"]`).first();
    await expect(settingsBtn).toBeVisible();
    await settingsBtn.click();
    await page.waitForSelector('#apiBookScraperModal:not(.hidden)', { timeout: 8000 });
    await page.fill('#apiEndpointInput', 'https://example.test/api/book');
    await page.fill('#apiTimeoutInput', '10');
    await page.click('#apiBookScraperModal button[type="submit"]');
    const popup = await waitSwal(page);
    await expect(popup).toContainText(/Successo|salvat/i);
    // Closing the success alert reloads the plugins page. Await that reload so
    // it cannot race the next test's navigation to /admin/updates.
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 10000 }).catch(() => null),
      page.click('.swal2-confirm').catch(() => {}),
    ]);
  });

  // ════════════════════════════════════════════════════════════════════
  // admin/updates.php
  // ════════════════════════════════════════════════════════════════════

  // updates.php:562 — saveGitHubToken (form-prompt → warning/error alert).
  // Empty token surfaces the "Inserisci un token valido" warning.
  test('updates:562 saveGitHubToken — warning alert (empty)', async () => {
    await page.goto(`${BASE}/admin/updates`);
    await page.waitForSelector('#github-token', { timeout: 15000 });
    await page.fill('#github-token', '');
    await page.evaluate(() => {
      // @ts-ignore exposed by view
      saveGitHubToken();
    });
    const popup = await waitSwal(page);
    await expect(popup).toContainText(/token valido|Attenzione/i);
    await page.click('.swal2-confirm').catch(() => {});
  });

  // updates.php:598 — removeGitHubToken (confirm-destructive). The button only
  // renders when a token is saved; invoke the window fn and confirm (posting an
  // empty token is a no-op when none is configured).
  test('updates:598 removeGitHubToken — confirm-destructive then result', async () => {
    await page.goto(`${BASE}/admin/updates`);
    await page.waitForSelector('#github-token', { timeout: 15000 });
    // Make the token POST deterministic (success), so the result Swal shows
    // reliably instead of depending on real GitHub/DB state. We assert the
    // result popup but do NOT dismiss it — its onClose triggers a
    // location.reload() that would otherwise race the test to a 2-minute hang.
    await page.route('**/admin/updates/token', route =>
      route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, message: 'Token rimosso' }) }),
    );
    await page.evaluate(() => {
      // @ts-ignore
      removeGitHubToken();
    });
    const confirm = await waitSwal(page);
    await expect(confirm).toContainText(/token|60\/ora|Rimuovere/i);
    // Confirm the destructive warning -> POST -> success result Swal.
    await page.locator('.swal2-confirm').click();
    await expect(page.locator('.swal2-icon.swal2-success')).toBeVisible({ timeout: 10000 });
    await page.unroute('**/admin/updates/token');
  });

  // updates.php:676 — createBackup (confirm-action). Surface confirm and CANCEL
  // to avoid a full DB dump on the shared install.
  test('updates:676 createBackup — confirm-action (cancelled)', async () => {
    await page.goto(`${BASE}/admin/updates`);
    await page.waitForSelector('button[onclick="createBackup()"]', { timeout: 15000 });
    await page.click('button[onclick="createBackup()"]');
    const popup = await waitSwal(page);
    await expect(popup).toContainText(/backup/i);
    await page.click('.swal2-cancel');
  });

  // updates.php:731 — startUpdate (confirm-action). A real update would rewrite
  // the install, so surface the confirm via window fn and CANCEL.
  test('updates:731 startUpdate — confirm-action (cancelled)', async () => {
    await page.goto(`${BASE}/admin/updates`);
    await page.waitForSelector('#github-token', { timeout: 15000 });
    await page.evaluate(() => {
      // @ts-ignore
      startUpdate('99.99.99');
    });
    const popup = await waitSwal(page);
    await expect(popup).toContainText(/aggiornare|99\.99\.99|Conferma/i);
    await page.click('.swal2-cancel');
  });

  // updates.php:990 — deleteBackup (confirm-destructive). Uses the fs fixture.
  test('updates:990 deleteBackup — confirm-destructive then removed', async () => {
    const dir = path.join(BACKUP_DIR, BACKUP_FIXTURE);
    test.skip(!fs.existsSync(dir), 'backup fixture dir missing (not writable)');
    await page.goto(`${BASE}/admin/updates`);
    await page.waitForSelector('#github-token', { timeout: 15000 });
    await page.evaluate((name) => {
      // @ts-ignore
      deleteBackup(name);
    }, BACKUP_FIXTURE);
    const confirm = await waitSwal(page);
    await expect(confirm).toContainText(/backup|Eliminare/i);
    await page.click('.swal2-confirm');
    await page.waitForTimeout(1500);
    expect(fs.existsSync(dir)).toBe(false);
  });

  // updates.php:1187 — submitManualUpdate (confirm-action → error alert when no
  // file selected). With no uploaded ZIP, surfaces "Seleziona un file ZIP".
  test('updates:1187 submitManualUpdate — error alert (no file)', async () => {
    await page.goto(`${BASE}/admin/updates`);
    await page.waitForSelector('#github-token', { timeout: 15000 });
    await page.evaluate(() => {
      // @ts-ignore
      if (typeof uploadedFile !== 'undefined') { /* keep undefined */ }
      // @ts-ignore
      submitManualUpdate();
    });
    const popup = await waitSwal(page);
    await expect(page.locator('.swal2-icon.swal2-error')).toBeVisible();
    await expect(popup).toContainText(/ZIP|Errore/i);
    await page.click('.swal2-confirm').catch(() => {});
  });
});
