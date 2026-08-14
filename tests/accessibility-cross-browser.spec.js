// @ts-check
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';

const publicRoutes = ['/', '/catalogo', '/accedi'];
const adminRoutes = ['/admin/dashboard', '/admin/books', '/admin/users', '/admin/settings'];

function formatViolations(violations) {
  return violations.map((violation) => {
    const targets = violation.nodes
      .flatMap((node) => node.target.map((target) => String(target)))
      .slice(0, 8)
      .join(', ');
    return `${violation.impact}: ${violation.id} — ${violation.help} [${targets}]`;
  }).join('\n');
}

async function assertHealthyAndAccessible(page, route) {
  const pageErrors = [];
  const onPageError = (error) => pageErrors.push(error.message);
  page.on('pageerror', onPageError);

  try {
    // DOMContentLoaded is the relevant readiness boundary for axe. Waiting for
    // networkidle is unsafe here because admin pages perform background polling.
    const response = await page.goto(`${BASE}${route}`, { waitUntil: 'domcontentloaded' });
    expect(response, `${route} did not return a response`).not.toBeNull();
    expect(response.status(), `${route} returned HTTP ${response.status()}`).toBeLessThan(400);
    await expect(page.locator('body')).toBeVisible();

    // Scan the stable visual state. WebKit can otherwise sample text midway
    // through a fade-in and report the transient blended color as a violation.
    await page.addStyleTag({
      content: '*, *::before, *::after { animation: none !important; transition: none !important; }',
    });
    await page.evaluate(() => new Promise((resolve) => requestAnimationFrame(() => resolve())));

    const results = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
      .analyze();
    const blocking = results.violations.filter(({ impact }) => impact === 'critical' || impact === 'serious');
    expect(blocking, `Accessibility violations on ${route}:\n${formatViolations(blocking)}`).toEqual([]);
    expect(pageErrors, `Unhandled browser errors on ${route}`).toEqual([]);
  } finally {
    page.off('pageerror', onPageError);
  }
}

test.describe('critical pages across supported browser engines', () => {
  for (const route of publicRoutes) {
    test(`public ${route} has no serious WCAG violations or runtime errors`, async ({ page }) => {
      await assertHealthyAndAccessible(page, route);
    });
  }

  test.describe('authenticated admin pages', () => {
    let adminContext;

    test.beforeAll(async ({ browser }) => {
      if (!ADMIN_EMAIL || !ADMIN_PASS) return;

      // Authenticate once per browser project. Repeating the login for every
      // route can exhaust the application rate limit after the package tests.
      adminContext = await browser.newContext();
      const loginPage = await adminContext.newPage();
      try {
        await loginPage.goto(`${BASE}/accedi`, { waitUntil: 'domcontentloaded' });
        await loginPage.locator('input[name="email"]').fill(ADMIN_EMAIL);
        await loginPage.locator('input[name="password"]').fill(ADMIN_PASS);
        await Promise.all([
          loginPage.waitForURL((url) => url.pathname.startsWith('/admin'), { timeout: 30_000 }),
          loginPage.locator('button[type="submit"]').click(),
        ]);
      } finally {
        await loginPage.close();
      }
    });

    test.afterAll(async () => {
      await adminContext?.close();
    });

    for (const route of adminRoutes) {
      test(`admin ${route} has no serious WCAG violations or runtime errors`, async () => {
        test.skip(!adminContext, 'admin credentials are required');

        // A new page avoids attributing late errors from the previous route to
        // the route currently under audit while retaining the authenticated session.
        const auditPage = await adminContext.newPage();
        try {
          await assertHealthyAndAccessible(auditPage, route);
        } finally {
          await auditPage.close();
        }
      });
    }
  });
});
