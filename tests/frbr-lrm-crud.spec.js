// @ts-check
/**
 * FRBR / IFLA LRM plugin — Work → Expression → Manifestation round trip.
 *
 * Covers, through the real admin UI:
 *   1. creating an Opera (Work) from /admin/opere/new
 *   2. creating an Espressione (Expression) from the opera page
 *   3. attaching a book to the opera WITH that expression from the book edit
 *      form's Opera panel (the Expression select is filtered by the opera)
 *   4. the public /opera/{slug} page lists the edition under its expression,
 *      and an edition without one under "Altre edizioni"
 *   5. an unknown slug renders the 404 inside the public site layout
 * plus the server-side guards: an expression from another opera is refused,
 * the expressions JSON endpoint answers, LIKE wildcards in the autocomplete
 * are literal, and a non-ASCII title gets a transliterated slug.
 *
 * Run:
 *   /tmp/run-e2e.sh tests/frbr-lrm-crud.spec.js --config=tests/playwright.config.js --workers=1
 */
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';
const DB_HOST = process.env.E2E_DB_HOST || '';
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS ?? '';
const DB_NAME = process.env.E2E_DB_NAME || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';

const TAG = 'E2EFRBR' + Date.now();
const HAS_E2E_ENV = Boolean(ADMIN_EMAIL && ADMIN_PASS && DB_USER && DB_NAME && (DB_HOST || DB_SOCKET));

test.skip(!HAS_E2E_ENV, 'Missing E2E env vars for the FRBR-LRM CRUD spec');

function mysqlArgs(sql = '', batch = false) {
  const args = [];
  if (DB_HOST) args.push('-h', DB_HOST);
  if (DB_SOCKET) args.push('-S', DB_SOCKET);
  args.push('-u', DB_USER);
  if (DB_PASS !== '') args.push(`-p${DB_PASS}`);
  args.push(DB_NAME);
  if (batch) args.push('-N', '-B');
  if (sql !== '') args.push('-e', sql);
  return args;
}

function dbQuery(sql) {
  return execFileSync('mysql', mysqlArgs(sql, true), { encoding: 'utf-8', timeout: 10000 }).trim();
}

function dbExec(sql) {
  execFileSync('mysql', mysqlArgs(sql), { encoding: 'utf-8', timeout: 10000 });
}

