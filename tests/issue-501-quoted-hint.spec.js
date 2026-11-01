// @ts-check
/**
 * Issue #501: every main search box tells people that quotes exist, and the
 * quotes then do what the hint says. For each surface a person reads the
 * visible hint under the box, types a quoted query and sees only the exact
 * spelling: the public catalogue, the admin book / author / publisher lists,
 * and the archives (admin list, admin unified search, public archive).
 *
 * The archive rows are two units that differ only by an umlaut
 * ("<token> Brücke" / "<token> Brucke"), inserted for this run and removed
 * afterwards.
 */
const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
// e2e-fixtures throws while loading when the E2E environment is missing, so
// it is required only when the suite is going to run; the skip reports why.
const E2E_READY = Boolean(process.env.E2E_BASE_URL && process.env.E2E_DB_USER && process.env.E2E_DB_NAME
  && (process.env.E2E_DB_HOST || process.env.E2E_DB_SOCKET));
test.skip(!E2E_READY, 'E2E environment not configured (use /tmp/run-e2e.sh)');
const { createTempAdminUser, deleteTempAdminUser, dbQuery, dbExec } = E2E_READY ? require('./helpers/e2e-fixtures') : {};
const { makeToken, seed, clean, login, typeAndWait, expectTitles } = require('./helpers/exact-search-501');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const KEY = 'Usa le virgolette per la grafia esatta: "Häffner" trova solo Häffner, non Haffner.';
const HINT = JSON.parse(fs.readFileSync(path.join(__dirname, '..', 'locale', 'it_IT.json'), 'utf-8'))[KEY];
const ARCHIVE_ROUTE = JSON.parse(fs.readFileSync(path.join(__dirname, '..', 'locale', 'routes_it_IT.json'), 'utf-8')).archives || '/archivio';

