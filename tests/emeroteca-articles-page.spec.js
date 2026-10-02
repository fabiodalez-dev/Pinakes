// @ts-check
/**
 * /emeroteca/articoli on the catalogue surface: facets, active-filter chips,
 * the cover grid and the pagination window.
 *
 * The page is plain links and a GET form, so every check below reads what a
 * visitor clicks: the facet counts must match the public corpus (an
 * unpublished article never counts), each chip must remove only its own
 * filter, changing a facet must not keep the visitor on a page that may no
 * longer exist, and 51 results must split 50 + 1 across two pages.
 */
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || process.env.APP_URL || 'http://localhost:8081';
const RUN = `ArtPage${Date.now().toString(36)}`;
const TESTATA = `${RUN} Testata`;
const PUBLICATION = `${RUN} Pubblicazione`;
const PUBLIC_IN_TESTATA = 51;   // one more than a page (ContributionService::search() pages by 50)
const IN_PUBLICATION = 3;
const PRIVATE_TITLE = `${RUN} Non pubblico`;

function db(sql) {
  const args = ['-u', process.env.E2E_DB_USER, process.env.E2E_DB_NAME, '-N', '-B', '-e', sql];
  if (process.env.E2E_DB_SOCKET) args.unshift('-S', process.env.E2E_DB_SOCKET);
  return execFileSync('mysql', args, { encoding: 'utf8', env: { ...process.env, MYSQL_PWD: process.env.E2E_DB_PASS } }).trim();
}

async function login(page) {
  await page.goto(BASE + '/admin/plugins');
  if (await page.locator('input[name=email]').isVisible()) {
    await page.locator('input[name=email]').fill(process.env.E2E_ADMIN_EMAIL || '');
    await page.locator('input[name=password]').fill(process.env.E2E_ADMIN_PASS || '');
    await page.locator('button[type=submit]').click();
    await page.waitForURL(u => !u.pathname.includes('accedi') && !u.pathname.includes('login'));
  }
}

// Same activation loop as emeroteca-412.spec.js: through the real UI, so
// onActivate() builds the schema, and checked against the database because the
// activation POST and its dialog race. "Attiva plugin", not "Attiva", since
// "Disattiva" contains it.
async function setEmerotecaActive(page, wanted) {
  const id = Number(db("SELECT id FROM plugins WHERE name='emeroteca'") || '0');
  expect(id, 'emeroteca must be registered as a bundled plugin').toBeGreaterThan(0);
  const active = () => db(`SELECT is_active FROM plugins WHERE id=${id}`) === '1';
  const label = wanted ? 'Attiva plugin' : 'Disattiva';
  for (let attempt = 0; attempt < 3 && active() !== wanted; attempt++) {
    await page.goto(BASE + '/admin/plugins');
    const button = page.locator(`[data-plugin-id="${id}"]`).first().locator(`button:has-text("${label}")`);
    if (!await button.isVisible({ timeout: 3000 }).catch(() => false)) continue;
    await button.click();
    const confirm = page.locator('.swal2-confirm:visible');
    if (await confirm.isVisible({ timeout: 3000 }).catch(() => false)) await confirm.click();
    await expect.poll(() => active() === wanted, { timeout: 30_000 }).toBe(true).catch(() => {});
  }
  expect(active(), `emeroteca could not be ${wanted ? 'activated' : 'deactivated'}`).toBe(wanted);
}

const articlesUrl = (query = {}) => {
  const qs = new URLSearchParams(query).toString();
  return `${BASE}/emeroteca/articoli${qs ? `?${qs}` : ''}`;
};
const total = async (page) => Number((await page.locator('.results-info strong').innerText()).replace(/\D/g, ''));
const facetFor = (page, name) => page.locator('.filter-option', { hasText: name });

let wasActive = false;
let testataId = 0;

