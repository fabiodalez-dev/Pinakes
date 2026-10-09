// @ts-check
/**
 * Phone tab bar (as the Android app's bottom navigation): hidden on load,
 * shown from the first scroll, hidden again while the footer is in view, and
 * never on a desktop width. Signed-in readers get Loans and Favourites, with
 * the favourites count as a badge.
 *
 * Run: /tmp/run-e2e.sh tests/mobile-tabbar.spec.js --config=tests/playwright.config.js --workers=1
 */
const { test, expect } = require('@playwright/test');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';
const PHONE = { width: 375, height: 812 };

const barState = (page) => page.locator('[data-pk-tabbar]').evaluate((bar) => ({
  visible: bar.classList.contains('is-visible'),
  inert: bar.hasAttribute('inert'),
  shown: getComputedStyle(bar).display !== 'none' && getComputedStyle(bar).visibility === 'visible',
  labels: [...bar.querySelectorAll('.pk-tabbar__label')].map((l) => (l.textContent || '').trim()),
  active: (bar.querySelector('.pk-tabbar__item.is-active .pk-tabbar__label')?.textContent || '').trim(),
}));

test.describe('Phone tab bar', () => {
  test('a visitor: hidden on load, shown after the first scroll, hidden at the footer', async ({ browser }) => {
    const page = await (await browser.newContext({ viewport: PHONE })).newPage();
    try {
      await page.goto(`${BASE}/catalogo`, { waitUntil: 'domcontentloaded' });
      let state = await barState(page);
      expect(state.visible).toBe(false);
      expect(state.inert, 'a hidden bar is out of the tab order').toBe(true);
      expect(state.labels).toEqual(['Home', 'Catalogo', 'Accedi']);
      expect(state.active).toBe('Catalogo');

      await page.evaluate(() => window.scrollTo(0, 300));
      await expect.poll(async () => (await barState(page)).visible).toBe(true);
      state = await barState(page);
      expect(state.inert).toBe(false);
      await expect.poll(async () => (await barState(page)).shown).toBe(true);

      await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
      await expect.poll(async () => (await barState(page)).visible, { message: 'the bar leaves when the footer is in view' }).toBe(false);
      await expect.poll(async () => (await barState(page)).shown).toBe(false);

      // Back up from the footer: it returns (the first scroll already happened).
      await page.evaluate(() => window.scrollTo(0, 200));
      await expect.poll(async () => (await barState(page)).visible).toBe(true);
    } finally {
      await page.context().close();
    }
  });

  test('a desktop width never shows it', async ({ browser }) => {
    const page = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
    try {
      await page.goto(`${BASE}/catalogo`, { waitUntil: 'domcontentloaded' });
      await page.evaluate(() => window.scrollTo(0, 400));
      await page.waitForTimeout(400);
      expect(await page.locator('[data-pk-tabbar]').evaluate((bar) => getComputedStyle(bar).display)).toBe('none');
    } finally {
      await page.context().close();
    }
  });

  test('a signed-in reader gets Loans and Favourites with the favourites badge', async ({ browser }) => {
    test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'admin credentials not set');
    const page = await (await browser.newContext({ viewport: PHONE })).newPage();
    try {
      await page.goto(`${BASE}/accedi`);
      await page.fill('input[name="email"]', ADMIN_EMAIL);
      await page.fill('input[name="password"]', ADMIN_PASS);
      await page.locator('button[type="submit"]').click();
      await page.waitForURL((u) => !u.pathname.includes('accedi'), { timeout: 30000 });
      await page.goto(`${BASE}/`, { waitUntil: 'domcontentloaded' });
      const state = await barState(page);
      const catalogueOnly = !state.labels.includes('Prestiti');
      test.skip(catalogueOnly, 'catalogue-only mode hides loans and favourites');
      expect(state.labels).toEqual(['Home', 'Catalogo', 'Prestiti', 'Preferiti', 'Admin']);
      expect(state.active).toBe('Home');
      const wished = await page.evaluate(() => ((window.PK && window.PK.wish) || []).length);
      const badge = page.locator('[data-pk-tabbar] [data-pk-wish-count]');
      if (wished > 0) {
        await expect(badge).toHaveText(wished > 99 ? '99+' : String(wished));
      } else {
        await expect(badge).toBeHidden();
      }
    } finally {
      await page.context().close();
    }
  });
});
