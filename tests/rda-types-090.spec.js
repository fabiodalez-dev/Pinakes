// @ts-check
// RDA content / media / carrier in cataloguing (0.9.0): derived from the
// media type, optional per-record override in the book form, and the BIBFRAME
// output carrying the effective triple (derived types for every record, the
// override when one is set: a book keeps txt/n/nc, an audiobook gets spw/s/sd).
// The form, storage and book-page tests run on every install; only the ones
// that read the BIBFRAME output need the bibframe-linked-data plugin active.
const { test, expect } = require('@playwright/test');

// 0.9.0 optional modules (Settings → Modules / Loans): this suite tests
// rda_types, so it switches it on for its run and puts back what was there.
const featureToggles = require('./helpers/feature-toggles');
let featureSnapshot;
test.beforeAll(async () => { featureSnapshot = await featureToggles.enableFeatures(['rda_types']); });
test.afterAll(async () => { await featureToggles.restoreFeatures(featureSnapshot); });
const h = require('./helpers/minor-features-090');

test.skip(!h.configured, 'E2E credentials not configured');

const STAMP = Date.now().toString(36);
const TITLE = `E2E RDA ${STAMP}`;

async function bibframe(page, id) {
  const resp = await page.request.get(`${h.BASE}/api/bibframe/book/${id}`, { headers: { Accept: 'application/ld+json' } });
  expect(resp.status()).toBe(200);
  return resp.text();
}