test.describe.serial('Emeroteca public article search page', () => {
  test.beforeAll(async ({ browser }) => {
    if (!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_DB_USER) throw new Error('Run with /tmp/run-e2e.sh');
    wasActive = db("SELECT COALESCE(MAX(is_active),0) FROM plugins WHERE name='emeroteca'") === '1';
    if (!wasActive) {
      const page = await browser.newPage();
      try { await login(page); await setEmerotecaActive(page, true); } finally { await page.close(); }
    }

    db(`INSERT INTO emeroteca_testate (titolo) VALUES ('${TESTATA}')`);
    testataId = Number(db(`SELECT id FROM emeroteca_testate WHERE titolo='${TESTATA}'`));
    const rows = [];
    for (let i = 1; i <= PUBLIC_IN_TESTATA; i++) {
      const n = String(i).padStart(2, '0');
      const container = i <= IN_PUBLICATION ? `'${PUBLICATION}'` : 'NULL';
      rows.push(`('${RUN}-${n}', '${RUN} Articolo ${n}', ${testataId}, ${container}, 1)`);
    }
    // In the masthead and the publication, but unpublished: it must not be
    // counted by either facet, nor listed.
    rows.push(`('${RUN}-private', '${PRIVATE_TITLE}', ${testataId}, '${PUBLICATION}', 0)`);
    db(`INSERT INTO emeroteca_contributi (reference_key, titolo, testata_id, contenitore_titolo, pubblico) VALUES ${rows.join(',')}`);
  });

  test.afterAll(async ({ browser }) => {
    db(`DELETE FROM emeroteca_contributi WHERE reference_key LIKE '${RUN}-%'`);
    db(`DELETE FROM emeroteca_testate WHERE titolo='${TESTATA}'`);
    if (!wasActive) {
      const page = await browser.newPage();
      try { await login(page); await setEmerotecaActive(page, false); } finally { await page.close(); }
    }
  });

  test('the facets count only public articles', async ({ page }) => {
    await page.goto(articlesUrl());
    await expect(facetFor(page, TESTATA).locator('.count-badge')).toHaveText(String(PUBLIC_IN_TESTATA));
    await expect(facetFor(page, PUBLICATION).locator('.count-badge')).toHaveText(String(IN_PUBLICATION));
    await expect(page.locator('.filter-tag')).toHaveCount(0);
  });

  test('a search fills the grid and splits 51 results over two pages', async ({ page }) => {
    await page.goto(articlesUrl({ q: RUN }));
    expect(await total(page)).toBe(PUBLIC_IN_TESTATA);
    await expect(page.locator('.emeroteca-articles-grid .book-card[data-record-kind="article"]')).toHaveCount(50);
    await expect(page.locator('.book-card', { hasText: PRIVATE_TITLE })).toHaveCount(0);
    await expect(page.locator('.pagination .page-item.active .page-link')).toHaveText('1');

    await page.locator('.pagination .page-link', { hasText: /^2$/ }).click();
    await expect(page).toHaveURL(/[?&]page=2(&|$)/);
    expect(new URL(page.url()).searchParams.get('q')).toBe(RUN);
    await expect(page.locator('.emeroteca-articles-grid .book-card')).toHaveCount(PUBLIC_IN_TESTATA - 50);
    await expect(page.locator('.pagination .page-link[aria-current="page"]')).toHaveText('2');
  });

  test('a facet narrows the list and starts again from page 1', async ({ page }) => {
    await page.goto(articlesUrl({ q: RUN, page: '2' }));
    await facetFor(page, PUBLICATION).click();
    const params = new URL(page.url()).searchParams;
    expect(params.get('pubblicazione')).toBe(PUBLICATION);
    expect(params.get('q')).toBe(RUN);
    expect(params.has('page'), 'a changed facet must not keep the old page number').toBe(false);
    expect(await total(page)).toBe(IN_PUBLICATION);
    await expect(facetFor(page, PUBLICATION)).toHaveAttribute('aria-current', 'true');
    await expect(page.locator('.filter-tag')).toHaveCount(2);
    await expect(page.locator('.filter-tag', { hasText: `${PUBLICATION}` })).toBeVisible();
  });

  test('each chip removes only its own filter', async ({ page }) => {
    await page.goto(articlesUrl({ q: RUN, testata: String(testataId), pubblicazione: PUBLICATION }));
    await expect(page.locator('.filter-tag')).toHaveCount(3);
    await expect(page.locator('.filter-tag', { hasText: TESTATA })).toBeVisible();

    await page.locator('.filter-tag', { hasText: PUBLICATION }).locator('.filter-tag-remove').click();
    let params = new URL(page.url()).searchParams;
    expect(params.has('pubblicazione')).toBe(false);
    expect(params.get('q')).toBe(RUN);
    expect(params.get('testata')).toBe(String(testataId));
    expect(await total(page)).toBe(PUBLIC_IN_TESTATA);

    await page.locator('.filter-tag', { hasText: TESTATA }).locator('.filter-tag-remove').click();
    params = new URL(page.url()).searchParams;
    expect(params.has('testata')).toBe(false);
    expect(params.get('q')).toBe(RUN);
    await expect(page.locator('.filter-tag')).toHaveCount(1);
  });

  test('clearing every filter returns to the whole corpus, and an empty search says so', async ({ page }) => {
    await page.goto(articlesUrl({ q: RUN, testata: String(testataId) }));
    await page.locator('.clear-all-btn').click();
    await expect(page).toHaveURL(`${BASE}/emeroteca/articoli`);
    await expect(page.locator('.filter-tag')).toHaveCount(0);
    expect(await total(page)).toBeGreaterThanOrEqual(PUBLIC_IN_TESTATA);

    await page.goto(articlesUrl({ q: `${RUN}-nothing-matches` }));
    expect(await total(page)).toBe(0);
    await expect(page.locator('.empty-state')).toBeVisible();
    await expect(page.locator('.pagination')).toHaveCount(0);
  });
});
