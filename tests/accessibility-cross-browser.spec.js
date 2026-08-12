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

  const response = await page.goto(`${BASE}${route}`, { waitUntil: 'networkidle' });
  expect(response, `${route} did not return a response`).not.toBeNull();
  expect(response.status(), `${route} returned HTTP ${response.status()}`).toBeLessThan(400);
  await expect(page.locator('body')).toBeVisible();

  const results = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
    .analyze();
  const blocking = results.violations.filter(({ impact }) => impact === 'critical' || impact === 'serious');
  expect(blocking, `Accessibility violations on ${route}:\n${formatViolations(blocking)}`).toEqual([]);
  expect(pageErrors, `Unhandled browser errors on ${route}`).toEqual([]);

  page.off('pageerror', onPageError);
}

test.describe('critical pages across supported browser engines', () => {
  test('public pages have no serious WCAG violations or runtime errors', async ({ page }) => {
    for (const route of publicRoutes) {
      await test.step(route, () => assertHealthyAndAccessible(page, route));
    }
  });

  test('authenticated administration has no serious WCAG violations or runtime errors', async ({ page }) => {
    test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'admin credentials are required');

    await page.goto(`${BASE}/accedi`);
    await page.locator('input[name="email"]').fill(ADMIN_EMAIL);
    await page.locator('input[name="password"]').fill(ADMIN_PASS);
    await page.locator('button[type="submit"]').click();
    await page.waitForURL((url) => url.pathname.startsWith('/admin'), { timeout: 30_000 });

    for (const route of adminRoutes) {
      await test.step(route, () => assertHealthyAndAccessible(page, route));
    }
  });
});
