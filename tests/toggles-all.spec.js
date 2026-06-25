// @ts-check
// Regression for the toggle restyle (canonical gray→dark .toggle-checkbox switch,
// the auto-submit API toggle, the events visibility toggle, and the bulk-enrich
// switch). Turns each toggle ON and asserts it actually reflects the ON state —
// the shared .toggle-checkbox driver paints the track dark, forms auto-submit,
// and the AJAX switch flips. Restores every toggle so the run is idempotent.
const { test, expect } = require('@playwright/test');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';

const ON_BG = 'rgb(17, 24, 39)';   // #111827 — canonical "on" track colour
const OFF_BG = 'rgb(229, 231, 235)'; // #e5e7eb — canonical "off" track colour

test.skip(
  !ADMIN_EMAIL || !ADMIN_PASS,
  'E2E credentials not configured (set E2E_ADMIN_EMAIL, E2E_ADMIN_PASS)',
);

/**
 * Inline background-color the shared driver writes onto a canonical toggle's
 * track (input's next sibling). Read the inline style, not the computed one:
 * `transition-colors` animates the computed value over 300ms, so the computed
 * read lags; the inline value is set synchronously by the driver.
 */
async function trackBg(page, id) {
  return page.evaluate((tid) => {
    const input = document.getElementById(tid);
    if (!input || !input.nextElementSibling) return null;
    return input.nextElementSibling.style.backgroundColor;
  }, id);
}

/**
 * Flip a canonical sr-only .toggle-checkbox. The visible track/dot overlap and
 * intercept pointer events on a 1px sr-only input, so set `checked` and dispatch
 * `change` directly — the exact event a real click fires, which the shared
 * driver (and any onchange handler) listens for.
 */
async function setToggle(page, id, on) {
  const input = page.locator(`#${id}`);
  if ((await input.isChecked()) !== on) {
    await input.evaluate((el, val) => {
      el.checked = val;
      el.dispatchEvent(new Event('change', { bubbles: true }));
    }, on);
  }
  await expect(input).toBeChecked({ checked: on });
}

/** Flip a toggle whose onchange auto-submits a form; waits for the navigation. */
async function flipAndSubmit(page, id, on) {
  if ((await page.locator(`#${id}`).isChecked()) === on) return;
  await Promise.all([
    page.waitForNavigation({ timeout: 15000 }),
    page.locator(`#${id}`).evaluate((el, val) => {
      el.checked = val;
      el.dispatchEvent(new Event('change', { bubbles: true }));
    }, on),
  ]);
}

test.describe.serial('All toggles turn ON after the restyle', () => {
  /** @type {import('@playwright/test').BrowserContext} */
  let context;
  /** @type {import('@playwright/test').Page} */
  let page;

  test.beforeAll(async ({ browser }) => {
    context = await browser.newContext();
    page = await context.newPage();
    await page.goto(`${BASE}/accedi`);
    await page.fill('input[name="email"]', ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await page.locator('button[type="submit"]').click();
    await page.waitForURL(/admin/, { timeout: 15000 });
  });

  test.afterAll(async () => {
    await context.close();
  });

  // The shared .toggle-checkbox driver: turning a toggle on must paint the track
  // dark. Verified on every advanced-form toggle WITHOUT submitting (force_https
  // would redirect the HTTP test to HTTPS). State is reset before leaving.
  test('Advanced: every .toggle-checkbox reflects ON via the shared driver', async () => {
    await page.goto(`${BASE}/admin/settings?tab=advanced`);
    // force_https / private_mode are plain checkboxes, not switch toggles — excluded.
    const ids = ['llms_txt_enabled', 'catalogue_mode'];
    for (const id of ids) {
      const before = await page.locator(`#${id}`).isChecked();
      await setToggle(page, id, true);
      expect(await trackBg(page, id)).toBe(ON_BG);
      await setToggle(page, id, false);
      expect(await trackBg(page, id)).toBe(OFF_BG);
      // leave as found
      if (before) await setToggle(page, id, true);
    }
  });

  // The API toggle auto-submits its own form on change and the new state must
  // survive the reload.
  test('Advanced: API toggle auto-submits and persists ON', async () => {
    await page.goto(`${BASE}/admin/settings?tab=advanced`);
    const wasOn = await page.locator('#api_enabled').isChecked();

    await flipAndSubmit(page, 'api_enabled', true);
    await page.goto(`${BASE}/admin/settings?tab=advanced`);
    await expect(page.locator('#api_enabled')).toBeChecked();
    expect(await trackBg(page, 'api_enabled')).toBe(ON_BG);

    // Restore original state
    await flipAndSubmit(page, 'api_enabled', wasOn);
  });

  // Privacy toggles persist via the "Salva Privacy Policy" form submit.
  test('Privacy: cookie/analytics/marketing toggles persist ON', async () => {
    await page.goto(`${BASE}/admin/settings?tab=privacy`);
    const ids = ['cookie_banner_enabled', 'show_analytics', 'show_marketing'];
    const before = {};
    for (const id of ids) before[id] = await page.locator(`#${id}`).isChecked();

    for (const id of ids) await setToggle(page, id, true);
    await page.locator('button[type="submit"]:has-text("Salva Privacy")').first().click();
    await expect(page.locator('.bg-green-50, .swal2-icon-success').first())
      .toBeVisible({ timeout: 10000 });

    await page.goto(`${BASE}/admin/settings?tab=privacy`);
    for (const id of ids) await expect(page.locator(`#${id}`)).toBeChecked();

    // Restore original states
    for (const id of ids) await setToggle(page, id, before[id]);
    await page.locator('button[type="submit"]:has-text("Salva Privacy")').first().click();
    await expect(page.locator('.bg-green-50, .swal2-icon-success').first())
      .toBeVisible({ timeout: 10000 });
  });

  // Per-event visibility toggle (auto-submits). Skips when no event exists.
  test('Events: visibility toggle flips', async () => {
    await page.goto(`${BASE}/admin/cms/events`);
    const first = () => page.locator('input.toggle-checkbox').first();
    if ((await first().count()) === 0) {
      test.skip(true, 'No events to toggle');
      return;
    }
    const before = await first().isChecked();
    const flip = () => Promise.all([
      page.waitForNavigation({ timeout: 15000 }),
      first().evaluate((el) => {
        el.checked = !el.checked;
        el.dispatchEvent(new Event('change', { bubbles: true }));
      }),
    ]);
    await flip();
    await page.goto(`${BASE}/admin/cms/events`);
    expect(await first().isChecked()).toBe(!before);
    await flip(); // restore
  });

  // Bulk-enrich is a button[role=switch] flipped via AJAX. Skips when absent.
  test('Bulk-enrich: switch turns ON', async () => {
    await page.goto(`${BASE}/admin/books/bulk-enrich`);
    const sw = page.locator('#toggle-enrichment');
    if ((await sw.count()) === 0) {
      test.skip(true, 'No bulk-enrich toggle on this page');
      return;
    }
    const wasOn = (await sw.getAttribute('aria-checked')) === 'true';
    await sw.click();
    await expect(sw).toHaveAttribute('aria-checked', String(!wasOn), { timeout: 10000 });
    // Restore
    await sw.click();
    await expect(sw).toHaveAttribute('aria-checked', String(wasOn), { timeout: 10000 });
  });
});
