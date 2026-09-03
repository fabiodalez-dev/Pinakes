// @ts-check
const { test, expect } = require('@playwright/test');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';

const CSP_ERROR = /content security policy|violates the following.*-src|applying inline style/i;

async function login(page) {
  await page.goto(`${BASE}/accedi`);
  await page.fill('input[name="email"]', ADMIN_EMAIL);
  await page.fill('input[name="password"]', ADMIN_PASS);
  await page.locator('button[type="submit"]').click();
  await page.waitForFunction(
    () => !location.pathname.includes('accedi') && !location.pathname.includes('login'),
    { timeout: 15000 },
  );
}

function collectCspErrors(page) {
  const errors = [];
  page.on('console', (message) => {
    if (message.type() === 'error' && CSP_ERROR.test(message.text())) {
      errors.push(message.text());
    }
  });
  return errors;
}

// The emeroteca surfaces below only exist when the bundled plugin is active.
// CI shards start from a fresh install (plugin registered but inactive), so
// activate it through the real admin UI first — idempotent, same flow as
// emeroteca.spec.js. Gate on the public route answering 200, not on UI state.
async function ensureEmerotecaActive(browser) {
  const page = await browser.newPage();
  try {
    if ((await page.request.get(`${BASE}/emeroteca`)).status() === 200) {
      return;
    }
    await login(page);
    for (let attempt = 0; attempt < 3; attempt++) {
      await page.goto(`${BASE}/admin/plugins`);
      await page.waitForLoadState('domcontentloaded');
      const card = page.locator('[data-plugin-id]', { hasText: 'Emeroteca' }).first();
      const activateBtn = card.locator('button:has-text("Attiva")');
      if (await activateBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
        await activateBtn.click();
        const confirmBtn = page.locator('.swal2-confirm:visible');
        if (await confirmBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
          await confirmBtn.click();
        }
        await page.waitForTimeout(1500);
      }
      if ((await page.request.get(`${BASE}/emeroteca`)).status() === 200) {
        return;
      }
    }
    throw new Error('emeroteca plugin could not be activated: /emeroteca still not 200');
  } finally {
    await page.close();
  }
}

test('CSP keeps active content strict and permits configured HTTPS media', async ({ request }) => {
  const response = await request.get(`${BASE}/`);
  expect(response.status()).toBe(200);
  const csp = response.headers()['content-security-policy'] || '';

  expect(csp).toContain("script-src 'self' 'nonce-");
  expect(csp).toContain("style-src 'self' 'nonce-");
  expect(csp).not.toMatch(/script-src[^;]*\shttps:\s*(?:;|$)/);
  expect(csp).not.toMatch(/style-src[^;]*\shttps:\s*(?:;|$)/);
  expect(csp).toContain("img-src 'self' data: blob: https:");
  expect(csp).toContain("media-src 'self' blob: https:");
  expect(csp).toContain("frame-src 'self' data: blob: about: https:");
  expect(csp).toContain("connect-src 'self' data: blob: https://www.google.com");
});

test('public shell and Emeroteca render without CSP violations', async ({ page, browser }) => {
  test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'E2E admin credentials not configured (needed to activate emeroteca)');
  test.setTimeout(120000);
  await ensureEmerotecaActive(browser);
  const violations = collectCspErrors(page);
  for (const path of ['/', '/catalogo', '/emeroteca']) {
    const response = await page.goto(`${BASE}${path}`, { waitUntil: 'domcontentloaded' });
    expect(response?.status(), path).toBe(200);
    await page.waitForTimeout(400);
    expect(await page.locator('style:not([nonce])').count(), path).toBe(0);
  }
  expect(violations).toEqual([]);
});

test.describe('admin CSP surfaces', () => {
  test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'E2E admin credentials not configured');

  test('Uppy and periodical forms render without CSP violations', async ({ page, browser }) => {
    test.setTimeout(120000);
    await ensureEmerotecaActive(browser);
    await login(page);
    const violations = collectCspErrors(page);
    for (const path of [
      '/admin/books',
      '/admin/authors/create',
      '/admin/cms/home',
      '/admin/periodicals',
      '/admin/periodicals/create',
      '/prenotazioni',
    ]) {
      const response = await page.goto(`${BASE}${path}`, { waitUntil: 'domcontentloaded' });
      expect(response?.status(), path).toBe(200);
      await page.waitForTimeout(700);
      expect(await page.locator('style:not([nonce])').count(), path).toBe(0);
    }
    expect(violations).toEqual([]);
  });

  test('every TinyMCE surface uses external content CSS under CSP', async ({ page }) => {
    await login(page);
    const violations = collectCspErrors(page);
    const cases = [
      ['/admin/settings?tab=templates', 'textarea.tinymce-editor'],
      ['/admin/books/create', '#descrizione'],
      ['/admin/cms/events/create', '#event_content'],
      ['/admin/cms/home', '#text_content_body'],
    ];

    for (const [path, selector] of cases) {
      const response = await page.goto(`${BASE}${path}`, { waitUntil: 'domcontentloaded' });
      expect(response?.status(), path).toBe(200);
      await page.waitForFunction((target) => {
        const textarea = document.querySelector(target);
        return Boolean(textarea?.id && window.tinymce?.get(textarea.id)?.initialized);
      }, selector);
      expect(await page.locator('style:not([nonce])').count(), path).toBe(0);
    }
    expect(violations).toEqual([]);
  });
});
