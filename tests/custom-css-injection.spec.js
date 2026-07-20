// @ts-check
//
// End-to-end proof that the admin "Custom CSS" appearance setting cannot be
// used as a stored-XSS vector, AND that legitimate CSS still round-trips.
//
// The `advanced.custom_header_css` value is rendered verbatim inside an inline
// <style>…</style> block on every frontend page. Content inside <style> is HTML
// raw text: the only escape into script execution is the `</style` end tag.
// ContentSanitizer::sanitizeCustomCss() (applied at BOTH save and render) must
// neutralise that breakout. This spec drives the REAL admin form and inspects
// the REAL public page, so it also proves the save↔render wiring — not just the
// pure function (covered by tests/custom-css-injection.unit.php).
//
// Run: /tmp/run-e2e.sh tests/custom-css-injection.spec.js \
//        --config=tests/playwright.config.js --workers=1

const { test, expect } = require('@playwright/test');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';

test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'E2E admin credentials not configured');

async function loginAsAdmin(page) {
  await page.goto(`${BASE}/admin/dashboard`);
  const emailField = page.locator('input[name="email"]');
  if (await emailField.isVisible({ timeout: 3000 }).catch(() => false)) {
    await emailField.fill(ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await page.click('button[type="submit"]');
    await page.waitForURL(/.*(?:dashboard|admin).*/);
  }
}

/** Save a value into the Custom CSS field via the real advanced-settings form. */
async function setCustomCss(page, value) {
  await page.goto(`${BASE}/admin/settings?tab=advanced`);
  const css = page.locator('#custom_header_css');
  await css.waitFor({ state: 'visible', timeout: 10000 });
  await css.fill(value);
  // Submit the actual form that owns the textarea (posts to /admin/settings/advanced).
  await css.evaluate((el) => {
    const form = el.closest('form');
    if (form) form.requestSubmit();
  });
  await page.waitForLoadState('networkidle');
}

test.describe('Custom CSS injection hardening', () => {
  // Always restore the field to empty so the suite is idempotent.
  test.afterAll(async ({ browser }) => {
    const page = await browser.newPage();
    try {
      await loginAsAdmin(page);
      await setCustomCss(page, '');
    } finally {
      await page.close();
    }
  });

  test('a </style> breakout payload never executes on the frontend', async ({ page }) => {
    const marker = `XSSMARK_${Date.now()}`;
    const payload =
      `body{color:red}</style><script>window.__xss='${marker}';document.title='${marker}';</script>`;

    await loginAsAdmin(page);
    await setCustomCss(page, payload);

    // Capture any dialog the payload might raise.
    let dialogFired = false;
    page.on('dialog', (d) => { dialogFired = true; d.dismiss().catch(() => {}); });

    // Load a PUBLIC frontend page (fresh navigation parses the stored CSS).
    await page.goto(`${BASE}/`);
    await page.waitForLoadState('domcontentloaded');

    // 1. No JavaScript from the payload ran.
    const xssGlobal = await page.evaluate(() => /** @type {any} */ (window).__xss ?? null);
    expect(xssGlobal, 'payload JS must not execute').toBeNull();
    expect(dialogFired, 'no dialog may be raised').toBe(false);
    expect(await page.title(), 'document.title must be untouched by the payload')
      .not.toContain(marker);

    // 2. The parsed DOM must contain no <script> element carrying the marker
    //    (a successful breakout would have created a real script node).
    const injectedScripts = await page.evaluate((m) =>
      Array.from(document.querySelectorAll('script'))
        .filter((s) => (s.textContent || '').includes(m)).length, marker);
    expect(injectedScripts, 'no <script> element may carry the payload').toBe(0);

    // 3. The serialized HTML must not contain a live `</style><script` sequence.
    const html = (await page.content()).toLowerCase();
    expect(html).not.toContain('</style><script');
    expect(html).not.toContain('<script>window.__xss');
  });

  test('legitimate CSS still round-trips through save and render', async ({ page }) => {
    const legit = 'body{outline:3px solid rgb(7,8,9)}';

    await loginAsAdmin(page);
    await setCustomCss(page, legit);

    await page.goto(`${BASE}/`);
    await page.waitForLoadState('domcontentloaded');

    // The exact CSS must appear inside an inline <style> block (feature intact).
    const styleHasCss = await page.evaluate((needle) =>
      Array.from(document.querySelectorAll('style'))
        .some((s) => (s.textContent || '').includes(needle)), legit);
    expect(styleHasCss, 'valid CSS must survive the sanitiser and reach the page').toBe(true);
  });
});
