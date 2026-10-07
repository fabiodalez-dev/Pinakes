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

test.describe('Form fields follow the theme', () => {
  test('a field takes a soft fill and a rule mixed from the theme accent', async ({ page }) => {
    await page.goto(BASE + '/contatti', { waitUntil: 'networkidle' });
    const field = page.locator('main input.form-input').first();
    test.skip(await field.count() === 0, 'no contact form');
    const look = () => field.evaluate(el => { const cs = getComputedStyle(el); return { bg: cs.backgroundColor, border: cs.borderTopColor, width: cs.borderTopWidth }; });
    const before = await look();
    expect(before.width).toBe('1px');
    expect(before.bg).not.toBe('rgb(255, 255, 255)');
    // Another theme's accent: the same field must recolour, with no other change.
    // (Through the CSSOM: the site's CSP rightly refuses an injected <style>.)
    // A theme sets both the accent and its text shade (--primary-text, which
    // the field rule is mixed from); set them as a theme would.
    await page.evaluate(() => {
      document.documentElement.style.setProperty('--primary-color', '#059669', 'important');
      document.documentElement.style.setProperty('--primary-text', '#047b56', 'important');
    });
    const after = await look();
    expect(after.bg).not.toBe(before.bg);
    expect(after.border).not.toBe(before.border);
  });
});

test.describe('Footer "Seguici" column', () => {
  test('social links read like the other footer columns', async ({ page }) => {
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    const social = page.locator('footer .pk-footer .social-links a');
    test.skip(await social.count() === 0, 'no social profile set in Settings');
    const menu = await page.locator('footer .pk-footer__col').first().locator('li a').first().evaluate(a => a.getBoundingClientRect().height);
    const links = await social.evaluateAll(as => as.map(a => { const cs = getComputedStyle(a); return { h: a.getBoundingClientRect().height, bg: cs.backgroundColor, text: a.textContent.trim(), icon: !!a.querySelector('i') }; }));
    for (const l of links) {
      expect(l.bg).toBe('rgba(0, 0, 0, 0)');
      expect(Math.abs(l.h - menu)).toBeLessThanOrEqual(2);
      expect(l.text).not.toBe('');
      expect(l.icon).toBe(true);
    }
  });

  test('on a desktop its rows line up with Menu, four to a column', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    const social = page.locator('footer .pk-footer .social-links a');
    test.skip(await social.count() === 0, 'no social profile set in Settings');
    const r = await page.evaluate(() => {
      const tops = sel => [...new Set([...document.querySelectorAll(sel)].map(a => Math.round(a.getBoundingClientRect().top)))];
      return { menu: tops('footer .pk-footer__col:nth-of-type(2) li a'), social: tops('footer .social-links li a') };
    });
    expect(r.social.length).toBe(Math.min(4, await social.count()));
    r.social.forEach((top, i) => expect(Math.abs(top - r.menu[i])).toBeLessThanOrEqual(1));
  });
});

test.describe('Wanted books (desiderata) covers', () => {
  async function covers(page) {
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    const rows = page.locator('#desiderata-results li');
    test.skip(await rows.count() === 0, 'no wanted books on the home');
    // Every row carries its cover in the frame: the blank-book fallback needs it.
    await expect(page.locator('#desiderata-results li .dw-cover-frame')).toHaveCount(await rows.count());
    return page.locator('#desiderata-results .dw-cover-frame');
  }

  test.describe('on a phone', () => {
    test.use(PHONE);
    test('a cover fills the row, as a book', async ({ page }) => {
      const frames = await covers(page);
      const r = await frames.first().evaluate(f => { const b = f.getBoundingClientRect(); const li = f.closest('li').getBoundingClientRect(); return { w: b.width, h: b.height, row: li.width }; });
      expect(r.w).toBeGreaterThanOrEqual(r.row - 1);
      expect(Math.abs(r.h / r.w - 1.5)).toBeLessThan(0.02);
    });
  });

  test.describe('on a desktop', () => {
    test.use({ viewport: { width: 1280, height: 900 } });
    test('a cover is a readable thumbnail, and a missing one shows the title', async ({ page }) => {
      const frames = await covers(page);
      const sizes = await frames.evaluateAll(fs => fs.map(f => Math.round(f.getBoundingClientRect().width)));
      for (const w of sizes) expect(w).toBeGreaterThanOrEqual(90);
      const blank = page.locator('#desiderata-results .dw-cover-frame.is-blank').first();
      if (await blank.count()) {
        const label = await blank.evaluate(f => getComputedStyle(f, '::after').content);
        expect(label).not.toBe('none');
        expect(label.replace(/^"|"$/g, '')).toBe(await blank.getAttribute('data-title'));
      }
    });
  });
});

test.describe('Genre carousel and archive filters', () => {
  for (const width of [390, 768, 1280]) {
    test(`at ${width}px the genre heading lines up with its books`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(BASE + '/', { waitUntil: 'networkidle' });
      const section = page.locator('.genre-carousel-section').first();
      test.skip(await section.count() === 0, 'no genre carousel');
      const delta = await section.evaluate(s => Math.abs(s.querySelector('.genre-carousel-title').getBoundingClientRect().left - s.querySelector('.carousel-book-card').getBoundingClientRect().left));
      expect(delta).toBeLessThanOrEqual(1);
    });

    test(`at ${width}px the archive year fields fit the filter column`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      const res = await page.goto(BASE + '/archivio', { waitUntil: 'networkidle' });
      test.skip(!res || res.status() >= 400, 'no archive');
      const toggle = page.locator('.filters-mobile-toggle').first();
      if (await toggle.count() && await toggle.isVisible()) await toggle.click();
      const inputs = page.locator('.custom-pages-inputs .pages-input');
      test.skip(await inputs.count() === 0, 'no year range');
      const fits = await inputs.evaluateAll(els => els.every(el => {
        const box = el.getBoundingClientRect();
        let p = el.parentElement;
        while (p && !/(hidden|clip)/.test(getComputedStyle(p).overflowX)) p = p.parentElement;
        return !p || box.right <= p.getBoundingClientRect().right + 1;
      }));
      expect(fits).toBe(true);
    });
  }
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
