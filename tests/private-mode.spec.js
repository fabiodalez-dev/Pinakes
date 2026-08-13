// @ts-check
// E2E for issue #158 — admin-toggleable "private mode" that restricts the whole
// public site to authenticated users. Off by default.
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS || '';
const DB_HOST = process.env.E2E_DB_HOST || '';
const DB_PORT = process.env.E2E_DB_PORT || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';
const DB_NAME = process.env.E2E_DB_NAME || '';

test.skip(
  !ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME,
  'E2E credentials not configured',
);

function dbQuery(sql) {
  const args = [];
  if (DB_HOST) { args.push('-h', DB_HOST); if (DB_PORT) args.push('-P', DB_PORT); }
  else if (DB_SOCKET) { args.push('-S', DB_SOCKET); }
  args.push('-u', DB_USER, DB_NAME, '-N', '-B', '-e', sql);
  // Pass the password via a private 0600 defaults-file rather than MYSQL_PWD
  // (deprecated, can leak through the environment) or -p<pass> (visible in
  // `ps`). The temp file is always removed in finally.
  const cnf = path.join(os.tmpdir(), `pinakes-e2e-${process.pid}-${Date.now()}.cnf`);
  fs.writeFileSync(cnf, `[client]\npassword="${DB_PASS}"\n`, { mode: 0o600 });
  try {
    return execFileSync('mysql', [`--defaults-extra-file=${cnf}`, ...args], {
      encoding: 'utf-8', timeout: 10000,
    }).trim();
  } catch (err) {
    // Surface a clear, actionable error instead of an opaque execFileSync throw.
    const detail = (err && (err.stderr || err.message)) || String(err);
    throw new Error(`dbQuery failed: ${detail}\n  SQL: ${sql}`);
  } finally {
    try { fs.unlinkSync(cnf); } catch { /* best effort cleanup */ }
  }
}

async function loginAsAdmin(page) {
  await page.goto(`${BASE}/accedi`);
  await page.fill('input[name="email"]', ADMIN_EMAIL);
  await page.fill('input[name="password"]', ADMIN_PASS);
  await page.locator('button[type="submit"]').click();
  await page.waitForURL((url) => !url.toString().includes('/accedi'), { timeout: 15000 });
}

/**
 * Toggle private mode through the real admin form. A direct SQL update cannot
 * invalidate APCu in the Apache process, which made the E2E result depend on
 * which cache backend happened to be active on the runner.
 */
async function setPrivateMode(adminPage, on) {
  await adminPage.goto(`${BASE}/admin/settings?tab=advanced`);
  const toggle = adminPage.locator('#private_mode');
  await toggle.waitFor({ state: 'visible', timeout: 10000 });
  if (on) await toggle.check();
  else await toggle.uncheck();

  const result = await toggle.evaluate(async (el) => {
    const form = el.closest('form');
    if (!form) return { status: 0, url: '' };
    const response = await fetch(form.action, {
      method: 'POST',
      body: new FormData(form),
      credentials: 'same-origin',
    });
    return { status: response.status, url: response.url };
  });
  expect(result.status).toBe(200);
  expect(new URL(result.url).pathname).toBe('/admin/settings');

  const stored = dbQuery("SELECT COALESCE(MAX(setting_value), '0') FROM system_settings WHERE category='advanced' AND setting_key='private_mode'");
  expect(stored).toBe(on ? '1' : '0');
}

