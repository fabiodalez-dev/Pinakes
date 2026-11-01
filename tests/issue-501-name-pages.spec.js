// @ts-check
/**
 * Issue #501, part 3 of 9: public pages and filters that look a person up BY
 * NAME. The collation folds accents, so the author page of "Haffner" also
 * listed the books of "Häffner" (two people merged as homonyms), and so did
 * the publisher page, the catalogue publisher/author filters and the
 * publisher facet. A name is now matched with its exact spelling (case still
 * ignored). Driven like a reader: direct links, clicks from a book page, the
 * facet list and the search boxes.
 */
const { test, expect } = require('@playwright/test');
const { makeToken, seed, clean, typeAndWait, expectTitles } = require('./helpers/exact-search-501');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
test.skip(!process.env.E2E_DB_USER || !process.env.E2E_DB_NAME, 'E2E database not configured (use /tmp/run-e2e.sh)');

const enc = encodeURIComponent;

test.describe.serial('#501 name pages and filters keep accents distinct', () => {
  /** @type {ReturnType<typeof seed>} */
  let f;

  test.beforeAll(() => {
    f = seed(makeToken());
  });
  test.afterAll(() => {
    clean(f);
  });

  /** @param {import('@playwright/test').Page} page @param {string} title */
  async function openBook(page, title) {
    await page.goto(`${BASE}/catalogo?q=${enc(`"${title}"`)}`);
    await page.locator('main').getByRole('link', { name: title }).first().click();
    await expect(page.locator('main h1').first()).toContainText(title);
  }

  // ── Author pages ─────────────────────────────────────────────────────────
  test('01 author page "Haffner" lists the Haffner book', async ({ page }) => {
    await page.goto(`${BASE}/autore/${enc(f.authorPlain)}`);
    await expect(page.locator('main')).toContainText(f.titlePlain);
  });

  test('02 author page "Haffner" no longer lists the Häffner book', async ({ page }) => {
    await page.goto(`${BASE}/autore/${enc(f.authorPlain)}`);
    await expectTitles(page.locator('main'), [f.titlePlain], [f.titleUmlaut]);
  });

  test('03 author page "Haffner" still merges a homonym that differs only in case', async ({ page }) => {
    await page.goto(`${BASE}/autore/${enc(f.authorPlain)}`);
    await expectTitles(page.locator('main'), [f.titlePlain, f.titleUpper], []);
  });

  test('04 author page "Häffner" lists only the Häffner book', async ({ page }) => {
    await page.goto(`${BASE}/autore/${enc(f.authorUmlaut)}`);
    await expectTitles(page.locator('main'), [f.titleUmlaut], [f.titlePlain, f.titleUpper]);
  });

  test('05 author page "Häffner" shows the umlaut name in its heading', async ({ page }) => {
    await page.goto(`${BASE}/autore/${enc(f.authorUmlaut)}`);
    await expect(page.locator('#archive-title')).toContainText(f.authorUmlaut);
  });

  test('06 a lower-case author URL still opens the right person', async ({ page }) => {
    await page.goto(`${BASE}/autore/${enc(f.authorUmlaut.toLowerCase())}`);
    await expectTitles(page.locator('main'), [f.titleUmlaut], [f.titlePlain]);
  });

  test('07 a spelling nobody has is a 404, not the nearest homonym', async ({ page }) => {
    const res = await page.goto(`${BASE}/autore/${enc(`${f.token} Haeffner`)}`);
    expect(res?.status()).toBe(404);
  });

  // ── Publisher pages ──────────────────────────────────────────────────────
  test('08 publisher page "Haffner" lists only its own book', async ({ page }) => {
    await page.goto(`${BASE}/editore/${enc(f.publisherPlain)}`);
    await expectTitles(page.locator('main'), [f.titlePlain], [f.titleUmlaut]);
  });

  test('09 publisher page "Häffner" lists only its own book', async ({ page }) => {
    await page.goto(`${BASE}/editore/${enc(f.publisherUmlaut)}`);
    await expectTitles(page.locator('main'), [f.titleUmlaut], [f.titlePlain]);
  });

  test('10 publisher page by id resolves to the exact name', async ({ page }) => {
    await page.goto(`${BASE}/editore/${f.ids.publishers[1]}`);
    await expectTitles(page.locator('main'), [f.titleUmlaut], [f.titlePlain]);
  });

  test('11 a publisher name with % opens its page (the % is not a wildcard)', async ({ page }) => {
    await page.goto(`${BASE}/editore/${enc(f.publisherPercent)}`);
    await expectTitles(page.locator('main'), [f.titleUpper], [f.titlePlain, f.titleUmlaut]);
  });

  // ── Clicks from a book page ──────────────────────────────────────────────
  test('12 clicking the author on the Häffner book opens only Häffner', async ({ page }) => {
    await openBook(page, f.titleUmlaut);
    await page.locator('main').getByRole('link', { name: f.authorUmlaut }).first().click();
    await expectTitles(page.locator('main'), [f.titleUmlaut], [f.titlePlain]);
  });

  test('13 clicking the publisher on the Häffner book opens only Häffner', async ({ page }) => {
    await openBook(page, f.titleUmlaut);
    await page.locator('main').getByRole('link', { name: f.publisherUmlaut }).first().click();
    await expectTitles(page.locator('main'), [f.titleUmlaut], [f.titlePlain]);
  });

  test('14 clicking the publisher on the Haffner book opens only Haffner', async ({ page }) => {
    await openBook(page, f.titlePlain);
    await page.locator('main').getByRole('link', { name: f.publisherPlain }).first().click();
    await expectTitles(page.locator('main'), [f.titlePlain], [f.titleUmlaut]);
  });

  // ── Catalogue filters ────────────────────────────────────────────────────
  test('15 catalogue publisher filter "Haffner" shows only its book', async ({ page }) => {
    await page.goto(`${BASE}/catalogo?editore=${enc(f.publisherPlain)}`);
    await expectTitles(page.locator('main'), [f.titlePlain], [f.titleUmlaut]);
  });

  test('16 catalogue publisher filter "Häffner" shows only its book', async ({ page }) => {
    await page.goto(`${BASE}/catalogo?editore=${enc(f.publisherUmlaut)}`);
    await expectTitles(page.locator('main'), [f.titleUmlaut], [f.titlePlain]);
  });

  test('17 catalogue author filter "Haffner" keeps the case homonym, drops Häffner', async ({ page }) => {
    await page.goto(`${BASE}/catalogo?autore=${enc(f.authorPlain)}`);
    await expectTitles(page.locator('main'), [f.titlePlain, f.titleUpper], [f.titleUmlaut]);
  });

  test('18 catalogue author filter "Häffner" shows only its book', async ({ page }) => {
    await page.goto(`${BASE}/catalogo?autore=${enc(f.authorUmlaut)}`);
    await expectTitles(page.locator('main'), [f.titleUmlaut], [f.titlePlain, f.titleUpper]);
  });

  // ── Publisher facet ──────────────────────────────────────────────────────
  test('19 the publisher facet lists Haffner and Häffner as two publishers', async ({ page }) => {
    await page.goto(`${BASE}/catalogo?q=${enc(f.token)}`);
    const facet = page.locator('#publishers-filter');
    await expect(facet.getByText(f.publisherPlain, { exact: false })).toHaveCount(1);
    await expect(facet.getByText(f.publisherUmlaut, { exact: false })).toHaveCount(1);
  });

  test('20 each publisher facet counts only its own book', async ({ page }) => {
    await page.goto(`${BASE}/catalogo?q=${enc(f.token)}`);
    const facet = page.locator('#publishers-filter');
    await expect(facet.locator('a', { hasText: f.publisherPlain })).toContainText('1');
    await expect(facet.locator('a', { hasText: f.publisherUmlaut })).toContainText('1');
  });

  test('21 clicking the Häffner facet filters to the Häffner book', async ({ page }) => {
    await page.goto(`${BASE}/catalogo?q=${enc(f.token)}`);
    const done = page.waitForResponse((r) => r.url().includes('editore=') && r.status() === 200, { timeout: 20000 });
    await page.locator('#publishers-filter a', { hasText: f.publisherUmlaut }).click();
    await done;
    await expectTitles(page.locator('main'), [f.titleUmlaut], [f.titlePlain]);
  });

  // ── Search boxes ─────────────────────────────────────────────────────────
  test('22 typing the name without quotes finds every spelling', async ({ page }) => {
    await page.goto(`${BASE}/catalogo`);
    await typeAndWait(page, '#search-input', `${f.token} haffner`, 'search=');
    await expectTitles(page.locator('main'), [f.titlePlain, f.titleUmlaut, f.titleUpper], []);
  });

  test('23 typing "häffner" in quotes finds only Häffner', async ({ page }) => {
    await page.goto(`${BASE}/catalogo`);
    await typeAndWait(page, '#search-input', `${f.token} "häffner"`, 'search=');
    await expectTitles(page.locator('main'), [f.titleUmlaut], [f.titlePlain, f.titleUpper]);
  });

  test('24 a quoted word still being typed ("haff) matches by its start', async ({ page }) => {
    await page.goto(`${BASE}/catalogo`);
    await typeAndWait(page, '#search-input', `${f.token} "haff`, 'search=');
    await expectTitles(page.locator('main'), [f.titlePlain, f.titleUpper], [f.titleUmlaut]);
  });

  test('25 the header search box honours quotes too', async ({ page }) => {
    await page.goto(`${BASE}/catalogo`);
    const box = page.locator('form.search-form input[name="q"]:visible');
    await box.fill(`"${f.titleUmlaut}"`);
    await Promise.all([page.waitForURL(/[?&]q=/), box.press('Enter')]);
    await expectTitles(page.locator('main'), [f.titleUmlaut], [f.titlePlain, f.titleUpper]);
  });

  test('26 a garbled query shows none of the books instead of all of them', async ({ page }) => {
    await page.goto(`${BASE}/catalogo?q=%FF%FE`);
    await expectTitles(page.locator('main'), [], [f.titlePlain, f.titleUmlaut, f.titleUpper]);
  });
});
