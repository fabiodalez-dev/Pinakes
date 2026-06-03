// #153: bookcase ("scaffale") codes must allow multi-character values, and two
// bookcases that share a first letter (e.g. "L1" and "L2") must both be
// creatable — the legacy UNIQUE(lettera) constraint + app-level check used to
// reject the second one. Verified through the real create flow + DB assertion.
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';

function dbQuery(sql) {
  const args = ['-N', '-B', '-e', sql];
  if (process.env.E2E_DB_SOCKET) args.push('-S', process.env.E2E_DB_SOCKET);
  else if (process.env.E2E_DB_HOST) args.push('-h', process.env.E2E_DB_HOST);
  args.push('-u', process.env.E2E_DB_USER, process.env.E2E_DB_NAME);
  return execFileSync('mysql', args, {
    encoding: 'utf-8', timeout: 10000,
    env: { ...process.env, MYSQL_PWD: process.env.E2E_DB_PASS },
  }).trim();
}

const CODES = ['ZT1', 'ZT2']; // share first letter "Z" — the #153 case

function cleanup() {
  dbQuery(`DELETE FROM scaffali WHERE codice IN ('ZT1','ZT2')`);
}

test.describe.serial('#153 multi-character bookcase codes', () => {
  test.beforeAll(() => cleanup());
  test.afterAll(() => cleanup());

  test('two bookcase codes sharing a first letter both create', async ({ page }) => {
    test.setTimeout(60000);
    // login
    await page.goto(`${BASE}/admin/dashboard`);
    const email = page.locator('input[name="email"]');
    if (await email.isVisible({ timeout: 3000 }).catch(() => false)) {
      await email.fill(process.env.E2E_ADMIN_EMAIL);
      await page.fill('input[name="password"]', process.env.E2E_ADMIN_PASS);
      await page.click('button[type="submit"]');
      await page.waitForURL(/.*(?:dashboard|admin).*/, { timeout: 15000 });
    }
    await page.goto(`${BASE}/admin/placement`);
    const csrf = await page.locator('input[name="csrf_token"]').first().inputValue();

    for (const code of CODES) {
      const r = await page.request.post(`${BASE}/admin/placement/shelving-units`, {
        form: { csrf_token: csrf, codice: code, nome: `Bookcase ${code}`, ordine: '0' },
      });
      // The handler redirects (302) on both success and validation error; the
      // real assertion is the DB state below.
      expect([200, 302]).toContain(r.status());
    }

    const rows = dbQuery(`SELECT codice FROM scaffali WHERE codice IN ('ZT1','ZT2') ORDER BY codice`);
    const got = rows ? rows.split('\n').sort() : [];
    expect(got).toEqual(['ZT1', 'ZT2']); // both created despite sharing "Z"
  });
});
