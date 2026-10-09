// @ts-check
/**
 * The public site on a phone (2026 design). Guards what a horizontal-scroll
 * check alone misses:
 *  - nothing reaches past the right edge, not even the closed mobile menu
 *    parked off-screen (a full-page capture measures the page as a phone does);
 *  - fields stay visible on a white card: a white field keeps a rule tinted
 *    by the theme accent, so it never vanishes white on white;
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
  test('a field is white with a rule mixed from the theme accent', async ({ page }) => {
    await page.goto(BASE + '/contatti', { waitUntil: 'networkidle' });
    const field = page.locator('main input.form-input').first();
    test.skip(await field.count() === 0, 'no contact form');
    const look = () => field.evaluate(el => { const cs = getComputedStyle(el); return { bg: cs.backgroundColor, border: cs.borderTopColor, width: cs.borderTopWidth }; });
    const before = await look();
    expect(before.width).toBe('1px');
    expect(before.bg).toBe('rgb(255, 255, 255)');
    // Another theme's accent: the same field must recolour, with no other change.
    // (Through the CSSOM: the site's CSP rightly refuses an injected <style>.)
    // A theme sets both the accent and its text shade (--primary-text, which
    // the field rule is mixed from); set them as a theme would.
    await page.evaluate(() => {
      document.documentElement.style.setProperty('--primary-color', '#059669', 'important');
      document.documentElement.style.setProperty('--primary-text', '#047b56', 'important');
    });
    const after = await look();
    expect(after.bg).toBe(before.bg);
    expect(after.border).not.toBe(before.border);
  });
});

test.describe('Book page details', () => {
  // Details and keywords sit under the description and the sidebar, across the
  // whole width: on a desktop no label or value of a detail wraps.
  test('details run across the whole width and stay on one line', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    // The catalogue's latest book with an ISBN-13, the detail every record has.
    await page.goto(BASE + '/catalogo?search=978', { waitUntil: 'networkidle' });
    const href = await page.locator('main a[href]').evaluateAll(as => (as.map(a => a.getAttribute('href')).find(h => /^\/[^/]+\/[^/]+\/\d+$/.test(h || '')) || ''));
    test.skip(href === '', 'no book in the catalogue');
    await page.goto(new URL(href, BASE).href, { waitUntil: 'networkidle' });
    const r = await page.evaluate(() => {
      const details = document.querySelector('#book-details-section');
      if (!details) return null;
      const wrap = details.closest('.pk-wrap').getBoundingClientRect();
      const lh = el => parseFloat(getComputedStyle(el).lineHeight) || 22;
      return {
        inMainColumn: !!details.closest('.pk-bookbody__main'),
        fullWidth: Math.abs(details.getBoundingClientRect().width - (wrap.width - parseFloat(getComputedStyle(details.closest('.pk-wrap')).paddingLeft) * 2)) < 2,
        wrapped: [...details.querySelectorAll('.meta-item:not(.meta-item--genre)')].filter(m => [...m.children].some(c => c.getBoundingClientRect().height > lh(c) * 1.5)).map(m => m.innerText.replace(/\s+/g, ' ')),
      };
    });
    test.skip(r === null, 'the book has no details');
    expect(r.inMainColumn).toBe(false);
    expect(r.fullWidth).toBe(true);
    expect(r.wrapped).toEqual([]);
  });

  // On a phone no row of the book page is left uneven: four facts (year,
  // pages, format, ISBN) go two by two, not three and one; the share buttons
  // and the citation actions one per row; the citation styles on one line.
  // Each title keeps room above its buttons. 412px is a common large phone,
  // where the facts used to fit three to a row.
  for (const width of [390, 412]) {
    test(`on a ${width}px phone every row of the book page is even`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(BASE + '/catalogo?search=978', { waitUntil: 'networkidle' });
      const hrefs = await page.locator('main a[href]').evaluateAll(as => [...new Set(as.map(a => a.getAttribute('href')).filter(h => /^\/[^/]+\/[^/]+\/\d+$/.test(h || '')))].slice(0, 8));
      test.skip(hrefs.length === 0, 'no book in the catalogue');
      let found = false;
      for (const href of hrefs) {
        await page.goto(new URL(href, BASE).href, { waitUntil: 'networkidle' });
        if (await page.locator('.pk-quick > .pk-quick__item').count() === 4) { found = true; break; }
      }
      test.skip(!found, 'no book with year, pages, format and ISBN');
      const r = await page.evaluate(() => {
        const tops = (els) => els.filter((e) => e.offsetParent).map((e) => Math.round(e.getBoundingClientRect().top));
        const rows = (t) => Object.values(t.reduce((m, y) => { m[y] = (m[y] || 0) + 1; return m; }, {}));
        const gap = (title, first) => (title && first ? Math.round(first.getBoundingClientRect().top - title.getBoundingClientRect().bottom) : null);
        const share = [...document.querySelectorAll('#book-share-card .social-share-btn')];
        const actions = document.querySelector('.pk-citebox__actions');
        const acts = actions ? [...actions.querySelectorAll('a, button')] : [];
        const last = acts.filter((e) => e.offsetParent).pop();
        const lastBox = last ? last.getBoundingClientRect() : null;
        const clipper = last ? last.closest('#book-cite-card') : null;
        return {
          facts: rows(tops([...document.querySelectorAll('.pk-quick > .pk-quick__item')])),
          share: rows(tops(share)),
          shareGap: gap(document.querySelector('#book-share-card .card-header h6'), share.find((e) => e.offsetParent)),
          tabs: rows(tops([...document.querySelectorAll('.pk-citebox__tab')])),
          citeGap: gap(document.querySelector('#book-cite-card .card-header h6'), document.querySelector('.pk-citebox__tabs')),
          actions: rows(tops(acts)),
          lastActionClipped: !!(clipper && lastBox && getComputedStyle(clipper).overflow !== 'visible' && clipper.getBoundingClientRect().bottom <= lastBox.bottom + 0.5),
          sideways: document.documentElement.scrollWidth > window.innerWidth,
          // The availability ("Disponibile") and the buttons under it share one left edge.
          availOffsets: (() => {
            const badge = document.querySelector('.pk-availbox .availability-badge');
            const btns = [...document.querySelectorAll('.pk-availbox .action-buttons .ui-button')].filter((e) => e.offsetParent);
            return badge ? btns.map((b) => Math.round(Math.abs(b.getBoundingClientRect().left - badge.getBoundingClientRect().left))) : [];
          })(),
        };
      });
      expect(r.facts, 'four facts, two by two').toEqual([2, 2]);
      if (r.share.length) expect(Math.max(...r.share), 'one share button per row').toBe(1);
      if (r.shareGap !== null) expect(r.shareGap, 'room under "Condividi"').toBeGreaterThanOrEqual(8);
      if (r.tabs.length) expect(r.tabs.length, 'the citation styles on one line').toBe(1);
      if (r.citeGap !== null) expect(r.citeGap, 'room under "Cita questo libro"').toBeGreaterThanOrEqual(8);
      if (r.actions.length) expect(Math.max(...r.actions), 'one citation action per row').toBe(1);
      expect(r.lastActionClipped, 'the last citation button is not cut').toBe(false);
      expect(r.sideways).toBe(false);
      for (const off of r.availOffsets) expect(off, 'the buttons start where "Disponibile" starts').toBeLessThanOrEqual(1);
    });

    // Available or not ("Disponibile", "Non disponibile oggi"), the status and
    // the buttons under it (Request a loan / Reserve, Favourites) start at the
    // same left edge and fill the box: they used to be capped at 300px and
    // centred, a step to the right of the status.
    test(`on a ${width}px phone the availability lines up with its buttons`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(BASE + '/catalogo', { waitUntil: 'networkidle' });
      const hrefs = await page.locator('main a[href]').evaluateAll(as => [...new Set(as.map(a => a.getAttribute('href')).filter(h => /^\/[^/]+\/[^/]+\/\d+$/.test(h || '')))].slice(0, 8));
      test.skip(hrefs.length === 0, 'no book in the catalogue');
      let checked = 0;
      for (const href of hrefs) {
        await page.goto(new URL(href, BASE).href, { waitUntil: 'networkidle' });
        const m = await page.evaluate(() => {
          const box = document.querySelector('.pk-availbox');
          const badge = box && box.querySelector('.availability-badge');
          const btns = box ? [...box.querySelectorAll('.action-buttons .ui-button')].filter((e) => e.offsetParent) : [];
          if (!badge || btns.length === 0) return null;
          const inner = box.getBoundingClientRect().right - parseFloat(getComputedStyle(box).paddingRight);
          return {
            state: badge.textContent.trim(),
            left: btns.map((b) => Math.round(Math.abs(b.getBoundingClientRect().left - badge.getBoundingClientRect().left))),
            right: btns.map((b) => Math.round(Math.abs(b.getBoundingClientRect().right - inner))),
          };
        });
        if (!m) continue;
        checked++;
        for (const off of m.left) expect(off, `${m.state}: the buttons start where the status starts`).toBeLessThanOrEqual(1);
        for (const off of m.right) expect(off, `${m.state}: the buttons fill the box`).toBeLessThanOrEqual(1);
      }
      test.skip(checked === 0, 'no book with loan buttons');
    });
  }
});

test.describe('Search boxes draw one border', () => {
  // The box carries the rule and the fill; the input inside it draws neither,
  // or the page shows a field inside a field.
  for (const path of ['/catalogo', '/emeroteca', '/archivio']) {
    test(`${path}: the input inside a search box has no border of its own`, async ({ page }) => {
      const res = await page.goto(BASE + path, { waitUntil: 'networkidle' });
      test.skip(!res || res.status() !== 200, `${path} not served`);
      const inputs = await page.$$eval('main :is(.search-box, .pk-filter-search) input', els => els
        .filter(el => el.getBoundingClientRect().width > 0)
        .map(el => { const cs = getComputedStyle(el); return { name: el.name || el.placeholder, border: cs.borderTopWidth, bg: cs.backgroundColor, shadow: cs.boxShadow }; }));
      test.skip(inputs.length === 0, 'no search box on this page');
      for (const i of inputs) {
        expect(i, `${i.name}`).toEqual({ name: i.name, border: '0px', bg: 'rgba(0, 0, 0, 0)', shadow: 'none' });
      }
    });
  }
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
