// @ts-check
/**
 * Issue #450: the automatic update failed on some hosting with "The server
 * returned an invalid response" and no way to tell why. From 0.7.92 it runs in
 * two requests (download, then the install-manual request a manual update
 * ends with), and an answer that is not JSON is shown with its HTTP status and
 * the start of its text.
 *
 * The full download-and-install against GitHub is exercised by
 * scripts/reinstall-test.sh --auto-update; here: the new endpoint's answers
 * and the error message the page builds, on the real admin page.
 */
const { test, expect } = require('@playwright/test');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';

test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'updater-two-step-450 requires E2E_ADMIN_EMAIL and E2E_ADMIN_PASS');

test.describe.serial('Automatic update in two requests (#450)', () => {
  /** @type {import('@playwright/test').Page} */
  let page;

  test.beforeAll(async ({ browser }) => {
    page = await (await browser.newContext()).newPage();
    await page.goto(`${BASE}/admin`);
    if (!page.url().includes('/admin') || /login|accedi|anmelden/.test(page.url())) {
      for (const slug of ['accedi', 'login', 'anmelden']) {
        const resp = await page.goto(`${BASE}/${slug}`).catch(() => null);
        if (resp && resp.status() === 200 && (await page.locator('input[name="email"]').count()) > 0) break;
      }
      await page.fill('input[name="email"]', ADMIN_EMAIL);
      await page.fill('input[name="password"]', ADMIN_PASS);
      await Promise.all([page.waitForURL(/admin/, { timeout: 15000 }), page.locator('button[type="submit"]').click()]);
    }
    await page.goto(`${BASE}/admin/updates`, { waitUntil: 'domcontentloaded' });
  });
  test.afterAll(async () => { await page?.context().close(); });

  test('1 A proxy timeout page reaches the operator as its HTTP status and text, not only "invalid response"', async () => {
    const message = await page.evaluate(async () => {
      const html = '<html><head><style>body{color:red}</style></head><body><h1>504 Gateway Time-out</h1><p>nginx</p></body></html>';
      try {
        // @ts-ignore readUpdateJson is the page's own helper
        await readUpdateJson(new Response(html, { status: 504, headers: { 'Content-Type': 'text/html' } }));
        return 'no error';
      } catch (e) {
        return String(e.message);
      }
    });
    expect(message).toContain('HTTP 504');
    expect(message).toContain('504 Gateway Time-out nginx');
    expect(message).not.toContain('<h1>');
    expect(message).not.toContain('color:red');
  });

  test('2 A JSON answer is read as before', async () => {
    const value = await page.evaluate(async () => {
      // @ts-ignore
      const data = await readUpdateJson(new Response('{"success":true}', { headers: { 'Content-Type': 'application/json' } }));
      return data.success;
    });
    expect(value).toBe(true);
  });

  test('3 The download endpoint answers JSON to the page (Accept: application/json): no token is refused, a malformed version is rejected', async () => {
    const noToken = await page.request.post(`${BASE}/admin/updates/download`, { form: { version: '9.9.9' }, headers: { Accept: 'application/json' } });
    expect(noToken.status()).toBe(403);
    expect(noToken.headers()['content-type'] || '').toContain('application/json');

    // @ts-ignore csrfToken is defined by the updates page
    const token = await page.evaluate(() => csrfToken);
    const badVersion = await page.request.post(`${BASE}/admin/updates/download`, { form: { csrf_token: token, version: '../../etc' }, headers: { Accept: 'application/json' } });
    expect(badVersion.status()).toBe(400);
    expect(await badVersion.json()).toHaveProperty('error');
  });

  test('4 The progress shows the real order: download first, then backup, files and migrations', async () => {
    const steps = await page.locator('#updateProgress .update-step').evaluateAll((els) => els.map((el) => el.getAttribute('data-step')));
    expect(steps).toEqual(['download', 'backup', 'install', 'migrate']);
  });
});
