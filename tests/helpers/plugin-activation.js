// @ts-check
/**
 * One home for "this spec needs an optional bundled plugin to be active".
 *
 * Every idiom this replaces had the same two hazards. The first is flipping
 * `plugins.is_active` in SQL: PluginManager caches the active set across
 * requests, so the database says active while the routes keep answering 404 —
 * the failure is invisible until a locator times out. The second is asserting
 * only on the HTTP status: a refusal (an unmet `requires_app`, a failing
 * `ensureSchema()`) answers 200 with `success:false` and its own message, and
 * that message is the only thing that explains the run.
 *
 * So activation always goes through the real admin endpoint with a real CSRF
 * token — the same path an operator clicks — and the endpoint's own message is
 * surfaced verbatim on failure.
 */
const { expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');

const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS || '';
const DB_NAME = process.env.E2E_DB_NAME || '';
const DB_HOST = process.env.E2E_DB_HOST || '';
const DB_PORT = process.env.E2E_DB_PORT || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';

function dbQuery(sql) {
  const args = [];
  if (DB_HOST) args.push('-h', DB_HOST);
  if (DB_PORT) args.push('-P', DB_PORT);
  if (!DB_HOST && DB_SOCKET) args.push('-S', DB_SOCKET);
  args.push('-u', DB_USER, DB_NAME, '-N', '-B', '-e', sql);
  return execFileSync('mysql', args, {
    encoding: 'utf-8',
    timeout: 10000,
    env: { ...process.env, MYSQL_PWD: DB_PASS },
  }).trim();
}

/**
 * Make sure a registered plugin is active, activating it through the admin UI's
 * own endpoint when it is not.
 *
 * @param {import('@playwright/test').Page} page a page already logged in as an admin
 * @param {string} pluginName the `plugins.name` value, e.g. 'desiderata'
 * @param {{ base?: string, smokePath?: string }} [options]
 *        `smokePath` is a route the plugin registers; it is requested after
 *        activation and must not answer 404. Any other status is accepted:
 *        a 302 to the login page or a 403 still proves the route exists.
 * @returns {Promise<number>} the plugin id
 */
async function ensurePluginActive(page, pluginName, options = {}) {
  const base = options.base || process.env.E2E_BASE_URL || process.env.APP_URL || 'http://localhost:8081';
  const quoted = String(pluginName).replace(/'/g, "''");

  // Two columns, never CONCAT with a tab: `mysql -B` escapes control characters
  // *inside* a value, so a tab placed there comes back as the two characters
  // `\` and `t` and the split below silently yields NaN. The separator between
  // columns is a real tab and is not escaped, which is what we split on.
  const row = dbQuery(`SELECT id, is_active FROM plugins WHERE name='${quoted}' LIMIT 1`);
  expect(row, `plugin '${pluginName}' must be registered in the plugins table`).toBeTruthy();
  const [rawId, rawActive] = row.split('\t');
  const id = Number(rawId);
  expect(id, `plugin '${pluginName}' must have a numeric id`).toBeGreaterThan(0);

  if (Number(rawActive) !== 1) {
    await page.goto(`${base}/admin/plugins`);
    await page.waitForLoadState('domcontentloaded');
    const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    expect(csrf, 'the admin plugins page must expose a CSRF token').toBeTruthy();

    const result = await page.evaluate(async ({ url, token }) => {
      const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-CSRF-Token': token, 'Content-Type': 'application/json' },
        body: '{}',
      });
      let body = {};
      try { body = await response.json(); } catch { /* asserted by the caller */ }
      return { status: response.status, body };
    }, { url: `${base}/admin/plugins/${id}/activate`, token: csrf });

    // The endpoint's own message is the diagnosis: a version refusal reads as
    // a version refusal instead of a locator timeout three tests later.
    expect(result.status, `${pluginName} activation HTTP status`).toBe(200);
    expect(
      result.body.success,
      `${pluginName} activation refused: ${result.body.message || '(no message returned)'}`,
    ).toBe(true);
  }

  const active = dbQuery(`SELECT is_active FROM plugins WHERE id=${id}`);
  expect(active, `${pluginName} must be active after activation`).toBe('1');

  if (options.smokePath) {
    // DB-active but unrouted is the hidden failure mode; a 404 here is the
    // only way to see it before the first locator times out.
    const smoke = await page.request.get(`${base}${options.smokePath}`);
    expect(
      smoke.status(),
      `${pluginName} route ${options.smokePath} must exist once the plugin is active`,
    ).not.toBe(404);
  }

  return id;
}

module.exports = { ensurePluginActive, dbQuery };
