// @ts-check
/**
 * Follow-ups of the review of the granular switches, as an administrator
 * meets them: switching consultation-only copies updates the availability
 * shown at once, the MARC module also owns the UNIMARC import and export, a
 * supplier filter left in a request does nothing while suppliers are off,
 * the reader record speaks of photos only while card printing is off, and
 * the holiday preview names the day itself of a holiday with a day in lieu.
 *
 * Run: /Users/fabio/Documents/GitHub/run-e2e-wt3.sh tests/feature-toggles-followups.spec.js --config=tests/playwright.config.js --workers=1
 */
const { test, expect } = require('@playwright/test');
const ft = require('./helpers/feature-toggles');

test.skip(!ft.configured, 'E2E credentials not configured');

const RUN = Date.now().toString(36);
const TAG = `ZZ-FU-${RUN}`;
const READER = `zz-ft-fu-${RUN}@test.local`;
const S = ft.sqlString;

test.describe.serial('Switches: review follow-ups', () => {
  test.describe.configure({ timeout: 180000 });
  /** @type {import('@playwright/test').Page} */
  let page;
  let snapshot;
  let bookId = 0;
  let plainId = 0;
  let supplierId = 0;
  let readerId = 0;
  /** @type {Record<string, string|null>} */
  const savedHoliday = {};

  const addBook = (title) => {
    ft.dbQuery(`INSERT INTO libri (titolo, tipo_media, copie_totali, copie_disponibili) VALUES (${S(title)}, 'libro', 1, 1)`);
    const id = Number(ft.dbQuery(`SELECT id FROM libri WHERE titolo=${S(title)} AND deleted_at IS NULL`));
    ft.dbQuery(`INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (${id}, ${S(`${title}-C1`)}, 'disponibile')`);
    return id;
  };
  const available = (id) => Number(ft.dbQuery(`SELECT copie_disponibili FROM libri WHERE id=${id}`));

  test.beforeAll(async ({ browser }) => {
    snapshot = ft.readFeatures(['consultation_only', 'marc', 'suppliers', 'patron_cards', 'patron_photos', 'closures_calendar']);
    await ft.writeFeatures({ consultation_only: '1', marc: '1', suppliers: '1', patron_cards: '1', patron_photos: '1', closures_calendar: '1' });
    bookId = addBook(`${TAG} consultation`);
    plainId = addBook(`${TAG} plain`);
    ft.dbQuery(`INSERT INTO fornitori (nome) VALUES (${S(`${TAG} supplier`)})`);
    supplierId = Number(ft.dbQuery(`SELECT id FROM fornitori WHERE nome=${S(`${TAG} supplier`)}`));
    ft.dbQuery(`UPDATE libri SET fornitore_id=${supplierId} WHERE id=${bookId}`);
    readerId = ft.createUser(READER, 'Test1234!', 'standard');
    for (const key of ['holiday_country', 'holiday_region', 'holiday_rules']) {
      const v = ft.dbQuery(`SELECT IFNULL((SELECT setting_value FROM system_settings WHERE category='calendar' AND setting_key=${S(key)}), '\\N')`);
      savedHoliday[key] = v === '\\N' || v === 'NULL' ? null : v;
    }
    page = await browser.newPage();
    await ft.loginAdmin(page);
  });

  test.afterAll(async () => {
    await ft.restoreFeatures(snapshot);
    ft.dbQuery(`DELETE FROM copie WHERE libro_id IN (${bookId}, ${plainId})`);
    ft.dbQuery(`DELETE FROM libri WHERE id IN (${bookId}, ${plainId})`);
    ft.dbQuery(`DELETE FROM fornitori WHERE id=${supplierId}`);
    ft.removeUsers('zz-ft-fu-%@test.local');
    await page?.close();
  });

  test('1. switching consultation-only copies off and on updates the availability at once', async () => {
    // The librarian keeps the only copy for consultation from the book page.
    await page.goto(`${ft.BASE}/admin/books/${bookId}`);
    await page.locator('form[action*="/consultation"] button[type="submit"]').first().click();
    await page.waitForURL((u) => u.pathname.endsWith(`/admin/books/${bookId}`));
    expect(available(bookId)).toBe(0);

    await ft.setModuleInUi(page, 'consultation_only', false);
    expect(available(bookId)).toBe(1);
    expect(available(plainId)).toBe(1);

    await ft.setModuleInUi(page, 'consultation_only', true);
    expect(available(bookId)).toBe(0);
    expect(available(plainId)).toBe(1);
  });

  test('2. the UNIMARC import and export belong to the MARC module', async () => {
    await page.goto(`${ft.BASE}/admin/books/edit/${plainId}`);
    const z39 = (await page.locator('#reicat_import_unimarc').count()) > 0;
    test.skip(!z39, 'z39-server plugin not active on this installation');
    const exportUrl = `${ft.BASE}/admin/books/${plainId}/export.unimarc.xml`;
    expect((await page.request.get(exportUrl, { maxRedirects: 0 })).status()).toBe(200);

    await ft.setModuleInUi(page, 'marc', false);
    await page.goto(`${ft.BASE}/admin/books/edit/${plainId}`);
    await expect(page.locator('#reicat_import_unimarc')).toHaveCount(0);
    await expect(page.locator(`a[href*="export.unimarc.xml"]`)).toHaveCount(0);
    const refused = await page.request.get(exportUrl, { maxRedirects: 0 });
    expect(refused.status()).toBe(302);
    await ft.setModuleInUi(page, 'marc', true);
  });

  test('3. a supplier filter left in a request does nothing while suppliers are off', async () => {
    const ids = async () => {
      const res = await page.request.get(`${ft.BASE}/api/libri?draw=1&length=500&search_text=${encodeURIComponent(RUN)}&fornitore_filter=${supplierId}`);
      return (await res.json()).data.map((row) => Number(row.id));
    };
    // search_index is built by the app: refresh it for the two rows added in the DB.
    ft.dbQuery(`UPDATE libri SET search_index=CONCAT_WS(' ', titolo) WHERE id IN (${bookId}, ${plainId})`);
    let found = await ids();
    expect(found).toContain(bookId);
    expect(found).not.toContain(plainId);

    await ft.setModuleInUi(page, 'suppliers', false);
    await page.goto(`${ft.BASE}/admin/books`);
    await expect(page.locator('#fornitore_filter')).toHaveCount(0);
    found = await ids();
    expect(found).toContain(bookId);
    expect(found).toContain(plainId);
    await ft.setModuleInUi(page, 'suppliers', true);
  });

  test('4. with card printing off the reader record speaks of the photo only', async () => {
    await page.goto(`${ft.BASE}/admin/users/details/${readerId}`);
    await expect(page.locator('#patron-card-section h2')).toHaveText(/Foto e tessera|Photo and card/);
    await ft.setModuleInUi(page, 'patron_cards', false);
    await page.goto(`${ft.BASE}/admin/users/details/${readerId}`);
    await expect(page.locator('#patron-card-section h2')).toHaveText(/^\s*(Foto|Photo)\s*$/);
    await expect(page.locator('#patron-card-section')).not.toContainText(/tessera stampata|printed card/);
    await expect(page.locator('#patron-card-section a[href*="card-pdf"]')).toHaveCount(0);
    await ft.setModuleInUi(page, 'patron_cards', true);
  });

  test('5. a holiday with a day in lieu names the day itself; the preview year is typed', async () => {
    await page.goto(`${ft.BASE}/admin/calendar?holiday_country=US`);
    // Christmas 2027 is a Saturday: Friday 24 is the day in lieu, Saturday 25 the holiday.
    await page.fill('#holiday_year', '2027');
    await Promise.all([page.waitForURL(/holiday_year=2027/), page.locator('#holiday_year').dispatchEvent('change')]);
    const christmas = page.locator('[data-holiday-row="US:christmas"]');
    await expect(christmas.locator('[data-holiday-date]')).toHaveAttribute('data-holiday-date', '2027-12-24');
    await expect(christmas.locator('[data-holiday-actual]')).toHaveAttribute('data-holiday-actual', '2027-12-25');
    // A year outside 1900-2199 is not accepted: the preview stays on a valid year.
    await page.goto(`${ft.BASE}/admin/calendar?holiday_country=US&holiday_year=3000`);
    expect(Number(await page.locator('#holiday_year').inputValue())).toBeLessThanOrEqual(2199);
  });
});
