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
    // "cut": a height set inline that is shorter than the two lines the
    // grid reserves for a title (the CSS clamp to two lines is intended).
    const cut = t && t.style.height !== '' ? parseFloat(t.style.height) < parseFloat(getComputedStyle(t).minHeight) - 1 : false;
    return { height: t ? t.style.height : '', cut, placeholder: !!c.querySelector('.subtitle-ph') };
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
      // A thumbnail (the design's 64px column), never the grid's full cover.
      expect(row.cover.w).toBeLessThanOrEqual(72);
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
    // The status is an eyebrow above the title, in the text column; the cover
    // starts level with the title, not with the eyebrow.
    const row = await page.locator('#books-grid .book-card').first().evaluate(card => {
      const body = card.querySelector('.pk-card__body').getBoundingClientRect();
      const status = card.querySelector('.pk-card__status').getBoundingClientRect();
      const cover = card.querySelector('.pk-book').getBoundingClientRect();
      return {
        statusAbove: status.bottom <= body.top + 1 && status.left >= cover.right,
        coverLevelWithTitle: Math.abs(cover.top - body.top) <= 2,
      };
    });
    expect(row.statusAbove).toBe(true);
    expect(row.coverLevelWithTitle).toBe(true);
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
    // The grid clamps a long title to two lines on purpose (with an ellipsis);
    // what must never happen is a title cut by a height the row alignment
    // left inline after the switch.
    for (const card of before) expect(card.cut).toBe(false);
  });

  test('in the grid every row lines its cards up: title, author, publisher and Details at the same height', async ({ page }) => {
    for (const width of [1440, 900]) {
      await page.setViewportSize({ width, height: 1000 });
      await page.goto(CATALOG);
      test.skip(await page.locator('#books-grid .book-card').count() < 2, 'not enough books');
      await page.waitForLoadState('networkidle');
      const offsets = await page.$$eval('#books-grid .book-card', (cards) => {
        const rows = {};
        for (const c of cards) {
          const top = Math.round(c.getBoundingClientRect().top);
          const at = (sel) => { const e = c.querySelector(sel); return e && getComputedStyle(e).display !== 'none' ? e.getBoundingClientRect().top : null; };
          (rows[top] = rows[top] || []).push({ title: at('.pk-card__title'), author: at('.pk-card__author'), meta: at('.pk-card__meta'), actions: at('.pk-card__actions') });
        }
        const out = [];
        for (const r of Object.values(rows)) {
          if (r.length < 2) continue;
          for (const k of ['title', 'author', 'meta', 'actions']) {
            const v = r.map((x) => x[k]).filter((x) => x !== null);
            if (v.length > 1) out.push(Math.max(...v) - Math.min(...v));
          }
        }
        return out;
      });
      // At least one row of two cards was compared, or the test proves nothing.
      expect(offsets.length, `cards compared at ${width}px`).toBeGreaterThan(0);
      expect(Math.max(...offsets), `rows line up at ${width}px`).toBeLessThan(2);
    }
  });
});
