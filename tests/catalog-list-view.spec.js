// @ts-check
/**
 * The catalogue's "Lista" view (2026 design): every book is one row with a
 * small cover, the title, author and publisher readable on the left, and the
 * availability, the digital editions spelled out, the media type and the
 * wishlist heart on the right. Switching back to the grid must give back the
 * grid exactly as it was: the row alignment of #298 measures cards, and a
 * measure taken in the list would clip the grid's titles.
 */
const { test, expect } = require('@playwright/test');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const CATALOG = `${BASE}/catalogo?sort=title_asc&page=1`;

async function gridState(page) {
  return page.$$eval('#books-grid .book-card', cards => cards.slice(0, 12).map(c => {
    const t = c.querySelector('.book-title');
    return { height: t ? t.style.height : '', clipped: t ? t.scrollHeight > t.clientHeight + 1 : false, placeholder: !!c.querySelector('.subtitle-ph') };
  }));
}

test.describe('Catalogue list view', () => {
  test.beforeEach(async ({ page }) => {
    await page.addInitScript(() => { try { localStorage.removeItem('pinakes-catalog-view'); } catch (e) { /* private mode */ } });
  });

  test('every book is a readable row, nothing hidden', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.goto(CATALOG);
    const cards = page.locator('#books-grid .book-card');
    test.skip(await cards.count() === 0, 'empty catalogue');
    await page.click('[data-pk-view="list"]');
    await expect(page.locator('#books-grid')).toHaveClass(/is-list/);

    const rows = await cards.evaluateAll(list => list.slice(0, 10).map(card => {
      const box = el => { const r = el ? el.getBoundingClientRect() : null; return r ? { l: r.left, r: r.right, t: r.top, b: r.bottom, w: r.width, h: r.height } : null; };
      const title = card.querySelector('.book-title');
      return {
        card: box(card),
        cover: box(card.querySelector('.pk-book')),
        body: box(card.querySelector('.pk-card__body')),
        status: box(card.querySelector('.pk-card__status')),
        heart: box(card.querySelector('.pk-heart')),
        titleClipped: title ? title.scrollHeight > title.clientHeight + 1 : true,
        titleInlineHeight: title ? title.style.height : 'x',
        placeholders: card.querySelectorAll('.subtitle-ph').length,
        author: (card.querySelector('.pk-card__author') || {}).textContent || '',
      };
    }));
    for (const row of rows) {
      // A row, not a card: cover, text, then status and heart further right.
      expect(row.card.h).toBeLessThan(200);
      expect(row.cover.w).toBeLessThanOrEqual(60);
      expect(row.body.l).toBeGreaterThan(row.cover.r);
      expect(row.status.l).toBeGreaterThanOrEqual(row.body.r - 1);
      if (row.heart) expect(row.heart.l).toBeGreaterThanOrEqual(row.status.r - 1);
      // Title and author readable: no grid alignment heights or placeholders.
      expect(row.titleClipped).toBe(false);
      expect(row.titleInlineHeight).toBe('');
      expect(row.placeholders).toBe(0);
      expect(row.author.trim()).not.toBe('');
    }

    // Author and publisher share one line.
    const sameLine = await cards.evaluateAll(list => list
      .map(c => [c.querySelector('.pk-card__author'), c.querySelector('.pk-card__meta:not(.book-meta-empty)')])
      .filter(([a, m]) => a && m)
      .map(([a, m]) => Math.abs(a.getBoundingClientRect().top - m.getBoundingClientRect().top) < 4));
    for (const ok of sameLine) expect(ok).toBe(true);

    // Digital editions carry their name, not only an icon.
    const digital = page.locator('#books-grid .pk-card__status .digital-badge-icon');
    if (await digital.count() > 0) {
      const icon = await digital.first().evaluate(el => ({ w: el.getBoundingClientRect().width, label: getComputedStyle(el, '::after').content }));
      expect(icon.label).not.toBe('none');
      expect(icon.w).toBeGreaterThan(60);
    }
    expect(await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth)).toBe(false);
  });

  test('on a phone the row stacks without overflowing', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 900 });
    await page.goto(CATALOG);
    test.skip(await page.locator('#books-grid .book-card').count() === 0, 'empty catalogue');
    await page.click('[data-pk-view="list"]');
    expect(await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth)).toBe(false);
    const statusBelow = await page.locator('#books-grid .book-card').first().evaluate(card => {
      const body = card.querySelector('.pk-card__body').getBoundingClientRect();
      const status = card.querySelector('.pk-card__status').getBoundingClientRect();
      return status.top >= body.bottom - 1 && status.left >= card.querySelector('.pk-book').getBoundingClientRect().right;
    });
    expect(statusBelow).toBe(true);
  });

  test('a page loaded through the pager is still a list', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.goto(CATALOG);
    const next = page.locator('#pagination-container a.page-link').last();
    test.skip(await next.count() === 0, 'one page only');
    await page.click('[data-pk-view="list"]');
    // The pager swaps the cards in through AJAX, then shows the container
    // again: it must not pin it back to a grid.
    await next.click();
    await page.waitForLoadState('networkidle');
    await expect(page.locator('#books-grid .book-card').first()).toBeVisible();
    const r = await page.evaluate(() => {
      const grid = document.getElementById('books-grid');
      const card = grid.querySelector('.book-card');
      return { display: getComputedStyle(grid).display, card: card.getBoundingClientRect().width, body: card.querySelector('.pk-card__body').getBoundingClientRect().width, grid: grid.getBoundingClientRect().width };
    });
    expect(r.display).toBe('flex');
    expect(r.card).toBeGreaterThan(r.grid - 2);
    expect(r.body).toBeGreaterThan(200);
  });

  test('back to the grid, the grid is as it was', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.goto(CATALOG);
    test.skip(await page.locator('#books-grid .book-card').count() === 0, 'empty catalogue');
    await page.waitForLoadState('networkidle');
    const before = await gridState(page);
    await page.click('[data-pk-view="list"]');
    // A resize while in the list re-runs the row alignment.
    await page.setViewportSize({ width: 1300, height: 1000 });
    await page.waitForTimeout(400);
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.waitForTimeout(400);
    await page.click('[data-pk-view="grid"]');
    await expect.poll(() => gridState(page)).toEqual(before);
    for (const card of before) expect(card.clipped).toBe(false);
  });
});
