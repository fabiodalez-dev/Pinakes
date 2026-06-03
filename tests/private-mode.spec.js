// @ts-check
// E2E for issue #158 — admin-toggleable "private mode" that restricts the whole
// public site to authenticated users. Off by default.
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

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
  return execFileSync('mysql', args, { encoding: 'utf-8', timeout: 10000, env: { ...process.env, MYSQL_PWD: DB_PASS } }).trim();
}

function setPrivateMode(on) {
  if (on) {
    dbQuery("INSERT INTO system_settings (category,setting_key,setting_value,created_at) VALUES ('advanced','private_mode','1',NOW()) ON DUPLICATE KEY UPDATE setting_value='1'");
  } else {
    dbQuery("DELETE FROM system_settings WHERE category='advanced' AND setting_key='private_mode'");
  }
}

async function loginAsAdmin(page) {
  await page.goto(`${BASE}/accedi`);
  await page.fill('input[name="email"]', ADMIN_EMAIL);
  await page.fill('input[name="password"]', ADMIN_PASS);
  await page.locator('button[type="submit"]').click();
  await page.waitForURL((url) => !url.toString().includes('/accedi'), { timeout: 15000 });
}

test.describe.serial('Private mode (issue #158)', () => {
  test.beforeAll(() => setPrivateMode(false));
  test.afterAll(() => setPrivateMode(false));

  test('1. Default off: home is reachable while logged out', async ({ page }) => {
    setPrivateMode(false);
    const resp = await page.goto(`${BASE}/`);
    expect(resp?.status()).toBe(200);
    expect(page.url()).not.toContain('/accedi');
  });

  test('2. Enabled: logged-out home redirects to login', async ({ page }) => {
    setPrivateMode(true);
    await page.goto(`${BASE}/`);
    await expect(page).toHaveURL(/\/accedi/);
  });

  test('3. Enabled: the login page itself stays reachable', async ({ page }) => {
    setPrivateMode(true);
    const resp = await page.goto(`${BASE}/accedi`);
    expect(resp?.status()).toBe(200);
    await expect(page.locator('input[name="email"]')).toBeVisible();
  });

  test('4. Enabled: register page stays reachable', async ({ page }) => {
    setPrivateMode(true);
    const resp = await page.goto(`${BASE}/registrati`);
    expect(resp?.status()).toBe(200);
  });

  test('5. Enabled: an API request without auth returns 401 JSON', async ({ request }) => {
    setPrivateMode(true);
    const resp = await request.get(`${BASE}/api/books/1/availability`);
    expect(resp.status()).toBe(401);
  });

  test('6. Enabled: a logged-in admin can browse the public site', async ({ page }) => {
    setPrivateMode(true);
    await loginAsAdmin(page);
    const resp = await page.goto(`${BASE}/`);
    expect(resp?.status()).toBe(200);
    expect(page.url()).not.toContain('/accedi');
  });

  test('7. Enabled: private uploaded content is NOT served while logged out (#160)', async ({ request }) => {
    setPrivateMode(true);
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
    setPrivateMode(true);
    // Public upload subtrees are still served straight from disk — a missing
    // file yields a plain 404, never a redirect to the login page.
    for (const path of ['/uploads/copertine/__e2e_nope__.jpg', '/uploads/settings/__e2e_nope__.png']) {
      const resp = await request.get(`${BASE}${path}`, { maxRedirects: 0 });
      expect(resp.status(), `${path} must not redirect`).not.toBe(302);
    }
  });

  test('9. Enabled: an API-key-protected route is reached, not blanket-401\'d (#160)', async ({ request }) => {
    setPrivateMode(true);
    // /api/public/* gates itself with ApiKeyMiddleware. Private mode must defer
    // to it instead of pre-empting with its own session 401 — so the response
    // is the route's own (API key / feature-gate), never the private payload.
    const resp = await request.get(`${BASE}/api/public/books/search?q=test`);
    const body = await resp.text();
    expect(body).not.toContain('Autenticazione richiesta');
  });

  test('10. Disabled again: home is public for everyone', async ({ page }) => {
    setPrivateMode(false);
    const resp = await page.goto(`${BASE}/`);
    expect(resp?.status()).toBe(200);
    expect(page.url()).not.toContain('/accedi');
  });
});
