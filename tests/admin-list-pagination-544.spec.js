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
      await expect.poll(() => wrapperTop(page, list.table), { timeout: 5000 }).toBeGreaterThanOrEqual(0);
      const firstRowBox = await page.locator(`${list.table} tbody tr`).first().boundingBox();
      expect(firstRowBox && firstRowBox.y >= 0 && firstRowBox.y < 520, 'first row of the new page in the window').toBeTruthy();
      // Not hidden under the sticky header either.
      const headerBottom = await page.locator('header.sticky').evaluate((h) => h.getBoundingClientRect().bottom);
      expect(await wrapperTop(page, list.table)).toBeGreaterThanOrEqual(headerBottom - 1);

      // From the top pager, already in view: the scroll stays where it is.
      const scrollBefore = await page.evaluate(() => window.scrollY);
      before = await firstRowText(page, list.table);
      await pagers.first().locator('.dt-paging-button.previous').click();
      await waitForNewPage(page, list.table, before);
      await page.waitForTimeout(400); // a smooth scroll, if one had started, would have moved by now
      expect(Math.abs((await page.evaluate(() => window.scrollY)) - scrollBefore)).toBeLessThanOrEqual(2);
    });
  }
});
