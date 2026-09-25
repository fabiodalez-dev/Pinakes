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
const crypto = require('crypto');
const fs = require('fs');
const http = require('http');
const https = require('https');
const path = require('path');

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
    if (adminId) {
      dbQuery(`DELETE FROM user_sessions WHERE utente_id=${adminId}`);
      // Belt and braces for the suspension case below: a run killed between
      // its UPDATE and its restore would otherwise leave the admin locked out
      // of every other suite, with nothing on screen to explain why.
      dbQuery(`UPDATE utenti SET stato='attivo' WHERE id=${adminId}`);
    }
  });

  /** Everything the browser still has after being closed and reopened. */
  async function keepOnlyTheRememberedCookie(context) {
    const kept = (await context.cookies()).filter(c => c.name === 'remember_token');
    await context.clearCookies();
    await context.addCookies(kept);

    return kept[0];
  }

  /**
   * PHP's session cookie, found rather than assumed — session.name is
   * configurable, so it is whatever is left once the application's own
   * cookies are set aside. Insisting there is exactly one means a cookie
   * added later makes the callers fail out loud instead of quietly measuring
   * the wrong header.
   */
  async function sessionCookie(context) {
    const OURS = new Set(['remember_token', 'csrf_login']);
    const candidates = (await context.cookies())
      .filter(c => !OURS.has(c.name) && /^[A-Za-z0-9,-]{16,128}$/.test(c.value));
    expect(candidates.length, `exactly one cookie is PHP's own session (${candidates.map(c => c.name).join(', ')})`).toBe(1);

    return candidates[0];
  }

  /** The note the middleware publishes so siblings join instead of racing. */
  function notePathFor(tokenValue) {
    return path.join(
      __dirname, '..', 'storage', 'tmp', 'remember-session',
      crypto.createHash('sha256').update(tokenValue).digest('hex'),
    );
  }

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

  /**
   * One GET, carrying exactly the cookies given and nothing else.
   *
   * Playwright's own request object shares the context's cookie jar, so the
   * second of two "parallel" requests can already be carrying the session the
   * first was handed — which is precisely the thing under test, quietly
   * arranged away. These go out raw so every one of them really does arrive
   * holding nothing but the remembered cookie.
   */
  function rawGet(url, cookie) {
    const target = new URL(url);
    const agent = target.protocol === 'https:' ? https : http;

    return new Promise((resolve, reject) => {
      const request = agent.request(
        {
          protocol: target.protocol,
          host: target.hostname,
          port: target.port,
          path: target.pathname + target.search,
          method: 'GET',
          headers: { Cookie: cookie },
        },
        (response) => {
          response.resume();
          response.on('end', () => resolve({
            status: response.statusCode,
            setCookie: response.headers['set-cookie'] || [],
          }));
        },
      );
      request.on('error', reject);
      request.end();
    });
  }

  /** The last value a response gives a cookie — what the browser would keep. */
  function lastCookieValue(setCookie, name) {
    let value = null;
    for (const line of setCookie) {
      const match = String(line).match(new RegExp(`^${name}=([^;]*)`));
      if (match) value = match[1];
    }

    return value;
  }

  // A browser coming back with only the remembered cookie asks for several
  // things at the same moment: the page, and whatever the page fetches for
  // itself. None of them has a session, each authenticates from the same
  // cookie, and each used to mint its own session with its own CSRF token.
  // The browser keeps the last Set-Cookie it is given, so the token printed
  // into the HTML could belong to a session already left behind, and the
  // visitor's next form post came back "Errore di Sicurezza".
  test('requests arriving together with only the remembered cookie end in one session', async ({ browser }) => {
    const context = await browser.newContext();
    const page = await context.newPage();
    try {
      await login(page, { remember: true });
      const sessionName = (await sessionCookie(context)).name;
      const carried = await keepOnlyTheRememberedCookie(context);
      expect(carried, 'the remembered cookie survived').toBeTruthy();

      // Nothing published yet, so all three below start from the same place —
      // which is what a browser reopened on a remembered site actually does.
      const note = notePathFor(carried.value);
      if (fs.existsSync(note)) fs.unlinkSync(note);

      const only = `remember_token=${carried.value}`;
      const burst = await Promise.all([
        rawGet(`${BASE}/admin`, only),
        rawGet(`${BASE}/api/stats/active-loans-count`, only),
        rawGet(`${BASE}/admin`, only),
      ]);

      // Every one of them has to have been signed in, or the count below is
      // one session for the trivial reason that nobody got one.
      expect(burst.map(r => r.status), 'all three were served as a signed-in visitor')
        .toEqual([302, 200, 302]);

      const issued = new Set(
        burst.map(r => lastCookieValue(r.setCookie, sessionName)).filter(Boolean),
      );
      expect(issued.size, `one session for the whole burst, not one each (${[...issued].join(', ')})`).toBe(1);
    } finally {
      await context.close();
    }
  });

  // The token row says the device is still trusted. It says nothing about
  // whether the account still is — that lives on the user row, and until now
  // the only place it was read came *after* the decision to adopt a sibling's
  // session, so a request that found one never asked at all.
  //
  // The sibling is staged rather than raced for, because a race reproduces
  // about half the time and a regression here has to fail every run: the note
  // is written by hand, pointing at the very session the sign-in above left
  // behind, which is exactly what a sibling would have published a moment
  // earlier. The account is then suspended and the endpoint asked is one with
  // no AuthMiddleware in front of it, which reads $_SESSION['user'] straight —
  // so nothing downstream can mask the answer.
  test('a suspended account is not put back into the session its cookie opened', async ({ browser }) => {
    const context = await browser.newContext();
    const page = await context.newPage();
    let note = '';
    let suspended = false;
    try {
      await login(page, { remember: true });
      const live = (await sessionCookie(context)).value;
      const carried = await keepOnlyTheRememberedCookie(context);
      expect(carried, 'the sign-in leaves a cookie behind').toBeTruthy();

      note = notePathFor(carried.value);
      fs.mkdirSync(path.dirname(note), { recursive: true });
      fs.writeFileSync(note, `${live}|${Math.floor(Date.now() / 1000)}`);

      dbQuery(`UPDATE utenti SET stato='sospeso' WHERE id=${adminId}`);
      suspended = true;

      const answered = await page.request.get(`${BASE}/api/user/reservations/count`);
      const handedBack = answered.headersArray()
        .filter(h => h.name.toLowerCase() === 'set-cookie')
        .flatMap(h => String(h.value).split('\n'))
        .some(line => line.includes(live));
      expect(handedBack, 'the suspended account is not handed the session it used to hold').toBe(false);
      expect((await context.cookies()).some(c => c.value === live),
        'and the browser does not end up carrying it either').toBe(false);

      // Nor by the ordinary way in.
      await page.goto(`${BASE}/admin`, { waitUntil: 'domcontentloaded' });
      expect(page.url(), 'a suspended account is not signed in by its cookie').not.toMatch(/\/admin(\/|$|\?)/);
    } finally {
      if (suspended) dbQuery(`UPDATE utenti SET stato='attivo' WHERE id=${adminId}`);
      if (note && fs.existsSync(note)) fs.unlinkSync(note);
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