test.describe.serial('#501 quote hint on every search box, and quoted search behind it', () => {
  /** @type {ReturnType<typeof seed>} */
  let f;
  /** @type {{id:number,email:string,password:string}} */
  let admin;
  /** @type {import('@playwright/test').BrowserContext} */
  let context;
  /** @type {import('@playwright/test').Page} */
  let page;
  let unitUmlaut = '';
  let unitPlain = '';
  let refPrefix = '';

  test.beforeAll(async ({ browser }) => {
    f = seed(makeToken());
    admin = createTempAdminUser('it_IT');
    const active = dbQuery("SELECT COUNT(*) FROM plugins WHERE name = 'archives' AND is_active = 1");
    test.skip(active !== '1', 'archives plugin is not active on this installation');
    unitUmlaut = `${f.token} Brücke`;
    unitPlain = `${f.token} Brucke`;
    refPrefix = `E2E-${f.token}`;
    dbExec(`INSERT INTO archival_units (reference_code, level, constructed_title, published)
            VALUES ('${refPrefix}-1', 'fonds', '${unitUmlaut}', 1), ('${refPrefix}-2', 'fonds', '${unitPlain}', 1)`);
    context = await browser.newContext();
    page = await context.newPage();
    await login(page, BASE, admin.email, admin.password);
  });

  test.afterAll(async () => {
    await context?.close();
    if (refPrefix !== '') {
      dbExec(`DELETE FROM archival_units WHERE reference_code IN ('${refPrefix}-1', '${refPrefix}-2')`);
    }
    clean(f);
    deleteTempAdminUser(admin?.id);
  });

  test('01 catalogue: the hint is visible and a quoted name finds only that spelling', async () => {
    expect(typeof HINT === 'string' && HINT !== '').toBe(true);
    await page.goto(`${BASE}/catalogo`);
    await expect(page.locator('#search-hint')).toBeVisible();
    await expect(page.locator('#search-hint')).toHaveText(HINT);
    const grid = page.locator('main');
    await typeAndWait(page, '#search-input', `${f.token} haffner`, 'search=');
    await expectTitles(grid, [f.titlePlain, f.titleUmlaut], []);
    await typeAndWait(page, '#search-input', `${f.token} "Häffner"`, 'search=');
    await expectTitles(grid, [f.titleUmlaut], [f.titlePlain]);
  });

  test('02 admin books: the hint is visible under the box and quotes narrow the list', async () => {
    await page.goto(`${BASE}/admin/books`);
    const hint = page.locator('#search_text_hint');
    await expect(hint).toBeVisible();
    await expect(hint).toHaveText(HINT);
    await expect(page.locator('#search_text')).toHaveAccessibleDescription(HINT);
    const table = page.locator('table').first();
    await typeAndWait(page, '#search_text', `${f.token} "haffner"`, '/api/libri');
    await expectTitles(table, [f.titlePlain], [f.titleUmlaut]);
  });

  test('03 admin authors: the hint is visible and a quoted name excludes the umlaut', async () => {
    await page.goto(`${BASE}/admin/authors`);
    await expect(page.locator('#search_nome_hint')).toBeVisible();
    await expect(page.locator('#search_nome_hint')).toHaveText(HINT);
    await expect(page.locator('#search_nome')).toHaveAccessibleDescription(HINT);
    const table = page.locator('table').first();
    await typeAndWait(page, '#search_nome', `"${f.token} Häffner"`, '/api/autori');
    await expectTitles(table, [f.authorUmlaut], [f.authorPlain]);
  });

  test('04 admin publishers: the hint is visible and a quoted name excludes the umlaut', async () => {
    await page.goto(`${BASE}/admin/publishers`);
    await expect(page.locator('#search_nome_hint')).toBeVisible();
    await expect(page.locator('#search_nome_hint')).toHaveText(HINT);
    await expect(page.locator('#search_nome')).toHaveAccessibleDescription(HINT);
    const table = page.locator('table').first();
    await typeAndWait(page, '#search_nome', `"${f.publisherPlain}"`, '/api/editori');
    await expectTitles(table, [f.publisherPlain], [f.publisherUmlaut]);
  });

  test('05 admin archive list: hint visible; unquoted finds both units, quoted only one', async () => {
    await page.goto(`${BASE}/admin/archives`);
    await expect(page.locator('#arc-q-hint')).toBeVisible();
    await expect(page.locator('#arc-q-hint')).toHaveText(HINT);
    await expect(page.locator('#arc-q')).toHaveAccessibleDescription(HINT);
    const main = page.locator('main');

    await page.fill('#arc-q', `${f.token} brucke`);
    await Promise.all([page.waitForURL(/[?&]q=/), page.locator('#arc-q').press('Enter')]);
    await expectTitles(main, [unitUmlaut, unitPlain], []);

    await page.fill('#arc-q', `"${unitUmlaut}"`);
    await Promise.all([page.waitForURL(/[?&]q=%22/), page.locator('#arc-q').press('Enter')]);
    await expectTitles(main, [unitUmlaut], [unitPlain]);
  });

  test('06 admin archive unified search: hint visible; quoted keeps the plain spelling', async () => {
    await page.goto(`${BASE}/admin/archives/search`);
    await expect(page.locator('#archive-search-q-hint')).toBeVisible();
    await expect(page.locator('#archive-search-q-hint')).toHaveText(HINT);
    await expect(page.locator('#archive-search-q')).toHaveAccessibleDescription(HINT);
    await page.fill('#archive-search-q', `${f.token} "Brucke"`);
    await Promise.all([page.waitForURL(/[?&]q=/), page.getByRole('button', { name: 'Cerca', exact: true }).last().click()]);
    await expectTitles(page.locator('main'), [unitPlain], [unitUmlaut]);
  });

  test('07 public archive: hint visible in the filter column; quoted search finds one unit', async () => {
    await page.goto(`${BASE}${ARCHIVE_ROUTE}`);
    const hint = page.locator('#filter-search-hint');
    await expect(hint).toBeVisible();
    await expect(hint).toHaveText(HINT);
    const box = page.locator('#filter-search-input');
    await expect(box).toHaveAccessibleDescription(HINT);

    await box.fill(`${f.token} brucke`);
    await Promise.all([page.waitForURL(/[?&]q=/), box.press('Enter')]);
    await expectTitles(page.locator('main'), [unitUmlaut, unitPlain], []);

    await page.locator('#filter-search-input').fill(`"${unitUmlaut}"`);
    await Promise.all([page.waitForURL(/[?&]q=%22/), page.locator('#filter-search-input').press('Enter')]);
    await expectTitles(page.locator('main'), [unitUmlaut], [unitPlain]);
  });
});
