// Multi-language installation verification test.
// Driven by env var E2E_LOCALE (it_IT|en_US|de_DE). Expects a fresh sandbox
// with empty DB and no .installed marker. Validates:
//   1. Installer wizard accepts the requested locale
//   2. Post-install DB seeds match the chosen locale (`generi` table)
//   3. utenti.locale row matches the chosen locale
//   4. First login renders the correct locale (URL + UI strings)

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8082';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS  = process.env.E2E_ADMIN_PASS  || '';
const DB_HOST = process.env.E2E_DB_HOST   || 'localhost';
const DB_USER = process.env.E2E_DB_USER   || '';
const DB_PASS = process.env.E2E_DB_PASS   || '';
const DB_NAME = process.env.E2E_DB_NAME   || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';
const LOCALE = process.env.E2E_LOCALE || 'it_IT';

// Hard skip when E2E env is incomplete — without these vars the wizard would
// silently fail at the DB-config step (or worse, write to the wrong DB).
// Per CR R6 review: refuse to run rather than produce a misleading green/red.
test.skip(
  !ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME,
  'multilang-install-i18n requires E2E_ADMIN_EMAIL, E2E_ADMIN_PASS, E2E_DB_USER, E2E_DB_NAME',
);

// Expected seed values for the `generi` table (top-5 IDs).
// Source: installer/database/data_<locale>.sql
const SEED_EXPECTATIONS = {
  it_IT: { 1: 'Prosa',  2: 'Poesia',  3: 'Teatro',  4: 'Narrativa', 5: 'Divulgativa' },
  en_US: { 1: 'Prose',  2: 'Poetry',  3: 'Theatre', 4: 'Narrative', 5: 'Non-fiction' },
  de_DE: { 1: 'Prosa',  2: 'Lyrik',   3: 'Theater', 4: 'Erzählung', 5: 'Sachbuch' },
};

// Expected URL slugs for the locale-routed login route.
// Source: locale/routes_<locale>.json (key: "login")
const LOGIN_SLUGS = {
  it_IT: 'accedi',
  en_US: 'login',
  de_DE: 'anmelden',
};
const LOGIN_URL_PATTERNS = {
  it_IT: /\/accedi/,
  en_US: /\/login/,
  de_DE: /\/anmelden/,
};

const NOT_LOGIN_URL_PATTERNS = {
  it_IT: [/\/login(?!\w)/, /\/anmelden/],   // IT login should NOT be /login or /anmelden
  en_US: [/\/accedi/, /\/anmelden/],
  de_DE: [/\/accedi/, /\/login(?!\w)/],
};

// UI string fingerprints to differentiate locales on dashboard / login pages.
// Use \b word boundaries everywhere to avoid false-positives on substring
// matches inside HTML attributes, JS identifiers, or unrelated text.
const UI_FINGERPRINTS = {
  it_IT: { positive: /\bAccedi\b|\bEsci\b|\bProfilo\b|Indirizzo email/i, negative: /\bSign in\b|\bAnmelden\b|\bAbmelden\b/i },
  en_US: { positive: /\bSign in\b|\bLogin\b|\bLogout\b|\bProfile\b/i, negative: /\bAnmelden\b|\bAbmelden\b|\bEsci\b/i },
  de_DE: { positive: /\bAnmelden\b|\bAbmelden\b|\bProfil\b|E-?Mail/i, negative: /\bSign in\b|\bAccedi\b|\bEsci\b/i },
};

function dbQuery(sql) {
  const args = ['-N', '-B', '-e', sql];
  if (DB_HOST) args.push('-h', DB_HOST);
  if (DB_SOCKET) args.push('-S', DB_SOCKET);
  args.push('-u', DB_USER);
  if (DB_PASS !== '') args.push(`-p${DB_PASS}`);
  args.push(DB_NAME);
  return execFileSync('mysql', args, { encoding: 'utf-8', timeout: 10000 }).trim();
}

const seedExpect = SEED_EXPECTATIONS[LOCALE];
const loginPattern = LOGIN_URL_PATTERNS[LOCALE];
const wrongLoginPatterns = NOT_LOGIN_URL_PATTERNS[LOCALE];
const uiFingerprint = UI_FINGERPRINTS[LOCALE];

