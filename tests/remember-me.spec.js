// @ts-check
//
// Regression test for the "Remember me" checkbox being silently ignored.
//
// The login form posts the checkbox as `remember_me` (app/Views/auth/login.php),
// but AuthController::login used to read `$data['remember']`. The names never
// matched, so `$remember` was always false: no persistent token was created, no
// `remember_token` cookie was set, and the session always expired on the next
// PHP-session teardown despite the box being ticked.
//
// These tests pin the behaviour end-to-end through the real form:
//   1. box ticked   → a `remember_token` cookie is set AND a user_sessions row exists
//   2. box unticked → neither the cookie nor a row appears (fix is not "always on")
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS  = process.env.E2E_ADMIN_PASS  || '';

const DB_USER   = process.env.E2E_DB_USER   || '';
const DB_PASS   = process.env.E2E_DB_PASS   || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';
const DB_NAME   = process.env.E2E_DB_NAME   || '';

test.skip(
  !ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_PASS || !DB_NAME,
  'E2E credentials not configured',
);

function dbQuery(sql) {
  const args = ['-u', DB_USER, `-p${DB_PASS}`, DB_NAME, '-N', '-B', '-e', sql];
  if (DB_SOCKET) args.splice(3, 0, '-S', DB_SOCKET);
  return execFileSync('mysql', args, { encoding: 'utf-8', timeout: 10000 }).trim();
}

function sqlEscape(s) {
  return String(s).replace(/'/g, "''");
}

/** Log in through the real form; optionally tick the "Remember me" box. */
async function login(page, { remember }) {
  await page.goto(`${BASE}/accedi`);
  await page.fill('input[name="email"]', ADMIN_EMAIL);
  await page.fill('input[name="password"]', ADMIN_PASS);
  if (remember) {
    await page.check('#remember_me');
  }
  await page.locator('button[type="submit"]').click();
  await page.waitForURL(url => !url.toString().includes('/accedi'), { timeout: 15000 });
}

test.describe.serial('Remember Me checkbox', () => {
  let adminId = '';

  test.beforeAll(() => {
    adminId = dbQuery(`SELECT id FROM utenti WHERE LOWER(email)=LOWER('${sqlEscape(ADMIN_EMAIL)}') LIMIT 1`);
    expect(adminId, 'admin user must exist').not.toBe('');
  });

  // Start each case from a clean slate so counts are unambiguous. These are
  // disposable rows in the E2E database, not a real user's live sessions.
  test.beforeEach(() => {
    dbQuery(`DELETE FROM user_sessions WHERE utente_id=${adminId}`);
  });

  test.afterAll(() => {
    if (adminId) dbQuery(`DELETE FROM user_sessions WHERE utente_id=${adminId}`);
  });

  test('ticked → sets remember_token cookie and a user_sessions row', async ({ browser }) => {
    const context = await browser.newContext();
    const page = await context.newPage();
    try {
      await login(page, { remember: true });

      const cookies = await context.cookies();
      const remember = cookies.find(c => c.name === 'remember_token');
      expect(remember, 'remember_token cookie must be set when the box is ticked').toBeTruthy();
      // 64-byte token, hex-encoded → 128 chars; HttpOnly by design.
      expect(remember.value.length).toBe(128);
      expect(remember.httpOnly).toBe(true);

      const rows = dbQuery(
        `SELECT COUNT(*) FROM user_sessions WHERE utente_id=${adminId} AND is_revoked=0 AND expires_at > NOW()`,
      );
      expect(rows).toBe('1');
    } finally {
      await context.close();
    }
  });

  test('unticked → no remember_token cookie and no user_sessions row', async ({ browser }) => {
    const context = await browser.newContext();
    const page = await context.newPage();
    try {
      await login(page, { remember: false });

      const cookies = await context.cookies();
      const remember = cookies.find(c => c.name === 'remember_token');
      expect(remember, 'no remember_token cookie when the box is left unticked').toBeFalsy();

      const rows = dbQuery(`SELECT COUNT(*) FROM user_sessions WHERE utente_id=${adminId}`);
      expect(rows).toBe('0');
    } finally {
      await context.close();
    }
  });
});
