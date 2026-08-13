// @ts-check
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS || '';
const DB_NAME = process.env.E2E_DB_NAME || '';
const DB_HOST = process.env.E2E_DB_HOST || '';
const DB_PORT = process.env.E2E_DB_PORT || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';
const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';

const INTEROP_PLUGINS = [
  'bibframe-linked-data',
  'discogs',
  'ncip-server',
  'oai-pmh-server',
  'open-library',
  'openurl-resolver',
  'resource-sync',
  'viaf-authority',
  'z39-server',
];

function mysqlArgs(sql, batch = false) {
  const args = [];
  if (DB_HOST) args.push('-h', DB_HOST);
  if (DB_PORT) args.push('-P', DB_PORT);
  if (!DB_HOST && DB_SOCKET) args.push('-S', DB_SOCKET);
  args.push('-u', DB_USER, DB_NAME);
  if (batch) args.push('-N', '-B');
  args.push('-e', sql);
  return args;
}

const MYSQL_ENV = () => ({ ...process.env, MYSQL_PWD: DB_PASS });

function dbQuery(sql) {
  return execFileSync('mysql', mysqlArgs(sql, true), {
    encoding: 'utf-8',
    timeout: 10000,
    env: MYSQL_ENV(),
  }).trim();
}

test.skip(!DB_USER || !DB_NAME || !ADMIN_EMAIL || !ADMIN_PASS, 'Missing E2E DB/admin environment');

async function loginAsAdmin(page) {
  await page.goto(`${BASE}/admin/dashboard`);
  const email = page.locator('input[name="email"]');
  if (await email.isVisible({ timeout: 3000 }).catch(() => false)) {
    await email.fill(ADMIN_EMAIL);
    await page.locator('input[name="password"]').fill(ADMIN_PASS);
    await page.locator('button[type="submit"]').click();
    await page.waitForURL(/.*(?:dashboard|admin).*/, { timeout: 15000 });
  }
}

test('interop preflight: bundled linked-data plugins are active before interop suites', async ({ page, request }) => {
  const quoted = INTEROP_PLUGINS.map((name) => `'${name}'`).join(',');
  await loginAsAdmin(page);
  await page.goto(`${BASE}/admin/plugins`);
  const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
  expect(csrf, 'admin plugin page exposes a CSRF token').toBeTruthy();

  const plugins = dbQuery(
    `SELECT id, name, is_active FROM plugins WHERE name IN (${quoted}) ORDER BY name`
  ).split('\n').filter(Boolean).map((row) => {
    const [id, name, active] = row.split('\t');
    return { id: Number(id), name, active: Number(active) };
  });
  expect(plugins).toHaveLength(INTEROP_PLUGINS.length);

  // Use the real lifecycle endpoint instead of flipping is_active in SQL. The
  // latter leaves PluginManager's cross-request cache stale for five minutes,
  // so the DB says active while routes remain 404 — exactly the hidden failure
  // this preflight is meant to prevent.
  for (const plugin of plugins.filter(({ active }) => active !== 1)) {
    const result = await page.evaluate(async ({ base, id, token }) => {
      const response = await fetch(`${base}/admin/plugins/${id}/activate`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-CSRF-Token': token, 'Content-Type': 'application/json' },
        body: '{}',
      });
      let body = {};
      try { body = await response.json(); } catch { /* asserted below */ }
      return { status: response.status, body };
    }, { base: BASE, id: plugin.id, token: csrf });
    expect(result.status, `${plugin.name} activation HTTP status`).toBe(200);
    expect(result.body.success, `${plugin.name}: ${result.body.message || 'activation failed'}`).toBe(true);
  }

  const rows = dbQuery(
    `SELECT CONCAT(name, '=', is_active)
       FROM plugins
      WHERE name IN (${quoted})
      ORDER BY name`
  ).split('\n').filter(Boolean);

  expect(rows).toHaveLength(INTEROP_PLUGINS.length);
  for (const row of rows) {
    expect(row).toMatch(/=1$/);
  }

  // Force a fresh unauthenticated request after lifecycle cache invalidation.
  // 403 proves the VIAF route exists; 404 would mean DB-active but unrouted.
  const noAuth = await request.get(`${BASE}/api/viaf/suggest?q=Test`);
  expect(noAuth.status()).toBe(403);
});