if (!seedExpect || !loginPattern || !uiFingerprint) {
  throw new Error(`Unsupported E2E_LOCALE=${LOCALE} — must be one of it_IT/en_US/de_DE`);
}

let appReady = false;

test.describe.serial(`multilang install — ${LOCALE}`, () => {
  let context;
  let page;

  test.beforeAll(async ({ browser }) => {
    context = await browser.newContext();
    page = await context.newPage();
  });
  test.afterAll(async () => { await context?.close(); });

  test(`Installer step 0: select ${LOCALE}`, async () => {
    await page.goto(`${BASE}/installer/?step=0`);
    await page.locator(`input[name="language"][value="${LOCALE}"]`).check();
    await page.locator('button[type="submit"]').click();
    await page.waitForURL(/step=1/);
  });

  test('Installer step 1: requirements pass', async () => {
    await expect(page.locator('li.not-met')).toHaveCount(0);
    await page.locator('button[type="submit"].btn-primary').click();
    await page.waitForURL(/step=2/);
  });

  test('Installer step 2: DB config', async () => {
    await page.fill('#db_host', DB_HOST);
    await page.fill('#db_username', DB_USER);
    await page.fill('#db_password', DB_PASS);
    await page.fill('#db_database', DB_NAME);
    if (DB_SOCKET) await page.fill('#db_socket', DB_SOCKET);
    await page.click('#test-connection-btn');
    await page.waitForFunction(
      () => {
        const el = document.getElementById('connection-result');
        return el && el.style.display !== 'none' && el.textContent.trim().length > 0;
      },
      { timeout: 15000 },
    );
    const cls = await page.locator('#connection-result').getAttribute('class');
    expect(cls).toContain('alert-success');
    await page.click('#continue-btn');
    await page.waitForURL(/step=[34]/, { timeout: 30000 });
  });

  test('Installer step 3: schema import', async () => {
    if (page.url().includes('step=4')) return;
    await page.waitForURL(/step=4/, { timeout: 60000 });
  });

  test('Installer step 4: admin user', async () => {
    await page.fill('input[name="nome"]', 'Fabio');
    await page.fill('input[name="cognome"]', 'Dalez');
    await page.fill('input[name="email"]', ADMIN_EMAIL);
    await page.fill('#password', ADMIN_PASS);
    await page.fill('#password_confirm', ADMIN_PASS);
    await page.locator('button[type="submit"].btn-primary').click();
    await page.waitForURL(/step=5/, { timeout: 15000 });
  });

  test('Installer step 5: app name', async () => {
    await page.fill('input[name="app_name"]', 'Pinakes');
    await page.locator('button[type="submit"].btn-primary').click();
    await page.waitForURL(/step=6/, { timeout: 15000 });
  });

  test('Installer step 6: email config', async () => {
    await page.selectOption('#email_driver', 'mail');
    await page.fill('input[name="from_email"]', 'noreply@example.com');
    await page.fill('input[name="from_name"]', 'Pinakes');
    await page.locator('button[type="submit"].btn-primary').click();
    await page.waitForURL(/step=7/, { timeout: 30000 });
  });

  test('Installer step 7: completion', async () => {
    await expect(page.locator('.alert-success').first()).toBeVisible({ timeout: 30000 });
    await page.locator('a.btn-primary').click();
    await page.waitForURL(url => !url.toString().includes('installer'), { timeout: 15000 });
    appReady = true;
  });

  test('DB verification: seeds match locale', async () => {
    test.skip(!appReady, 'Install did not complete');
    const out = dbQuery('SELECT id, nome FROM generi WHERE id IN (1,2,3,4,5) ORDER BY id');
    const seenById = {};
    for (const line of out.split('\n').filter(Boolean)) {
      const [id, ...rest] = line.split('\t');
      seenById[id] = rest.join('\t');
    }
    console.log(`[seed-check ${LOCALE}] generi rows:`, Object.entries(seenById).map(([k, v]) => `${k}=${v}`).join(', '));
    for (const [id, expected] of Object.entries(seedExpect)) {
      expect(seenById[id], `generi.id=${id} should be "${expected}" for locale ${LOCALE}, got "${seenById[id]}"`).toBe(expected);
    }
  });

  test('DB verification: utenti.locale matches chosen locale', async () => {
    test.skip(!appReady, 'Install did not complete');
    // ADMIN_EMAIL is supplied via env; sanitize for SQL by stripping anything outside [A-Za-z0-9._@+-]
    const safeEmail = ADMIN_EMAIL.replace(/[^A-Za-z0-9._@+\-]/g, '');
    expect(safeEmail).toBe(ADMIN_EMAIL); // refuse if env is suspicious
    const out = dbQuery(`SELECT email, locale FROM utenti WHERE email = '${safeEmail}' LIMIT 1`);
    console.log(`[locale-check ${LOCALE}] admin user row:`, out);
    expect(out.length).toBeGreaterThan(0);
    const [, locale] = out.split('\t');
    expect(locale).toBe(LOCALE);
  });

  test(`Locale-routed login URL responds 200 (${LOCALE})`, async () => {
    test.skip(!appReady, 'Install did not complete');
    const loginSlug = LOGIN_SLUGS[LOCALE];
    // Use a fresh anonymous context to test the public login URL
    const fresh = await page.context().browser().newContext();
    const p2 = await fresh.newPage();
    try {
      const loginResp = await p2.goto(`${BASE}/${loginSlug}`);
      const loginStatus = loginResp ? loginResp.status() : null;
      console.log(`[diag ${LOCALE}] /${loginSlug} status:`, loginStatus, 'landed at:', p2.url());
      expect(loginStatus, `/${loginSlug} should respond 200 in locale ${LOCALE}`).toBe(200);
      expect(p2.url(), `URL after navigating to /${loginSlug} should match ${loginPattern}`).toMatch(loginPattern);

      const stripScripts = (html) => html.replace(/<script[\s\S]*?<\/script>/gi, '');
      const loginHtml = stripScripts(await p2.content());
      const loginPositive = uiFingerprint.positive.test(loginHtml);
      const loginNegative = uiFingerprint.negative.test(loginHtml);
      console.log(`[diag ${LOCALE}] login page positive:`, loginPositive, 'wrong-locale-leakage:', loginNegative);

      expect(loginPositive, `login page should show ${LOCALE} fingerprint`).toBeTruthy();
      expect(loginNegative, `login page should NOT contain wrong-locale leakage`).toBeFalsy();
    } finally {
      await fresh.close();
    }
  });

  test(`Authenticated dashboard renders in ${LOCALE}`, async () => {
    test.skip(!appReady, 'Install did not complete');
    // After step 7, the wizard has already authenticated `page` as admin.
    // Navigate directly to the admin dashboard (route is locale-agnostic).
    const resp = await page.goto(`${BASE}/admin/dashboard`);
    const status = resp ? resp.status() : null;
    console.log(`[diag ${LOCALE}] /admin/dashboard status:`, status, 'landed at:', page.url());
    expect(status, '/admin/dashboard should respond 200').toBe(200);

    const stripScripts = (html) => html.replace(/<script[\s\S]*?<\/script>/gi, '');
    const dashHtml = stripScripts(await page.content());
    const dashPositive = uiFingerprint.positive.test(dashHtml);
    const dashNegative = uiFingerprint.negative.test(dashHtml);
    console.log(`[diag ${LOCALE}] dashboard positive:`, dashPositive, 'wrong-locale-leakage:', dashNegative);
    if (dashNegative) {
      const leaks = dashHtml.match(new RegExp(uiFingerprint.negative.source, 'gi')) || [];
      const uniqueLeaks = [...new Set(leaks.map(s => s.toLowerCase()))];
      console.log(`[diag ${LOCALE}] LEAKED TERMS:`, uniqueLeaks.join(', '));
      for (const term of uniqueLeaks) {
        const idx = dashHtml.toLowerCase().indexOf(term.toLowerCase());
        if (idx >= 0) {
          const ctx = dashHtml.slice(Math.max(0, idx - 80), Math.min(dashHtml.length, idx + 80));
          console.log(`[diag ${LOCALE}] ${term} context:`, ctx.replace(/\s+/g, ' '));
        }
      }
    }

    expect(dashPositive, `dashboard should show ${LOCALE} fingerprint`).toBeTruthy();
    expect(dashNegative, `dashboard should NOT contain wrong-locale leakage`).toBeFalsy();
  });
});
