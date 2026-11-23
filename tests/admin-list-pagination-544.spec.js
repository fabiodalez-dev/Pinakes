// @ts-check
// #544: the admin lists (books, authors, publishers, users, loans) are long
// pages. The pager now also sits above the table, and moving to another page
// from the pager below the table brings the top of the table back into view,
// so the first row of the new page is what the user sees. Moving from the
// pager above, already in view, leaves the scroll where it is.
//
// Run: /tmp/run-e2e.sh tests/admin-list-pagination-544.spec.js --config=tests/playwright.config.js --workers=1
const { test, expect } = require('@playwright/test');
const F = require('./helpers/circulation-fixtures');

test.skip(!F.CONFIGURED, 'E2E credentials not configured');
// A short window, so ten rows already run past its bottom edge.
test.use({ viewport: { width: 1280, height: 520 } });

const LISTS = [
  { name: 'books', path: '/admin/books', table: '#libri-table', count: "SELECT COUNT(*) FROM libri WHERE deleted_at IS NULL" },
  { name: 'authors', path: '/admin/authors', table: '#autori-table', count: 'SELECT COUNT(*) FROM autori' },
  { name: 'publishers', path: '/admin/publishers', table: '#editori-table', count: 'SELECT COUNT(*) FROM editori' },
  { name: 'users', path: '/admin/users', table: '#utenti-table', count: 'SELECT COUNT(*) FROM utenti' },
  { name: 'loans', path: '/admin/loans', table: '#prestiti-table', count: 'SELECT COUNT(*) FROM prestiti' },
];

/** The DataTables wrapper of the table, and its two pagers (above, below). */
function parts(page, table) {
  const wrapper = page.locator('.dt-container').filter({ has: page.locator(table) });
  const pagers = wrapper.locator('.dt-paging');
  return { wrapper, pagers };
}

/** Where the top of the table wrapper is, in the window. */
async function wrapperTop(page, table) {
  return page.locator(table).evaluate((t) => (t.closest('.dt-container') || t).getBoundingClientRect().top);
}

async function firstRowText(page, table) {
  return (await page.locator(`${table} tbody tr`).first().innerText()).trim();
}

/** Waits until the window has stopped scrolling (a smooth scroll moves over several frames). */
async function settledScrollY(page) {
  let last = -1;
  await expect.poll(async () => {
    const y = await page.evaluate(() => new Promise((r) => setTimeout(() => r(window.scrollY), 150)));
    const still = y === last;
    last = y;
    return still;
  }, { timeout: 8000, intervals: [0] }).toBe(true);
  return last;
}

/** Where the window would have to put the wrapper top: a small margin, plus a header pinned to the top right now. */
async function expectedWrapperTop(page) {
  return page.evaluate(() => {
    const h = document.querySelector('header.sticky, header.fixed');
    const b = h ? h.getBoundingClientRect() : null;
    return 16 + (b && b.top <= 0 && b.bottom > 0 ? b.bottom : 0);
  });
}

/** Waits until the first row changes after a page move (server-side tables redraw after their AJAX answer). */
async function waitForNewPage(page, table, before) {
  await expect.poll(() => firstRowText(page, table), { timeout: 15000 }).not.toBe(before);
}

