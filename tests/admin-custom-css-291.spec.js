// @ts-check
// Issue #291: admin-configured custom CSS (advanced.custom_header_css) did not
// apply on admin pages (only frontend + auth), so a rule meant to hide unused
// fields on the book form had no effect. The fix emits the sanitized custom CSS
// in the admin layout's <head> too. This asserts the CSS actually APPLIES on
// /admin/books/edit — a rule hiding #ean makes the field display:none.
//
// Run: /tmp/run-e2e.sh tests/admin-custom-css-291.spec.js --config=tests/playwright.config.js --workers=1
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';
const DB_NAME = process.env.E2E_DB_NAME || '';

test.skip(!ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME, 'E2E credentials not configured');

const MARKER = '/* PINAKES291SPEC */';
const CUSTOM_CSS = `${MARKER} input#ean { display: none !important; }`;

function db(sql) {
  const args = [];
  if (DB_SOCKET) args.push('-S', DB_SOCKET);
  args.push('-u', DB_USER, DB_NAME, '-N', '-B', '-e', sql);
  return execFileSync('mysql', args, { encoding: 'utf-8', timeout: 10000, env: { ...process.env, MYSQL_PWD: DB_PASS } }).trim();
}

let bookId = 0;
let hadPrior = false;
let priorValue = '';

test.beforeAll(() => {
  bookId = parseInt(db("SELECT id FROM libri WHERE deleted_at IS NULL ORDER BY id LIMIT 1"), 10);
  // Preserve any existing custom CSS, then set our test rule. Use EXISTS to tell
  // an absent row from a present-but-empty value, so afterAll restores a real
  // empty configuration instead of deleting it.
  hadPrior = db("SELECT EXISTS(SELECT 1 FROM system_settings WHERE category='advanced' AND setting_key='custom_header_css')") === '1';
  priorValue = hadPrior
    ? db("SELECT setting_value FROM system_settings WHERE category='advanced' AND setting_key='custom_header_css'")
    : '';
  db(`INSERT INTO system_settings (category, setting_key, setting_value, updated_at)
      VALUES ('advanced','custom_header_css',${sqlq(CUSTOM_CSS)}, NOW())
      ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_at=NOW()`);
});

test.afterAll(() => {
  if (hadPrior) {
    db(`UPDATE system_settings SET setting_value=${sqlq(priorValue)} WHERE category='advanced' AND setting_key='custom_header_css'`);
  } else {
    db("DELETE FROM system_settings WHERE category='advanced' AND setting_key='custom_header_css'");
  }
});

function sqlq(s) { return "'" + String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'"; }

test('#291: admin custom CSS applies on the book edit form', async ({ page }) => {
  expect(bookId).toBeGreaterThan(0);

  await page.goto(`${BASE}/accedi`);
  await page.fill('input[name="email"]', ADMIN_EMAIL);
  await page.fill('input[name="password"]', ADMIN_PASS);
  await page.locator('button[type="submit"]').click();
  await page.waitForFunction(
    () => !window.location.pathname.includes('accedi') && !window.location.pathname.includes('login'),
    { timeout: 15000 },
  );

  await page.goto(`${BASE}/admin/books/edit/${bookId}`);
  await page.waitForLoadState('networkidle');

  // The sanitized custom CSS must be present in a <head> <style> block.
  const inHead = await page.evaluate((marker) => {
    return [...document.head.querySelectorAll('style')].some(s => s.textContent.includes(marker));
  }, 'PINAKES291SPEC');
  expect(inHead, 'custom CSS emitted in admin <head>').toBe(true);

  // And it must actually take effect: #ean must exist on the book form AND be
  // hidden by the custom CSS — the exact behaviour the issue asks for. No `if`
  // guard: a redirect or a form that failed to render must fail the test, not
  // silently skip the assertion.
  const ean = page.locator('input#ean');
  await expect(ean).toHaveCount(1);
  await expect(ean).toHaveCSS('display', 'none');
});
