// @ts-check
// The admin pages added in 0.9.0, opened on a phone (iPhone 13, 390 px):
// nothing scrolls sideways (the page or a table inside it), no text field is
// under 16 px (iOS zooms the page on focus), the calendar's month arrows sit
// side by side, a wide table reads as cards with each value under its column
// name, and a three-digit notification count fits its badge.
//
// Run: /tmp/run-e2e.sh tests/mobile-admin-090-layout.spec.js --config=tests/playwright.config.js --workers=1
const { test, expect, devices } = require('@playwright/test');
const F = require('./helpers/circulation-fixtures');
const ft = require('./helpers/feature-toggles');

test.skip(!F.CONFIGURED, 'E2E credentials not configured');
test.use({ ...devices['iPhone 13'], defaultBrowserType: 'chromium' });

const MODULES = ['stocktake', 'marc', 'visitor_log', 'suppliers', 'patron_cards', 'user_import', 'circulation_rules', 'closures_calendar', 'fines', 'automatic_fines'];
const TAG = `MOB090${String(Date.now()).slice(-6)}`;

/** What a phone user would trip on, measured in the page. */
async function phoneDefects(page) {
  return page.evaluate(() => {
    const vw = window.innerWidth;
    const shown = (el) => { const r = el.getBoundingClientRect(); const cs = getComputedStyle(el); return r.width > 0 && r.height > 0 && cs.visibility !== 'hidden' && cs.display !== 'none'; };
    const name = (el) => el.id ? `#${el.id}` : `${el.tagName.toLowerCase()}.${String(el.className).split(/\s+/).slice(0, 3).join('.')}`;
    const sideways = [];
    for (const el of document.querySelectorAll('main *')) {
      if (!shown(el) || el.closest('.tox')) continue; // the rich text editor's own toolbar
      const o = getComputedStyle(el).overflowX;
      if ((o === 'auto' || o === 'scroll') && el.clientWidth > 100 && el.scrollWidth > el.clientWidth + 4) sideways.push(`${name(el)} ${el.clientWidth}/${el.scrollWidth}`);
    }
    const small = [];
    for (const el of document.querySelectorAll('main input:not([type=checkbox]):not([type=radio]):not([type=hidden]):not([type=file]):not([type=range]), main select, main textarea')) {
      if (shown(el) && parseFloat(getComputedStyle(el).fontSize) < 16) small.push(`${name(el)} ${getComputedStyle(el).fontSize}`);
    }
    return { pageWidth: document.documentElement.scrollWidth, vw, sideways, small };
  });
}

test.describe.serial('0.9.0 admin pages on a phone', () => {
  let snapshot;
  let sessionId = 0;
  let supplierId = 0;
  let patron;
  let book;

  test.beforeAll(async () => {
    snapshot = await ft.enableFeatures(MODULES);
    F.dbQuery(`INSERT INTO inventario_sessioni (nome, stato, totale_attese) VALUES ('${TAG} revisione', 'aperta', 3)`);
    sessionId = F.dbInt(`SELECT id FROM inventario_sessioni WHERE nome='${TAG} revisione'`);
    F.dbQuery(`INSERT INTO fornitori (nome, referente, email, telefono) VALUES ('${TAG} Libreria', 'Mario Rossi', 'ordini@example.test', '0612345678')`);
    supplierId = F.dbInt(`SELECT id FROM fornitori WHERE nome='${TAG} Libreria'`);
    patron = F.createPatron(TAG);
    book = F.createTitle(`${TAG} mobile`, 'libro', 1);
    F.dbQuery(`UPDATE copie SET stato='prestato' WHERE id=${book.copyIds[0]}`);
    F.dbQuery(`INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo) VALUES (${book.id}, ${book.copyIds[0]}, ${patron.id}, DATE_SUB(CURDATE(), INTERVAL 20 DAY), DATE_SUB(CURDATE(), INTERVAL 6 DAY), 'in_ritardo', 'diretto', 1)`);
  });

  test.afterAll(async () => {
    try { F.dbQuery(`DELETE FROM inventario_sessioni WHERE id=${sessionId}`); } catch { /* */ }
    try { F.dbQuery(`DELETE FROM fornitori WHERE id=${supplierId}`); } catch { /* */ }
    try { F.dbQuery(`DELETE FROM movimenti_lettore WHERE utente_id=${patron.id}`); } catch { /* */ }
    if (book) F.deleteTitles([book.id]);
    if (patron) F.deletePatrons([patron.id]);
    await ft.restoreFeatures(snapshot);
  });

  for (const [label, path] of [
    ['attendance register', () => '/admin/attendance'],
    ['closures calendar', () => '/admin/calendar'],
    ['stocktake sessions', () => '/admin/inventory'],
    ['stocktake session', () => `/admin/inventory/${sessionId}`],
    ['MARC import', () => '/admin/books/import/marc'],
    ['suppliers', () => '/admin/suppliers'],
    ['supplier form', () => `/admin/suppliers/edit/${supplierId}`],
    ['patron import', () => '/admin/users/import'],
    ['settings: modules', () => '/admin/settings?tab=modules'],
    ['settings: loans and fines', () => '/admin/settings?tab=loans'],
    ['settings: cards', () => '/admin/settings?tab=cards'],
    ['settings: circulation', () => '/admin/settings?tab=circulation'],
    ['reader record and ledger', () => `/admin/users/details/${patron.id}`],
    ['return with late fine', () => `/admin/loans/returned/${F.dbInt(`SELECT id FROM prestiti WHERE libro_id=${book.id}`)}`],
  ]) {
    test(`${label}: no sideways scrolling, no field that makes iOS zoom`, async ({ page }) => {
      await F.loginAsAdmin(page);
      const response = await page.goto(path(), { waitUntil: 'networkidle' });
      expect(response?.status()).toBe(200);
      const d = await phoneDefects(page);
      expect(d.pageWidth, 'page wider than the phone').toBeLessThanOrEqual(d.vw);
      expect(d.sideways, 'blocks that scroll sideways').toEqual([]);
      expect(d.small, 'fields under 16 px').toEqual([]);
    });
  }

  test('the calendar month arrows sit side by side', async ({ page }) => {
    await F.loginAsAdmin(page);
    await page.goto('/admin/calendar');
    const prev = await page.locator('#calendar-prev').boundingBox();
    const next = await page.locator('#calendar-next').boundingBox();
    expect(prev && next && Math.abs(prev.y - next.y) < 4 && next.x > prev.x + prev.width).toBe(true);
  });

  test('a table stacks as cards, each value under its column name', async ({ page }) => {
    await F.loginAsAdmin(page);
    await page.goto('/admin/suppliers');
    const row = page.locator('#fornitori-table tbody tr', { hasText: `${TAG} Libreria` });
    await expect(row.locator('td').nth(1)).toHaveAttribute('data-label', /Referente/i);
    const cells = await row.locator('td').evaluateAll((tds) => tds.slice(0, 2).map((td) => Math.round(td.getBoundingClientRect().top)));
    expect(cells[1]).toBeGreaterThan(cells[0]); // one under the other, not side by side
    await expect(page.locator('#fornitori-table thead')).toBeHidden();
  });

  test('a three-digit notification count shows as 99+ inside the badge', async ({ page }) => {
    await page.route('**/admin/notifications/unread-count', (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ count: 812 }) }));
    await F.loginAsAdmin(page);
    await page.goto('/admin/dashboard');
    const badge = page.locator('#notifications-badge');
    await expect(badge).toHaveText('99+');
    const fits = await badge.evaluate((b) => b.scrollWidth <= b.clientWidth + 1);
    expect(fits).toBe(true);
  });
});
