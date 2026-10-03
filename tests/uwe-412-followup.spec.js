// @ts-check
/**
 * #412 follow-up: what Uwe asked for and the first round left half done.
 *
 *  - an article is found by the header search while the reader types, not
 *    only after Enter;
 *  - on /catalogo an article card links its authors and its publication;
 *  - the article form links the record to a catalogued masthead by searching
 *    it, and copies the masthead's title and ISSN into empty fields;
 *  - the MARCXML export carries the MARC country code in 008/15-17 and the
 *    public PDF in 856.
 */
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || process.env.APP_URL || 'http://localhost:8081';
const RUN = `Uwe412${Date.now().toString(36)}`;
const TITLE = `${RUN} Intertextuality in Tyll`;
const MASTHEAD = `${RUN} Arbejderhistorie`;

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

// Through the real UI, so onActivate() registers the hooks; checked against the
// database because the activation POST and its dialog race.
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

let wasActive = false;
let articleId = 0;
let mastheadId = 0;

test.describe.serial('Uwe #412 follow-up', () => {
  test.beforeAll(async ({ browser }) => {
    if (!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_DB_USER) throw new Error('Run with /tmp/run-e2e.sh');
    wasActive = db("SELECT COALESCE(MAX(is_active),0) FROM plugins WHERE name='emeroteca'") === '1';
    if (!wasActive) {
      const page = await browser.newPage();
      try { await login(page); await setEmerotecaActive(page, true); } finally { await page.close(); }
    }
    db(`INSERT INTO emeroteca_testate (titolo, issn) VALUES ('${MASTHEAD}', '0107-8461')`);
    mastheadId = Number(db(`SELECT id FROM emeroteca_testate WHERE titolo='${MASTHEAD}'`));
    db(`INSERT INTO emeroteca_contributi (reference_key, titolo, autori, contenitore_titolo, paese, pubblico, pdf_path, pdf_pubblico)
        VALUES ('${RUN}-a', '${TITLE}', 'Schweissinger, Marc J.', 'International Journal of Language and Literature', 'DK', 1, 'emeroteca/${RUN}.pdf', 1)`);
    articleId = Number(db(`SELECT id FROM emeroteca_contributi WHERE reference_key='${RUN}-a'`));
  });

  test.afterAll(async ({ browser }) => {
    db(`DELETE FROM emeroteca_contributi WHERE reference_key LIKE '${RUN}-%'`);
    db(`DELETE FROM emeroteca_testate WHERE titolo='${MASTHEAD}'`);
    if (!wasActive) {
      const page = await browser.newPage();
      try { await login(page); await setEmerotecaActive(page, false); } finally { await page.close(); }
    }
  });

  test('the header search suggests the article while the reader types', async ({ page }) => {
    await page.goto(`${BASE}/`);
    const input = page.locator('.search-input:visible').first();
    await input.click();
    await input.pressSequentially(RUN, { delay: 20 });
    const result = page.locator('.search-results.is-visible .article-result', { hasText: TITLE });
    await expect(result).toBeVisible({ timeout: 10_000 });
    await expect(result).toContainText('Schweissinger');
    await expect(result).toHaveAttribute('href', new RegExp(`/emeroteca/articolo/${articleId}$`));
  });

  test('an article card on /catalogo links its author and its publication', async ({ page }) => {
    await page.goto(`${BASE}/catalogo?q=${encodeURIComponent(RUN)}`);
    const card = page.locator('.book-card', { hasText: TITLE }).first();
    await expect(card).toBeVisible();
    const author = card.locator('.book-author a', { hasText: 'Schweissinger' });
    await expect(author, 'the author name is a link').toHaveCount(1);
    expect(await author.getAttribute('href')).toMatch(/autore=Schweissinger/);
    const source = card.locator('.book-meta a', { hasText: 'International Journal of Language and Literature' });
    await expect(source, 'the publication is a link').toHaveCount(1);
    expect(await source.getAttribute('href')).toMatch(/\/emeroteca\/articoli\?pubblicazione=/);
  });

  test('the article form links a masthead by searching it, and fills title and ISSN', async ({ page }) => {
    await login(page);
    await page.goto(`${BASE}/admin/periodicals/articles/create`);
    await page.locator('#article-titolo').fill(`${RUN} Linked`);
    const picker = page.locator('#article-host-record .choices');
    await picker.click();
    await page.locator('#article-host-record .choices__input--cloned').fill(RUN);
    await page.locator('#article-host-record .choices__list--dropdown .choices__item', { hasText: MASTHEAD }).first().click();
    await expect(page.locator('#article-contenitore_titolo')).toHaveValue(MASTHEAD);
    await expect(page.locator('#article-issn')).toHaveValue('0107-8461');
    await page.locator('form button[type=submit]').first().click();
    await expect.poll(() => db(`SELECT COALESCE(testata_id,0) FROM emeroteca_contributi WHERE titolo='${RUN} Linked'`)).toBe(String(mastheadId));
    db(`UPDATE emeroteca_contributi SET reference_key='${RUN}-linked' WHERE titolo='${RUN} Linked'`);
  });

  test('the MARCXML export carries the MARC country and the public PDF', async ({ page }) => {
    const response = await page.request.get(`${BASE}/emeroteca/articolo/${articleId}/marc.xml`);
    expect(response.status()).toBe(200);
    const xml = await response.text();
    const fixed = (xml.match(/<controlfield tag="008">([^<]*)<\/controlfield>/) || [])[1] || '';
    expect(fixed.substring(15, 18), '008/15-17 is the MARC code for Denmark').toBe('dk ');
    expect(xml).toMatch(new RegExp(`<datafield tag="856" ind1="4" ind2="0"><subfield code="u">[^<]*/emeroteca/articolo/${articleId}/pdf</subfield>`));
  });
});