test.describe.serial('RDA content/media/carrier types', () => {
  let context;
  let page;
  let bookId = 0;
  let bibframeActive = false;
  const NO_BIBFRAME = 'bibframe-linked-data plugin is not active on this install';

  test.beforeAll(async ({ browser }) => {
    bibframeActive = h.dbQuery("SELECT is_active FROM plugins WHERE name = 'bibframe-linked-data'") === '1';
    context = await browser.newContext();
    page = await context.newPage();
    await h.loginAsAdmin(page);
  });

  test.afterAll(async () => {
    h.deleteBooks([bookId]);
    await context?.close();
  });

  test('1. The book form shows the RDA types derived from the media type', async () => {
    await page.goto(`${h.BASE}/admin/books/create`);
    await expect(page.locator('#bookForm')).toBeVisible({ timeout: 15000 });
    const derived = (id) => page.locator(`#${id} option[data-rda-derived]`);
    await expect(derived('rda_content_type')).toHaveText('Automatico: Testo (txt)');
    await expect(derived('rda_media_type')).toHaveText('Automatico: Senza mediazione (n)');
    await expect(derived('rda_carrier_type')).toHaveText('Automatico: Volume (nc)');
    await page.selectOption('#tipo_media', 'dvd');
    await expect(derived('rda_content_type')).toHaveText('Automatico: Immagine in movimento bidimensionale (tdi)');
    await expect(derived('rda_media_type')).toHaveText('Automatico: Video (v)');
    await expect(derived('rda_carrier_type')).toHaveText('Automatico: Videodisco (vd)');
    for (const id of ['rda_content_type', 'rda_media_type', 'rda_carrier_type']) {
      await expect(page.locator(`#${id}`)).toHaveValue('');
    }
  });

  test('2. Without an override nothing is stored and the derived value is shown', async () => {
    test.setTimeout(90000);
    await page.goto(`${h.BASE}/admin/books/create`);
    await expect(page.locator('#bookForm')).toBeVisible({ timeout: 15000 });
    await page.fill('#titolo', TITLE);
    await page.selectOption('#tipo_media', 'audiolibro');
    await h.submitBookForm(page);
    await expect.poll(() => h.dbQuery(`SELECT COUNT(*) FROM libri WHERE titolo = ${h.sqlString(TITLE)} AND deleted_at IS NULL`), { timeout: 30000 }).toBe('1');
    bookId = parseInt(h.dbQuery(`SELECT id FROM libri WHERE titolo = ${h.sqlString(TITLE)} AND deleted_at IS NULL`), 10);
    expect(h.dbQuery(`SELECT CONCAT_WS('|', tipo_media, IFNULL(rda_content_type,'NULL'), IFNULL(rda_media_type,'NULL'), IFNULL(rda_carrier_type,'NULL')) FROM libri WHERE id = ${bookId}`))
      .toBe('audiolibro|NULL|NULL|NULL');
    await page.goto(`${h.BASE}/admin/books/${bookId}`);
    await expect(page.locator('#book-rda-types')).toHaveAttribute('data-rda', 'spw/s/sd');
    await expect(page.locator('#book-rda-types')).toHaveText('Parola parlata · Audio · Disco sonoro');
  });

  test('3. Without an override BIBFRAME carries the types the book page shows (audiobook: spw/s/sd)', async () => {
    test.skip(!bibframeActive, NO_BIBFRAME);
    const body = await bibframe(page, bookId);
    expect(body).toContain('http://id.loc.gov/vocabulary/contentTypes/spw');
    expect(body).toContain('http://id.loc.gov/vocabulary/carriers/sd');
    expect(body).toContain('http://id.loc.gov/vocabulary/mediaTypes/s');
    expect(body).not.toContain('http://id.loc.gov/vocabulary/contentTypes/txt');
  });

  test('4. An override saved in the form is stored, reloaded and shown', async () => {
    test.setTimeout(90000);
    await page.goto(`${h.BASE}/admin/books/edit/${bookId}`);
    await expect(page.locator('#bookForm')).toBeVisible({ timeout: 15000 });
    await page.selectOption('#rda_media_type', 'c');
    await page.selectOption('#rda_carrier_type', 'cr');
    await h.submitBookForm(page);
    await expect.poll(() => h.dbQuery(`SELECT CONCAT_WS('|', IFNULL(rda_content_type,'NULL'), IFNULL(rda_media_type,'NULL'), IFNULL(rda_carrier_type,'NULL')) FROM libri WHERE id = ${bookId}`), { timeout: 30000 })
      .toBe('NULL|c|cr');
    await page.goto(`${h.BASE}/admin/books/edit/${bookId}`);
    await expect(page.locator('#rda_media_type')).toHaveValue('c');
    await expect(page.locator('#rda_carrier_type')).toHaveValue('cr');
    await expect(page.locator('#rda_content_type')).toHaveValue('');
    await page.goto(`${h.BASE}/admin/books/${bookId}`);
    await expect(page.locator('#book-rda-types')).toHaveAttribute('data-rda', 'spw/c/cr');
  });

  test('5. BIBFRAME uses the override, and only the overridden types', async () => {
    test.skip(!bibframeActive, NO_BIBFRAME);
    const body = await bibframe(page, bookId);
    expect(body).toContain('http://id.loc.gov/vocabulary/carriers/cr');
    expect(body).toContain('http://id.loc.gov/vocabulary/mediaTypes/c');
    expect(body).not.toContain('http://id.loc.gov/vocabulary/carriers/sd');
    // Content was not overridden: the value derived from the material stays.
    expect(body).toContain('http://id.loc.gov/vocabulary/contentTypes/spw');
  });

  test('6. Choosing "Automatico" again clears the override', async () => {
    test.setTimeout(90000);
    await page.goto(`${h.BASE}/admin/books/edit/${bookId}`);
    await expect(page.locator('#bookForm')).toBeVisible({ timeout: 15000 });
    await page.selectOption('#rda_media_type', '');
    await page.selectOption('#rda_carrier_type', '');
    await h.submitBookForm(page);
    await expect.poll(() => h.dbQuery(`SELECT CONCAT_WS('|', IFNULL(rda_media_type,'NULL'), IFNULL(rda_carrier_type,'NULL')) FROM libri WHERE id = ${bookId}`), { timeout: 30000 })
      .toBe('NULL|NULL');
    await page.goto(`${h.BASE}/admin/books/${bookId}`);
    await expect(page.locator('#book-rda-types')).toHaveAttribute('data-rda', 'spw/s/sd');
  });

  test('7. After the clear BIBFRAME is back to the derived media and carrier', async () => {
    test.skip(!bibframeActive, NO_BIBFRAME);
    const body = await bibframe(page, bookId);
    expect(body).toContain('http://id.loc.gov/vocabulary/carriers/sd');
    expect(body).not.toContain('http://id.loc.gov/vocabulary/carriers/cr');
    expect(body).toContain('http://id.loc.gov/vocabulary/mediaTypes/s');
    expect(body).not.toContain('http://id.loc.gov/vocabulary/mediaTypes/c');
  });

  test('8. A content type override is stored, reloaded and shown on the book page', async () => {
    test.setTimeout(90000);
    await page.goto(`${h.BASE}/admin/books/edit/${bookId}`);
    await expect(page.locator('#bookForm')).toBeVisible({ timeout: 15000 });
    await page.selectOption('#rda_content_type', 'prm');
    await h.submitBookForm(page);
    await expect.poll(() => h.dbQuery(`SELECT CONCAT_WS('|', IFNULL(rda_content_type,'NULL'), IFNULL(rda_media_type,'NULL'), IFNULL(rda_carrier_type,'NULL')) FROM libri WHERE id = ${bookId}`), { timeout: 30000 })
      .toBe('prm|NULL|NULL');
    await page.goto(`${h.BASE}/admin/books/edit/${bookId}`);
    await expect(page.locator('#rda_content_type')).toHaveValue('prm');
    await page.goto(`${h.BASE}/admin/books/${bookId}`);
    await expect(page.locator('#book-rda-types')).toHaveAttribute('data-rda', 'prm/s/sd');
    await expect(page.locator('#book-rda-types')).toHaveText('Musica eseguita · Audio · Disco sonoro');
  });

  test('9. BIBFRAME uses the content type override instead of the derived one', async () => {
    test.skip(!bibframeActive, NO_BIBFRAME);
    const body = await bibframe(page, bookId);
    expect(body).toContain('http://id.loc.gov/vocabulary/contentTypes/prm');
    expect(body).not.toContain('http://id.loc.gov/vocabulary/contentTypes/spw');
  });
});
