// @ts-check
// Regression for the Mobile API docs page (`GET /api/v1/docs`). It must be
// self-hosted: Swagger UI is served from public/assets/swagger-ui/ and NEVER
// from a CDN, so it renders behind an egress firewall. Guards against the bug
// where only `.gitkeep` shipped and the page fell back to jsDelivr → blank.
const { test, expect } = require('@playwright/test');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';

// Public endpoints (no bearer token needed) but they only exist when the
// bundled Mobile API plugin is active. Probe once and skip cleanly otherwise.
let apiAvailable = true;

test.describe.serial('Mobile API — Swagger UI docs (self-hosted, no CDN)', () => {
  test.beforeAll(async ({ request }) => {
    try {
      const res = await request.get(`${BASE}/api/v1/openapi.json`);
      apiAvailable = res.status() === 200;
    } catch {
      apiAvailable = false;
    }
  });

  test.beforeEach(() => {
    test.skip(!apiAvailable, 'Mobile API plugin not active / app not installed');
  });

  test('openapi.json is a valid OpenAPI 3 spec with paths', async ({ request }) => {
    const res = await request.get(`${BASE}/api/v1/openapi.json`);
    expect(res.status()).toBe(200);
    expect(res.headers()['content-type']).toContain('json');
    const spec = await res.json();
    expect(String(spec.openapi)).toMatch(/^3\./);
    expect(Object.keys(spec.paths || {}).length).toBeGreaterThan(0);
  });

  test('docs page references LOCAL assets and never a CDN', async ({ request }) => {
    const res = await request.get(`${BASE}/api/v1/docs`);
    expect(res.status()).toBe(200);
    const html = await res.text();
    // Loads the vendored bundle + css from the app's own asset path
    expect(html).toContain('/assets/swagger-ui/swagger-ui-bundle.js');
    expect(html).toContain('/assets/swagger-ui/swagger-ui.css');
    // No external CDN host anywhere in the page
    expect(html).not.toMatch(/cdn\.jsdelivr\.net|cdnjs\.cloudflare\.com|unpkg\.com/);
  });

  test('the local Swagger UI assets are actually served', async ({ request }) => {
    for (const f of ['swagger-ui-bundle.js', 'swagger-ui.css']) {
      const res = await request.get(`${BASE}/assets/swagger-ui/${f}`);
      expect(res.status(), f).toBe(200);
      const body = await res.body();
      expect(body.length, `${f} should be a real file, not empty`).toBeGreaterThan(1000);
    }
  });

  test('Swagger UI renders the API operations from the local bundle', async ({ page }) => {
    await page.goto(`${BASE}/api/v1/docs`);
    // The bundle must load and SwaggerUIBundle must be defined (proves no broken CDN)
    await page.waitForFunction(() => typeof window.SwaggerUIBundle !== 'undefined', null, { timeout: 15000 });
    // The spec must render at least one operation block
    await page.waitForSelector('#swagger-ui .opblock', { timeout: 15000 });
    const ops = await page.locator('#swagger-ui .opblock').count();
    expect(ops).toBeGreaterThan(0);
  });
});
