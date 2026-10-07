// @ts-check
/**
 * The public site on a phone (2026 design). Guards what a horizontal-scroll
 * check alone misses:
 *  - nothing reaches past the right edge, not even the closed mobile menu
 *    parked off-screen (a full-page capture measures the page as a phone does);
 *  - fields stay visible without a border: on a white card they take the soft
 *    fill instead of vanishing white on white;
 *  - the folded catalogue filters leave no empty room under their bar;
 *  - the home hero stacks with the books above the title (beside it on desktop).
 */
const { test, expect } = require('@playwright/test');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const PHONE = { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true };

const PAGES = ['/', '/catalogo', '/catalogo?search=a', '/eventi', '/chi-siamo', '/contatti', '/archivio', '/accedi', '/registrati', '/password-dimenticata'];

test.describe('Public site on a phone', () => {
  test.use(PHONE);

  for (const path of PAGES) {
    test(`${path} is no wider than the screen`, async ({ page }) => {
      const res = await page.goto(BASE + path, { waitUntil: 'networkidle' });
      test.skip(!res || res.status() >= 400, `${path} not available here`);
      const shot = await page.screenshot({ fullPage: true });
      // PNG IHDR: the width is the big-endian uint32 at byte 16.
      expect(shot.readUInt32BE(16), 'full-page width').toBe(390);
      expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);
    });
  }

  test('the mobile menu still opens fully on screen and closes', async ({ page }) => {
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    await page.click('.mobile-menu-toggle');
    const drawer = page.locator('.mobile-menu-content');
    await expect(drawer).toBeVisible();
    await expect.poll(async () => drawer.evaluate(el => Math.round(el.getBoundingClientRect().right))).toBe(390);
    await page.click('#mobileMenuClose');
    await expect(page.locator('#mobileMenuOverlay')).toBeHidden();
  });

  for (const path of ['/accedi', '/registrati', '/password-dimenticata']) {
    test(`${path}: every field is visible on its card`, async ({ page }) => {
      await page.goto(BASE + path, { waitUntil: 'networkidle' });
      const fields = await page.$$eval('main :is(input[type=text], input[type=email], input[type=password], input[type=tel], input[type=date], select, textarea)', els => els
        .filter(el => el.getBoundingClientRect().width > 0)
        .map(el => {
          let p = el.parentElement, bg = '';
          while (p) { const c = getComputedStyle(p).backgroundColor; if (c !== 'rgba(0, 0, 0, 0)') { bg = c; break; } p = p.parentElement; }
          const cs = getComputedStyle(el);
          const box = el.getBoundingClientRect();
          const card = el.closest('.auth-card, .card');
          return {
            name: el.name,
            distinct: cs.backgroundColor !== bg || parseFloat(cs.borderTopWidth) > 0 || cs.boxShadow !== 'none',
            inside: !card || box.right <= card.getBoundingClientRect().right + 1,
          };
        }));
      expect(fields.length).toBeGreaterThan(0);
      for (const f of fields) {
        expect(f.distinct, `${f.name} stands out from its card`).toBe(true);
        expect(f.inside, `${f.name} stays inside its card`).toBe(true);
      }
    });
  }

  test('folded catalogue filters leave no room under their bar', async ({ page }) => {
    await page.goto(BASE + '/catalogo?search=a', { waitUntil: 'networkidle' });
    const toggle = page.locator('#catalog-filters-toggle');
    await expect(toggle).toHaveAttribute('aria-expanded', 'false');
    const gap = () => page.evaluate(() => {
      const bar = document.querySelector('.filters-header').getBoundingClientRect();
      const next = document.querySelector('.catalog-filters-column').nextElementSibling.getBoundingClientRect();
      return Math.round(next.top - bar.bottom);
    });
    expect(await gap()).toBeLessThanOrEqual(24);
    await toggle.click();
    await expect(page.locator('#catalog-filters-content')).toBeVisible();
    await toggle.click();
    await expect(page.locator('#catalog-filters-content')).toBeHidden();
    expect(await gap()).toBeLessThanOrEqual(24);
  });

  test('the home hero shows the books above the title', async ({ page }) => {
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    test.skip(await page.locator('.pk-fan').count() === 0 || await page.locator('body.pk-hero-centered').count() > 0, 'no cover hero');
    const r = await page.evaluate(() => {
      const fan = document.querySelector('.pk-fan').getBoundingClientRect();
      const title = document.querySelector('.pk-hero__title').getBoundingClientRect();
      return { fanH: fan.height, fanBottom: fan.bottom, titleTop: title.top };
    });
    expect(r.fanH).toBeGreaterThan(200);
    expect(r.fanBottom).toBeLessThanOrEqual(r.titleTop);
  });
});

test.describe('Home hero on a desktop', () => {
  test.use({ viewport: { width: 1440, height: 900 } });

  test('the books sit to the right of the title', async ({ page }) => {
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    test.skip(await page.locator('.pk-fan').count() === 0 || await page.locator('body.pk-hero-centered').count() > 0, 'no cover hero');
    const r = await page.evaluate(() => {
      const fan = document.querySelector('.pk-fan').getBoundingClientRect();
      const title = document.querySelector('.pk-hero__title').getBoundingClientRect();
      return { fanLeft: fan.left, titleRight: title.right, fanTop: fan.top, titleBottom: title.bottom };
    });
    expect(r.fanLeft).toBeGreaterThanOrEqual(r.titleRight - 1);
    expect(r.fanTop).toBeLessThan(r.titleBottom);
  });
});
