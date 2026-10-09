// @ts-check
/**
 * On a phone, a visitor who has not answered the cookie banner yet opens the
 * catalogue from the tab bar with ONE tap. The banner sat at bottom: 16px over
 * the bar: the first tap landed on the banner and only the second reached the
 * tab. Signed-in readers had answered it long before, so only visitors saw it.
 *
 * The administrator switches the banner on from Settings → Privacy (and back
 * as it was afterwards); the visitor is a fresh phone with no consent stored.
 *
 * Run: /tmp/run-e2e.sh tests/mobile-tabbar-cookie-banner.spec.js --config=tests/playwright.config.js --workers=1
 */
const { test, expect, devices } = require('@playwright/test');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';

test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'admin credentials required');

test.describe.serial('Tab bar with the cookie banner open', () => {
  /** @type {import('@playwright/test').Page} */
  let admin;
  let wasOn = null;

  async function setBanner(on) {
    await admin.goto(`${BASE}/admin/settings?tab=privacy`);
    const box = admin.locator('#cookie_banner_enabled');
    if ((await box.isChecked()) !== on) {
      await box.setChecked(on, { force: true });
      await Promise.all([
        admin.waitForLoadState('load'),
        admin.locator('form[action*="cookie-banner"] button[type="submit"]').first().click(),
      ]);
    }
  }

  test.beforeAll(async ({ browser }) => {
    admin = await browser.newPage();
    await admin.goto(`${BASE}/accedi`);
    await admin.fill('input[name="email"]', ADMIN_EMAIL);
    await admin.fill('input[name="password"]', ADMIN_PASS);
    await admin.locator('button[type="submit"]').click();
    await admin.waitForURL((u) => !u.pathname.includes('accedi'), { timeout: 30000 });
    await admin.goto(`${BASE}/admin/settings?tab=privacy`);
    wasOn = await admin.locator('#cookie_banner_enabled').isChecked();
    await setBanner(true);
  });

  test.afterAll(async () => {
    try { if (wasOn !== null) await setBanner(wasOn); } catch { /* best effort */ }
    await admin?.close();
  });

  for (const path of ['/', '/catalogo?page=2']) {
    test(`from ${path}, one tap on "Catalogo" opens the catalogue`, async ({ browser }) => {
      const ctx = await browser.newContext({ ...devices['Pixel 7'] });
      const phone = await ctx.newPage();
      try {
        await phone.goto(BASE + path, { waitUntil: 'networkidle' });
        await expect(phone.locator('#silktide-banner'), 'the banner waits for an answer').toBeVisible({ timeout: 10000 });
        // The reader scrolls; the bar comes up.
        await phone.evaluate(() => window.scrollTo(0, 400));
        const bar = phone.locator('[data-pk-tabbar].is-visible');
        test.skip(await phone.evaluate(() => document.documentElement.scrollHeight <= window.innerHeight + 30), 'page too short for the bar');
        await expect(bar).toBeVisible();
        const tab = bar.locator('.pk-tabbar__item').nth(1);
        await phone.waitForTimeout(400); // the bar's slide-in
        const b = await tab.boundingBox();
        expect(b).not.toBeNull();
        const from = phone.url();
        await phone.touchscreen.tap(b.x + b.width / 2, b.y + b.height / 2);
        await phone.waitForURL((u) => u.href !== from && /catalog/.test(u.pathname + u.search) && !/page=2/.test(u.search), { timeout: 5000 });
      } finally {
        await ctx.close();
      }
    });
  }
});
