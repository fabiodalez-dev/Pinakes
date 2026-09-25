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
  // Positive post-login assertion: an admin lands on /admin/... A negative
  // !includes('/accedi') predicate is both locale-fragile (a non-Italian
  // install redirects failures to /login?error=…, which does NOT contain
  // '/accedi', so it would resolve and false-pass) and, on the IT install,
  // turns every failure into an opaque 15s timeout. Matching /admin is
  // locale-independent and only a real login can satisfy it.
  await page.waitForURL(/\/admin(\/|$|\?)/, { timeout: 15000 });
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

  // The row is the point, and it is new in 0.7.86. Before it, only a remembered
  // sign-in left anything revocable behind, so a password reset ended the
  // automatic sign-ins while the browser an intruder was already using carried
  // on — AuthMiddleware decides from the PHP session and the session was tied to
  // nothing. What must stay absent when the box is unticked is the COOKIE.
  test('unticked → no remember_token cookie, but a revocable user_sessions row', async ({ browser }) => {
    const context = await browser.newContext();
    const page = await context.newPage();
    try {
      await login(page, { remember: false });

      const cookies = await context.cookies();
      const remember = cookies.find(c => c.name === 'remember_token');
      expect(remember, 'no remember_token cookie when the box is left unticked').toBeFalsy();

      const rows = dbQuery(
        `SELECT COUNT(*) FROM user_sessions WHERE utente_id=${adminId} AND is_revoked=0 AND expires_at > UTC_TIMESTAMP()`,
      );
      expect(rows, 'an ordinary sign-in is recorded so it can be revoked').toBe('1');

      // And revoking it ends the session on the next request, which is what
      // makes "a password reset signs every device out" true rather than a
      // claim about remembered devices only.
      dbQuery(`UPDATE user_sessions SET is_revoked=1 WHERE utente_id=${adminId}`);
      await page.goto(`${BASE}/admin/dashboard`, { waitUntil: 'domcontentloaded' });
      expect(page.url(), 'a revoked row signs the browser out on its next request').not.toContain('/admin/dashboard');
    } finally {
      await context.close();
    }
  });

  // A sign-in must not leave the previous occupant's bearer token in the
  // browser. Arriving at the login FORM with a live remembered cookie is not
  // how this is reached — the middleware signs you in from that cookie first —
  // so the exposed path is a sign-in POSTed while the cookie is present: the
  // session becomes the new person's, and the old cookie stayed behind, live,
  // ready to sign the browser back in as its owner once that session lapsed.
  test('a sign-in retires the remember-me cookie the browser was carrying', async ({ browser }) => {
    const context = await browser.newContext();
    const page = await context.newPage();
    try {
      await login(page, { remember: true });
      const carried = (await context.cookies()).find(c => c.name === 'remember_token');
      expect(carried, 'the first sign-in leaves a cookie behind').toBeTruthy();
      const carriedRowId = Number(dbQuery(
        `SELECT id FROM user_sessions WHERE utente_id=${adminId} AND is_revoked=0 ORDER BY id DESC LIMIT 1`,
      ));
      expect(carriedRowId, 'the cookie has a row behind it').toBeGreaterThan(0);

      // Drop everything except the remembered cookie: the browser was closed
      // and reopened, which is what leaves a public terminal in this state.
      const kept = (await context.cookies()).filter(c => c.name === 'remember_token');
      await context.clearCookies();
      await context.addCookies(kept);

      // Visiting anything now signs the browser in from that cookie alone —
      // which is exactly why the form is not the way in. Take a CSRF token from
      // the page it lands on and sign in over the top of it.
      await page.goto(`${BASE}/accedi`, { waitUntil: 'domcontentloaded' });
      const csrf = await page.locator('input[name="csrf_token"]').first().inputValue();
      expect(csrf, 'a CSRF token to post with').toBeTruthy();

      const posted = await page.request.post(`${BASE}/accedi`, {
        // A form post carries these; page.request does not add them, and the
        // referer guard turns their absence into a 403 that has nothing to do
        // with what is under test.
        headers: { Referer: `${BASE}/accedi`, Origin: BASE },
        form: { email: ADMIN_EMAIL, password: ADMIN_PASS, csrf_token: csrf },
        maxRedirects: 0,
      });
      expect([302, 303].includes(posted.status()), `the sign-in was accepted (got ${posted.status()})`).toBe(true);

      const left = (await context.cookies()).find(c => c.name === 'remember_token');
      expect(left, 'the cookie the browser arrived with is gone').toBeFalsy();
      const stillLive = dbQuery(
        `SELECT COUNT(*) FROM user_sessions WHERE id=${carriedRowId} AND is_revoked=0`,
      );
      expect(stillLive, 'and the row behind it can no longer sign anyone in').toBe('0');
    } finally {
      await context.close();
    }
  });
});
