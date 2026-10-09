// @ts-check
/**
 * The suggestions of the home search open over the page, never under it.
 * Up to 0.8.0 the 2026 hero clipped them (overflow: hidden): on a phone the
 * list was cut at the hero's edge and the statistics band and the next
 * section covered it. A visitor types in the hero's search box and must see
 * the results below it, on a phone and on a desktop.
 *
 * Run: /tmp/run-e2e.sh tests/search-suggestions-layer.spec.js --config=tests/playwright.config.js --workers=1
 */
const { test, expect } = require('@playwright/test');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';

for (const [name, viewport] of [['phone', { width: 375, height: 812 }], ['desktop', { width: 1440, height: 900 }]]) {
  test(`${name}: the hero search suggestions are on top of the sections below`, async ({ page }) => {
    await page.setViewportSize(viewport);
    await page.goto(`${BASE}/`);
    const input = page.locator('.pk-hero .search-input').first();
    test.skip(await input.count() === 0, 'the home has no hero search');
    await input.scrollIntoViewIfNeeded();
    await input.click();
    await input.pressSequentially('il', { delay: 60 });
    const results = page.locator('.pk-hero .search-results.is-visible');
    await expect(results).toBeVisible({ timeout: 10000 });
    await expect(results.locator('a').first()).toBeVisible();
    // While typing, only the rounded box shows the focus: no second rectangle
    // around the text.
    const ring = await input.evaluate((el) => ({ shadow: getComputedStyle(el).boxShadow, outline: getComputedStyle(el).outlineStyle }));
    expect(ring.shadow, 'no ring around the typed text').toBe('none');
    expect(ring.outline).toBe('none');

    // Bring the box to the top of the screen, as a visitor scrolls to read on.
    await page.evaluate(() => {
      const box = document.querySelector('.pk-hero .search-input');
      if (box) window.scrollBy(0, box.getBoundingClientRect().top - 90);
    });
    const covered = await results.evaluate((list) => {
      const r = list.getBoundingClientRect();
      const bottom = Math.min(r.bottom, window.innerHeight);
      const hits = [];
      for (let y = r.top + 10; y < bottom - 4; y += 24) {
        const el = document.elementFromPoint(r.left + r.width / 2, y);
        if (!el || list.contains(el)) continue;
        // Fixed controls (tab bar, cookie and back-to-top buttons) float over
        // everything by design; any other element is a section on top.
        let fixed = false;
        for (let a = el; a; a = a.parentElement) if (getComputedStyle(a).position === 'fixed' || getComputedStyle(a).position === 'sticky') { fixed = true; break; }
        if (!fixed) hits.push(`${Math.round(y)}: ${(el.closest('section') || el).className}`);
      }
      return { height: Math.round(bottom - r.top), hits };
    });
    expect(covered.height, 'the list is not cut at the hero').toBeGreaterThan(200);
    expect(covered.hits, 'nothing of the page covers the results').toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth), 'no sideways scroll').toBe(false);
  });
}