test.describe.serial('Private mode (issue #158)', () => {
  let adminContext;
  let adminPage;

  test.beforeAll(async ({ browser }) => {
    adminContext = await browser.newContext();
    adminPage = await adminContext.newPage();
    await loginAsAdmin(adminPage);
    await setPrivateMode(adminPage, false);
  });

  test.afterAll(async () => {
    try {
      if (adminPage) await setPrivateMode(adminPage, false);
    } finally {
      await adminContext?.close();
    }
  });

  test('1. Default off: home is reachable while logged out', async ({ page }) => {
    await setPrivateMode(adminPage, false);
    const resp = await page.goto(`${BASE}/`);
    expect(resp?.status()).toBe(200);
    expect(page.url()).not.toContain('/accedi');
  });

  test('2. Enabled: logged-out home redirects to login', async ({ page }) => {
    await setPrivateMode(adminPage, true);
    await page.goto(`${BASE}/`);
    await expect(page).toHaveURL(/\/accedi/);
  });

  test('3. Enabled: the login page itself stays reachable', async ({ page }) => {
    await setPrivateMode(adminPage, true);
    const resp = await page.goto(`${BASE}/accedi`);
    expect(resp?.status()).toBe(200);
    await expect(page.locator('input[name="email"]')).toBeVisible();
  });

  test('4. Enabled: register page stays reachable', async ({ page }) => {
    await setPrivateMode(adminPage, true);
    const resp = await page.goto(`${BASE}/registrati`);
    expect(resp?.status()).toBe(200);
  });

  test('5. Enabled: an API request without auth returns 401 JSON', async ({ request }) => {
    await setPrivateMode(adminPage, true);
    const resp = await request.get(`${BASE}/api/books/1/availability`);
    expect(resp.status()).toBe(401);
  });

  test('6. Enabled: a logged-in admin can browse the public site', async ({ page }) => {
    await setPrivateMode(adminPage, true);
    await loginAsAdmin(page);
    const resp = await page.goto(`${BASE}/`);
    expect(resp?.status()).toBe(200);
    expect(page.url()).not.toContain('/accedi');
  });

  test('7. Enabled: private uploaded content is NOT served while logged out (#160)', async ({ request }) => {
    await setPrivateMode(adminPage, true);
    // Digital-library files, archive documents and generic storage are routed
    // through PHP (public/.htaccess) so private mode governs them. A logged-out
    // request must be redirected to login, not served the bytes.
    for (const path of [
      '/uploads/digital/__e2e_nope__.pdf',
      '/uploads/archives/documents/__e2e_nope__.pdf',
      '/uploads/storage/__e2e_nope__.bin',
    ]) {
      const resp = await request.get(`${BASE}${path}`, { maxRedirects: 0 });
      expect(resp.status(), `${path} must redirect to login`).toBe(302);
      expect(resp.headers()['location'] || '').toMatch(/\/accedi/);
    }
  });

  test('8. Enabled: PUBLIC uploads (covers, branding) are NOT login-walled (#160)', async ({ request }) => {
    await setPrivateMode(adminPage, true);
    // Public upload subtrees are still served straight from disk — a missing
    // file yields a plain 404, never a redirect to the login page.
    for (const path of ['/uploads/copertine/__e2e_nope__.jpg', '/uploads/settings/__e2e_nope__.png']) {
      const resp = await request.get(`${BASE}${path}`, { maxRedirects: 0 });
      expect(resp.status(), `${path} must not redirect`).not.toBe(302);
    }
  });

  test('9. Enabled: an API-key-protected route is reached, not blanket-401\'d (#160)', async ({ request }) => {
    await setPrivateMode(adminPage, true);
    // /api/public/* gates itself with ApiKeyMiddleware. Private mode must defer
    // to it instead of pre-empting with its own session 401 — so the response
    // is the route's own (API key / feature-gate), never the private payload.
    const resp = await request.get(`${BASE}/api/public/books/search?q=test`);
    // The response must be ApiKeyMiddleware's OWN JSON gate (401 missing key /
    // 403 API disabled) with a matching status — proving private mode actually
    // reached the route and deferred to it, instead of pre-empting with its own
    // session 401/redirect. A bare "not.toContain" could pass on any unrelated
    // response, so assert the gate's shape explicitly.
    expect([401, 403]).toContain(resp.status());
    expect(resp.headers()['content-type'] || '').toContain('application/json');
    const body = await resp.text();
    expect(body).not.toContain('Autenticazione richiesta');
    const json = JSON.parse(body);
    expect(json).toMatchObject({ status: resp.status() });
    expect(json).toHaveProperty('error');
  });

  test('10. Disabled again: home is public for everyone', async ({ page }) => {
    await setPrivateMode(adminPage, false);
    const resp = await page.goto(`${BASE}/`);
    expect(resp?.status()).toBe(200);
    expect(page.url()).not.toContain('/accedi');
  });
});