test.describe.serial('#544 admin list pagination', () => {
  test.beforeEach(async ({ page }) => {
    F.clearRateLimits();
    await F.loginAsAdmin(page);
  });

  for (const list of LISTS) {
    test(`${list.name}: pager above the table, and back to the top after a page change below`, async ({ page }) => {
      test.skip(F.dbInt(list.count) <= 10, `needs more than 10 ${list.name}`);

      await page.goto(F.BASE + list.path);
      const { wrapper, pagers } = parts(page, list.table);
      await expect(page.locator(`${list.table} tbody tr`).first()).toBeVisible({ timeout: 15000 });

      // Ten rows per page, from the page-size menu above the table.
      let before = await firstRowText(page, list.table);
      await wrapper.locator('.dt-length select').selectOption('10');
      await expect(page.locator(`${list.table} tbody tr`)).toHaveCount(10, { timeout: 15000 });

      // Two pagers: one above the table, one below.
      await expect(pagers).toHaveCount(2);
      // Two navigation landmarks a screen reader can tell apart: each is named
      // after where it sits, not the shared "pagination" DataTables gives both.
      const navNames = await pagers.locator('nav').evaluateAll((navs) => navs.map((n) => n.getAttribute('aria-label') || ''));
      expect(navNames).toHaveLength(2);
      expect(navNames[0]).not.toBe(navNames[1]);
      for (const name of navNames) expect(name).not.toMatch(/^(pagination)?$/);
      const tableBox = await page.locator(list.table).boundingBox();
      const topPagerBox = await pagers.first().boundingBox();
      const bottomPagerBox = await pagers.last().boundingBox();
      expect(topPagerBox && tableBox && topPagerBox.y < tableBox.y, 'first pager above the table').toBeTruthy();
      expect(bottomPagerBox && tableBox && bottomPagerBox.y > tableBox.y + tableBox.height - 1, 'second pager below the table').toBeTruthy();

      // From the bottom pager: the page scrolled down to it, the table top
      // above the window. After the change the table top is back in view.
      await pagers.last().scrollIntoViewIfNeeded();
      expect(await wrapperTop(page, list.table), 'precondition: table top scrolled out of view').toBeLessThan(0);
      before = await firstRowText(page, list.table);
      await pagers.last().locator('.dt-paging-button.next').click();
      await waitForNewPage(page, list.table, before);
      // Wait for the scroll to finish, then check where it ended: the wrapper
      // top just below the window edge (and below a pinned header, if any).
      await settledScrollY(page);
      const landed = await wrapperTop(page, list.table);
      const expected = await expectedWrapperTop(page);
      // A short last page (11 rows at ten per page leave one) may not leave
      // enough document below the table to scroll it all the way up: then the
      // window stops at its bottom, with the table top lower but in view.
      const atBottom = await page.evaluate(() => window.scrollY + window.innerHeight >= document.documentElement.scrollHeight - 2);
      if (atBottom) expect(landed, `wrapper top landed at ${landed}`).toBeGreaterThanOrEqual(expected - 2);
      else expect(Math.abs(landed - expected), `wrapper top landed at ${landed}`).toBeLessThanOrEqual(2);
      const firstRowBox = await page.locator(`${list.table} tbody tr`).first().boundingBox();
      expect(firstRowBox && firstRowBox.y >= 0 && firstRowBox.y < 520, 'first row of the new page in the window').toBeTruthy();

      // From the top pager, in view, with the wrapper's own top edge (its
      // padding and the page-size row) already above the window: the scroll
      // stays where it is.
      await page.evaluate((sel) => {
        const pager = document.querySelector(sel).closest('.dt-container').querySelector('.dt-paging');
        window.scrollBy(0, pager.getBoundingClientRect().top - 2);
      }, list.table);
      const scrollBefore = await settledScrollY(page);
      if (list.name === 'loans') {
        // The loans wrapper pads its top row: its edge leaves the window while
        // the pager under it is still fully visible.
        expect(await wrapperTop(page, list.table), 'precondition: wrapper edge above the window').toBeLessThan(0);
      }
      before = await firstRowText(page, list.table);
      await pagers.first().locator('.dt-paging-button.previous').click();
      await waitForNewPage(page, list.table, before);
      expect(Math.abs((await settledScrollY(page)) - scrollBefore), 'scroll kept after a change from the top pager').toBeLessThanOrEqual(2);
    });
  }

  test('books: a page request that fails does not scroll on the next unrelated redraw', async ({ page }) => {
    test.skip(F.dbInt(LISTS[0].count) <= 10, 'needs more than 10 books');
    const table = LISTS[0].table;
    page.on('dialog', (d) => d.dismiss().catch(() => {})); // DataTables reports the failed request
    await page.goto(F.BASE + LISTS[0].path);
    const { wrapper, pagers } = parts(page, table);
    await expect(page.locator(`${table} tbody tr`).first()).toBeVisible({ timeout: 15000 });
    await wrapper.locator('.dt-length select').selectOption('10');
    await expect(page.locator(`${table} tbody tr`)).toHaveCount(10, { timeout: 15000 });

    // The next page request fails: no page is drawn, so no scroll either.
    let failNext = true;
    await page.route('**/api/libri**', (route) => {
      if (failNext) { failNext = false; return route.fulfill({ status: 500, body: 'error' }); }
      return route.continue();
    });
    await pagers.last().scrollIntoViewIfNeeded();
    expect(await wrapperTop(page, table), 'precondition: table top above the window').toBeLessThan(0);
    await pagers.last().locator('.dt-paging-button.next').click();
    await expect.poll(() => failNext, { timeout: 15000 }).toBe(false);
    await page.waitForTimeout(500);

    // Later the user sorts by a column from where they are: the page must stay put.
    const scrollBefore = await settledScrollY(page);
    await page.evaluate((sel) => window.jQuery(sel).DataTable().order([4, 'desc']).draw(false), table);
    await expect(page.locator(`${table} tbody tr`).first()).not.toHaveText(/Caricamento/, { timeout: 15000 });
    await page.unroute('**/api/libri**');
    expect(Math.abs((await settledScrollY(page)) - scrollBefore), 'a sort after a failed page request must not scroll').toBeLessThanOrEqual(2);
  });
});