function escapeSql(value) {
  return String(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
}

async function loginAsAdmin(page) {
  await page.goto(`${BASE}/admin/dashboard`);
  const emailField = page.locator('input[name="email"]');
  if (await emailField.isVisible({ timeout: 3000 }).catch(() => false)) {
    await emailField.fill(ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await Promise.all([
      page.waitForURL(/\/admin\//, { timeout: 15000 }),
      page.click('button[type="submit"]'),
    ]);
  }
}

async function csrf(page) {
  return page.evaluate(() => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '');
}

async function ensurePluginActive(page, name) {
  await page.goto(`${BASE}/admin/plugins`);
  await page.waitForLoadState('domcontentloaded');
  const id = Number((dbQuery(`SELECT id FROM plugins WHERE name='${escapeSql(name)}' LIMIT 1`) || '0').trim() || '0');
  if (!id) return false;
  if ((dbQuery(`SELECT is_active FROM plugins WHERE id=${id}`) || '0').trim() === '1') return true;
  const token = await csrf(page);
  await page.evaluate(async ({ base, id, token }) => {
    await fetch(`${base}/admin/plugins/${id}/activate`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
      body: JSON.stringify({ csrf_token: token }),
    });
  }, { base: BASE, id, token });
  return (dbQuery(`SELECT is_active FROM plugins WHERE id=${id}`) || '0').trim() === '1';
}

test.describe.serial('FRBR-LRM Work / Expression / Manifestation CRUD', () => {
  /** @type {import('@playwright/test').BrowserContext} */
  let context;
  /** @type {import('@playwright/test').Page} */
  let page;
  let active = false;

  const OPERA_TITLE = `${TAG} Opera`;
  const EXPR_TITLE = `${TAG} Traduzione inglese`;
  const BOOK_WITH = `${TAG} Edizione tradotta`;
  const BOOK_WITHOUT = `${TAG} Edizione senza espressione`;
  const fx = { operaId: 0, slug: '', exprId: 0, bookWith: 0, bookWithout: 0, otherOperaId: 0, otherExprId: 0, cyrId: 0 };

  test.beforeAll(async ({ browser }) => {
    context = await browser.newContext();
    page = await context.newPage();
    await loginAsAdmin(page);
    active = await ensurePluginActive(page, 'frbr-lrm');
    if (!active) return;
    dbExec(`INSERT INTO libri (titolo, copie_totali, copie_disponibili) VALUES ('${escapeSql(BOOK_WITH)}', 1, 1)`);
    dbExec(`INSERT INTO libri (titolo, copie_totali, copie_disponibili) VALUES ('${escapeSql(BOOK_WITHOUT)}', 1, 1)`);
    fx.bookWith = Number(dbQuery(`SELECT id FROM libri WHERE titolo='${escapeSql(BOOK_WITH)}' ORDER BY id DESC LIMIT 1`));
    fx.bookWithout = Number(dbQuery(`SELECT id FROM libri WHERE titolo='${escapeSql(BOOK_WITHOUT)}' ORDER BY id DESC LIMIT 1`));
  });

  test.afterAll(async () => {
    try {
      dbExec(`UPDATE libri SET opera_id = NULL, espressione_id = NULL WHERE titolo LIKE '${escapeSql(TAG)}%'`);
      dbExec(`DELETE FROM espressioni WHERE opera_id IN (SELECT id FROM opere WHERE titolo_uniforme LIKE '${escapeSql(TAG)}%' OR id=${Number(fx.cyrId) || 0})`);
      dbExec(`DELETE FROM opere WHERE titolo_uniforme LIKE '${escapeSql(TAG)}%' OR id=${Number(fx.cyrId) || 0}`);
      dbExec(`DELETE FROM libri WHERE titolo LIKE '${escapeSql(TAG)}%'`);
    } catch { /* best-effort cleanup */ }
    await context?.close();
  });

  test('1. create an Opera from the admin UI', async () => {
    test.skip(!active, 'frbr-lrm plugin not active');
    await page.goto(`${BASE}/admin/opere/new`);
    await page.fill('input[name="titolo_uniforme"]', OPERA_TITLE);
    await page.fill('input[name="lingua_originale"]', 'ita');
    await Promise.all([
      page.waitForURL(/\/admin\/opere\/\d+$/, { timeout: 15000 }),
      page.locator('form[method="POST"] button[type="submit"]').first().click(),
    ]);
    fx.operaId = Number(dbQuery(`SELECT id FROM opere WHERE titolo_uniforme='${escapeSql(OPERA_TITLE)}' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1`));
    expect(fx.operaId).toBeGreaterThan(0);
    fx.slug = dbQuery(`SELECT slug FROM opere WHERE id=${fx.operaId}`);
    expect(fx.slug).toBe(`${TAG.toLowerCase()}-opera`);
    await expect(page.locator('h1')).toContainText(OPERA_TITLE);
  });

  test('2. create an Espressione on the opera page', async () => {
    test.skip(!active || !fx.operaId, 'needs the opera');
    await page.goto(`${BASE}/admin/opere/${fx.operaId}`);
    await page.locator('details summary').first().click();
    const form = page.locator(`form[action$="/admin/opere/${fx.operaId}/espressioni"]`);
    await form.locator('select[name="tipo_espressione"]').selectOption('traduzione');
    await form.locator('input[name="lingua"]').fill('eng');
    await form.locator('input[name="titolo_espressione"]').fill(EXPR_TITLE);
    await form.locator('input[name="anno_espressione"]').fill('1990');
    await Promise.all([
      page.waitForURL(new RegExp(`/admin/opere/${fx.operaId}$`), { timeout: 15000 }),
      form.locator('button[type="submit"]').click(),
    ]);
    fx.exprId = Number(dbQuery(`SELECT id FROM espressioni WHERE opera_id=${fx.operaId} AND titolo_espressione='${escapeSql(EXPR_TITLE)}' AND deleted_at IS NULL LIMIT 1`));
    expect(fx.exprId).toBeGreaterThan(0);
    await expect(page.getByText(EXPR_TITLE)).toBeVisible();

    // The expressions JSON endpoint behind the book panel lists it.
    const res = await page.request.get(`${BASE}/admin/frbr-lrm/opere/${fx.operaId}/espressioni`);
    expect(res.status()).toBe(200);
    const body = await res.json();
    expect(body.success).toBe(true);
    expect(body.espressioni.map((e) => e.id)).toContain(fx.exprId);
  });

  test('3. attach a book with that Expression from the book panel', async () => {
    test.skip(!active || !fx.exprId, 'needs the expression');
    await page.goto(`${BASE}/admin/books/edit/${fx.bookWith}`);
    const panel = page.locator('#frbr-opera-panel');
    await expect(panel).toHaveCount(1);
    await page.locator('#frbr-opera-toggle').click();
    await page.locator('#frbr_opera_search').fill(OPERA_TITLE);
    const hit = page.locator('#frbr_opera_results button', { hasText: OPERA_TITLE });
    await expect(hit).toBeVisible({ timeout: 10000 });
    await hit.click();
    await expect(page.locator('#frbr-opera-current-label')).toHaveText(OPERA_TITLE, { timeout: 10000 });

    // The Expression select appears, filtered by the chosen opera.
    const select = page.locator('#frbr_espressione_select');
    await expect(select).toBeVisible();
    await expect(select.locator(`option[value="${fx.exprId}"]`)).toHaveCount(1);
    await select.selectOption(String(fx.exprId));
    await expect(page.locator('#frbr-opera-status')).not.toHaveClass(/hidden/, { timeout: 10000 });
    await expect.poll(() => dbQuery(`SELECT CONCAT(IFNULL(opera_id,0), ':', IFNULL(espressione_id,0)) FROM libri WHERE id=${fx.bookWith}`))
      .toBe(`${fx.operaId}:${fx.exprId}`);

    // Reload: the persisted Expression is preselected.
    await page.reload();
    await expect(page.locator('#frbr_espressione_select')).toHaveValue(String(fx.exprId));

    // Second book attached to the same opera WITHOUT an expression (POST
    // straight to the endpoint the panel uses).
    const token = await csrf(page);
    const res = await page.request.post(`${BASE}/admin/books/${fx.bookWithout}/attach-opera`, {
      headers: { 'X-CSRF-Token': token, Accept: 'application/json' },
      form: { csrf_token: token, opera_id: String(fx.operaId) },
    });
    expect(res.status()).toBe(200);
    expect(dbQuery(`SELECT CONCAT(IFNULL(opera_id,0), ':', IFNULL(espressione_id,0)) FROM libri WHERE id=${fx.bookWithout}`))
      .toBe(`${fx.operaId}:0`);
  });

  test('3b. an Expression of another opera is refused', async () => {
    test.skip(!active || !fx.exprId, 'needs the expression');
    dbExec(`INSERT INTO opere (titolo_uniforme, slug) VALUES ('${escapeSql(TAG)} Altra', '${escapeSql(TAG.toLowerCase())}-altra')`);
    fx.otherOperaId = Number(dbQuery(`SELECT id FROM opere WHERE slug='${escapeSql(TAG.toLowerCase())}-altra'`));
    dbExec(`INSERT INTO espressioni (opera_id, tipo_espressione, titolo_espressione) VALUES (${fx.otherOperaId}, 'testo', '${escapeSql(TAG)} altra')`);
    fx.otherExprId = Number(dbQuery(`SELECT id FROM espressioni WHERE opera_id=${fx.otherOperaId} LIMIT 1`));
    await page.goto(`${BASE}/admin/opere`);
    const token = await csrf(page);
    const res = await page.request.post(`${BASE}/admin/books/${fx.bookWith}/attach-opera`, {
      headers: { 'X-CSRF-Token': token, Accept: 'application/json' },
      form: { csrf_token: token, opera_id: String(fx.operaId), espressione_id: String(fx.otherExprId) },
    });
    expect(res.status()).toBe(422);
    // Unchanged link.
    expect(dbQuery(`SELECT CONCAT(IFNULL(opera_id,0), ':', IFNULL(espressione_id,0)) FROM libri WHERE id=${fx.bookWith}`))
      .toBe(`${fx.operaId}:${fx.exprId}`);
  });

  test('3c. autocomplete treats LIKE wildcards literally; non-ASCII titles get a real slug', async () => {
    test.skip(!active || !fx.operaId, 'needs the opera');
    const pct = await (await page.request.get(`${BASE}/api/opere/search?q=${encodeURIComponent('%')}`)).json();
    expect(pct.some((r) => r.label === OPERA_TITLE)).toBe(false);
    const under = await (await page.request.get(`${BASE}/api/opere/search?q=${encodeURIComponent(TAG.slice(0, 4) + '_')}`)).json();
    expect(under.some((r) => r.label === OPERA_TITLE)).toBe(false);
    const exact = await (await page.request.get(`${BASE}/api/opere/search?q=${encodeURIComponent(TAG)}`)).json();
    expect(exact.some((r) => r.label === OPERA_TITLE)).toBe(true);

    await page.goto(`${BASE}/admin/opere/new`);
    const cyr = `Война и мир ${TAG}`;
    await page.fill('input[name="titolo_uniforme"]', cyr);
    await Promise.all([
      page.waitForURL(/\/admin\/opere\/\d+$/, { timeout: 15000 }),
      page.locator('form[method="POST"] button[type="submit"]').first().click(),
    ]);
    // Read it back by id (from the redirect): the mysql CLI's client charset
    // would mangle a Cyrillic literal in a WHERE clause.
    const cyrId = Number((page.url().match(/\/admin\/opere\/(\d+)$/) || [])[1] || 0);
    expect(cyrId).toBeGreaterThan(0);
    fx.cyrId = cyrId;
    const slug = dbQuery(`SELECT slug FROM opere WHERE id=${cyrId}`);
    expect(slug).toBe(`vojna-i-mir-${TAG.toLowerCase()}`);
  });

  test('4. the public opera page groups the editions under their Expression', async () => {
    test.skip(!active || !fx.slug, 'needs the opera');
    const res = await page.goto(`${BASE}/opera/${fx.slug}`);
    expect(res?.status()).toBe(200);
    const sections = page.locator('section.frbr-espressione');
    await expect(sections).toHaveCount(2);
    const first = sections.nth(0);
    await expect(first.locator('h3').first()).toContainText(EXPR_TITLE);
    await expect(first).toContainText(BOOK_WITH);
    await expect(first).not.toContainText(BOOK_WITHOUT);
    const second = sections.nth(1);
    await expect(second.locator('h3').first()).toHaveText(/Altre edizioni|Other editions|Weitere Ausgaben|Autres éditions|Andre udgaver/);
    await expect(second).toContainText(BOOK_WITHOUT);
  });

  test('5. an unknown slug renders the 404 inside the site layout', async () => {
    test.skip(!active, 'frbr-lrm plugin not active');
    const res = await page.goto(`${BASE}/opera/${TAG.toLowerCase()}-does-not-exist`);
    expect(res?.status()).toBe(404);
    // Public chrome (header + footer) around a localised message.
    await expect(page.locator('.header-container').first()).toBeAttached();
    await expect(page.locator('footer.footer').first()).toBeAttached();
    await expect(page.locator('.error-404-title')).toBeVisible();
    const title = await page.title();
    expect(title.length).toBeGreaterThan(0);
  });

  test('6. deleting the Expression detaches it from the book', async () => {
    test.skip(!active || !fx.exprId, 'needs the expression');
    await page.goto(`${BASE}/admin/opere/${fx.operaId}`);
    const token = await csrf(page);
    const res = await page.request.post(`${BASE}/admin/opere/espressioni/${fx.exprId}/delete`, {
      headers: { 'X-CSRF-Token': token },
      form: { csrf_token: token },
      maxRedirects: 0,
    });
    expect(res.status()).toBe(302);
    expect(dbQuery(`SELECT CONCAT(IFNULL(opera_id,0), ':', IFNULL(espressione_id,0)) FROM libri WHERE id=${fx.bookWith}`))
      .toBe(`${fx.operaId}:0`);
  });
});
