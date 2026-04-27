// Repro spec for issue #112: language stays Italian after English install.
// Drives the installer wizard end-to-end with locale=en_US, then logs in
// fresh in a clean browser context and inspects the rendered locale (URL
// translation + visible UI strings).

const { test, expect } = require('@playwright/test');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8082';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || 'admin@pinakes.test';
const ADMIN_PASS  = process.env.E2E_ADMIN_PASS  || 'Pinakes!Testing2026';
const DB_HOST = process.env.E2E_DB_HOST   || 'localhost';
const DB_USER = process.env.E2E_DB_USER   || '';
const DB_PASS = process.env.E2E_DB_PASS   || '';
const DB_NAME = process.env.E2E_DB_NAME   || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';

let appReady = false;

test.describe.serial('Issue #112 — install English, expect English on first login', () => {
  let context;
  let page;

  test.beforeAll(async ({ browser }) => {
    context = await browser.newContext();
    page = await context.newPage();
  });
  test.afterAll(async () => { await context?.close(); });

  test('Installer step 0: select English', async () => {
    await page.goto(`${BASE}/installer/?step=0`);
    await page.locator('input[name="language"][value="en_US"]').check();
    await page.locator('button[type="submit"]').click();
    await page.waitForURL(/step=1/);
  });

  test('Installer step 1: requirements pass', async () => {
    await expect(page.locator('li.not-met')).toHaveCount(0);
    await page.locator('button[type="submit"].btn-primary').click();
    await page.waitForURL(/step=2/);
  });

  test('Installer step 2: DB config', async () => {
    await page.fill('#db_host', DB_HOST);
    await page.fill('#db_username', DB_USER);
    await page.fill('#db_password', DB_PASS);
    await page.fill('#db_database', DB_NAME);
    if (DB_SOCKET) await page.fill('#db_socket', DB_SOCKET);
    await page.click('#test-connection-btn');
    await page.waitForFunction(
      () => {
        const el = document.getElementById('connection-result');
        return el && el.style.display !== 'none' && el.textContent.trim().length > 0;
      },
      { timeout: 15000 },
    );
    const cls = await page.locator('#connection-result').getAttribute('class');
    expect(cls).toContain('alert-success');
    await page.click('#continue-btn');
    await page.waitForURL(/step=[34]/, { timeout: 30000 });
  });

  test('Installer step 3: schema import', async () => {
    if (page.url().includes('step=4')) return;
    await page.waitForURL(/step=4/, { timeout: 60000 });
  });

  test('Installer step 4: admin user', async () => {
    await page.fill('input[name="nome"]', 'Fabio');
    await page.fill('input[name="cognome"]', 'Dalez');
    await page.fill('input[name="email"]', ADMIN_EMAIL);
    await page.fill('#password', ADMIN_PASS);
    await page.fill('#password_confirm', ADMIN_PASS);
    await page.locator('button[type="submit"].btn-primary').click();
    await page.waitForURL(/step=5/, { timeout: 15000 });
  });

  test('Installer step 5: app name', async () => {
    await page.fill('input[name="app_name"]', 'Pinakes');
    await page.locator('button[type="submit"].btn-primary').click();
    await page.waitForURL(/step=6/, { timeout: 15000 });
  });

  test('Installer step 6: email config', async () => {
    await page.selectOption('#email_driver', 'mail');
    await page.fill('input[name="from_email"]', 'noreply@example.com');
    await page.fill('input[name="from_name"]', 'Pinakes');
    await page.locator('button[type="submit"].btn-primary').click();
    await page.waitForURL(/step=7/, { timeout: 30000 });
  });

  test('Installer step 7: completion', async () => {
    await expect(page.locator('.alert-success').first()).toBeVisible({ timeout: 30000 });
    await page.locator('a.btn-primary').click();
    await page.waitForURL(url => !url.toString().includes('installer'), { timeout: 15000 });
    appReady = true;
  });

  test('First login lands in English: URL + UI strings', async () => {
    test.skip(!appReady, 'Install did not complete');
    // Fresh browser context to simulate "first login after install"
    const fresh = await page.context().browser().newContext();
    const p2 = await fresh.newPage();

    // Visit root: app should redirect to /login (English) NOT /accedi (Italian)
    const resp = await p2.goto(BASE + '/');
    const landingUrl = p2.url();
    console.log('[diag #112] root redirected to:', landingUrl);

    // Try the English login route directly
    const loginResp = await p2.goto(BASE + '/login');
    const loginStatus = loginResp ? loginResp.status() : null;
    console.log('[diag #112] /login status:', loginStatus);

    // Grab visible login form labels (the giveaway: "Email" vs "Indirizzo email", "Login" vs "Accedi")
    const html = await p2.content();
    const looksItalian = /Accedi|Indirizzo email|Password dimenticata/i.test(html);
    const looksEnglish = /\bSign in\b|\bLog in\b|Forgot.*password/i.test(html);
    console.log('[diag #112] looksItalian:', looksItalian, 'looksEnglish:', looksEnglish);

    // Login as admin
    await p2.fill('input[name="email"]', ADMIN_EMAIL);
    await p2.fill('input[name="password"]', ADMIN_PASS);
    await p2.locator('button[type="submit"]').click();
    await p2.waitForURL(/admin/, { timeout: 15000 });
    const dashboardUrl = p2.url();
    console.log('[diag #112] dashboard URL:', dashboardUrl);

    // Sample dashboard for the locale-revealing strings
    const dashHtml = await p2.content();
    const dashItalian = /Dashboard|Panoramica|Libri/i.test(dashHtml) && /Accedi|Esci|Profilo/i.test(dashHtml);
    const dashEnglish = /Dashboard/i.test(dashHtml) && /\bLogout\b|\bSign out\b|\bProfile\b/i.test(dashHtml);
    console.log('[diag #112] dashboard Italian markers:', dashItalian, 'English markers:', dashEnglish);

    await fresh.close();

    // The bug: if landing URL is /accedi instead of /login, or dashboard
    // shows Italian UI strings, the locale reverted.
    expect(landingUrl).not.toMatch(/\/accedi/);
    expect(loginStatus).toBe(200);
    expect(looksEnglish || !looksItalian).toBeTruthy();
  });
});
