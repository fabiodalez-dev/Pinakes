// @ts-check
// RDA content / media / carrier in cataloguing (0.9.0): derived from the
// media type, optional per-record override in the book form, override used by
// the BIBFRAME output (historical output kept when there is no override).
const { test, expect } = require('@playwright/test');
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

  test.beforeAll(async ({ browser }) => {
    const active = h.dbQuery("SELECT is_active FROM plugins WHERE name = 'bibframe-linked-data'");
    test.skip(active !== '1', 'bibframe-linked-data plugin is not active on this install');
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

  test('3. BIBFRAME keeps its current output when no override is set', async () => {
    const body = await bibframe(page, bookId);
    expect(body).toContain('http://id.loc.gov/vocabulary/contentTypes/txt');
    expect(body).toContain('http://id.loc.gov/vocabulary/carriers/nc');
    expect(body).toContain('http://id.loc.gov/vocabulary/mediaTypes/n');
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
    const body = await bibframe(page, bookId);
    expect(body).toContain('http://id.loc.gov/vocabulary/carriers/cr');
    expect(body).toContain('http://id.loc.gov/vocabulary/mediaTypes/c');
    expect(body).not.toContain('http://id.loc.gov/vocabulary/carriers/nc');
    // Content was not overridden: the historical value stays.
    expect(body).toContain('http://id.loc.gov/vocabulary/contentTypes/txt');
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
    const body = await bibframe(page, bookId);
    expect(body).toContain('http://id.loc.gov/vocabulary/carriers/nc');
    expect(body).not.toContain('http://id.loc.gov/vocabulary/carriers/cr');
  });
});
