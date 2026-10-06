// @ts-check
/**
 * #450: a proxy in front of the site (here, a NAS's remote access) gave up on
 * the install request with a 502 after a minute, while PHP carried the update
 * to its end. The page reported an error for an update that had succeeded.
 *
 * The page now reads the outcome from /admin/updates/status when the install
 * request comes back as a gateway error or a dropped connection. The proxy is
 * simulated by answering the browser's install request ourselves; the status
 * replies play the server finishing (or failing) behind it. No update runs.
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const TARGET = '9.9.9';

async function login(page) {
  await page.goto(`${BASE}/accedi`);
  await page.fill('input[name="email"]', process.env.E2E_ADMIN_EMAIL || '');
  await page.fill('input[name="password"]', process.env.E2E_ADMIN_PASS || '');
  await page.locator('button[type=submit]').click();
  await page.waitForURL(u => !u.pathname.includes('accedi') && !u.pathname.includes('login'));
}

/** The identifier the page sent with its install request, once it has. */
let sentAttempt = '';

/**
 * Play the proxy on the install request, keeping the attempt identifier the
 * page sent with it (the server would write it next to the outcome).
 * @param {import('@playwright/test').Page} page
 * @param {'502'|'reset'} how
 */
async function routeInstall(page, how) {
  sentAttempt = '';
  await page.route('**/admin/updates/install-manual', route => {
    sentAttempt = new URLSearchParams(route.request().postData() || '').get('attempt') || '';
    return how === 'reset' ? route.abort('connectionreset') : route.fulfill(PROXY_502);
  });
}

/**
 * Serve the status replies in order, the last one for every later poll. A
 * reply may be a function of the attempt identifier the page sent; `null`
 * answers 500, a status that cannot be read.
 * @param {import('@playwright/test').Page} page
 * @param {(object|null|((attempt: string) => object))[]} replies
 */
async function routeStatus(page, replies) {
  let call = 0;
  await page.route('**/admin/updates/status', route => {
    const reply = replies[Math.min(call, replies.length - 1)];
    call++;
    if (reply === null) {
      return route.fulfill({ status: 500, contentType: 'text/html', body: 'Internal Server Error' });
    }
    const body = typeof reply === 'function' ? reply(sentAttempt) : reply;
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(body) });
  });
}

/** The download step succeeds: the package is "on the server". */
async function routeDownload(page) {
  await page.route('**/admin/updates/download', route => route.fulfill({
    status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, package: 'proxy450' }),
  }));
}

/** @param {import('@playwright/test').Page} page */
async function startUpdate(page) {
  await page.evaluate(v => { window.startUpdate(v); }, TARGET);
  await page.locator('.swal2-confirm').click();
}

const PROXY_502 = {
  status: 502,
  contentType: 'text/html',
  body: '<html><body><h1>Proxy Error</h1><p>The proxy server received an invalid response from an upstream server. Reason: Error reading from remote server</p></body></html>',
};

test.describe.serial('Update survives a proxy that drops the install request (#450)', () => {
  test.beforeAll(() => {
    if (!process.env.E2E_ADMIN_EMAIL) throw new Error('Run with /tmp/run-e2e.sh');
  });

  test('1 The status endpoint answers with the installed version and no update running', async ({ page }) => {
    await login(page);
    const res = await page.request.get(`${BASE}/admin/updates/status`);
    expect(res.status()).toBe(200);
    expect(res.headers()['cache-control']).toContain('no-store');
    const data = await res.json();
    const installed = JSON.parse(fs.readFileSync(path.join(__dirname, '..', 'version.json'), 'utf8')).version;
    expect(data.success).toBe(true);
    expect(data.version).toBe(installed);
    expect(data.running).toBe(false);
    // A visitor gets nothing from it.
    const anonymous = await page.context().browser().newContext();
    try {
      const anon = await anonymous.request.get(`${BASE}/admin/updates/status`, { maxRedirects: 0 });
      expect(anon.status(), 'not for visitors').not.toBe(200);
    } finally {
      await anonymous.close();
    }
  });

  test('2 A 502 from the proxy, then the server finishes: the page reports success', async ({ page }) => {
    await login(page);
    await page.goto(`${BASE}/admin/updates`);
    await routeDownload(page);
    await routeInstall(page, '502');
    await routeStatus(page, [
      { success: true, version: '0.7.93', running: false, last: { id: 40, to_version: '0.7.93', status: 'completed', error: '' } },
      { success: true, version: '0.7.93', running: true, last: { id: 41, to_version: TARGET, status: 'started', error: '' } },
      { success: true, version: TARGET, running: false, last: { id: 41, to_version: TARGET, status: 'completed', error: '' } },
    ]);
    await startUpdate(page);
    await expect(page.locator('#updateMessage'), 'the page says it is waiting, not that it failed').toContainText('continua sul server', { timeout: 10_000 });
    await expect(page.locator('#updateTitle')).toHaveText('Aggiornamento completato!', { timeout: 30_000 });
    await expect(page.locator('[data-step="migrate"]')).not.toContainText('Errore');
  });

  test('3 A 502 from the proxy, then the update fails on the server: the page shows its error', async ({ page }) => {
    await login(page);
    await page.goto(`${BASE}/admin/updates`);
    await routeDownload(page);
    await routeInstall(page, '502');
    await routeStatus(page, [
      { success: true, version: '0.7.93', running: false, last: { id: 50, to_version: '0.7.93', status: 'completed', error: '' } },
      { success: true, version: '0.7.93', running: false, last: { id: 51, to_version: TARGET, status: 'failed', error: 'Errore nella copia del file: probe450' } },
    ]);
    await startUpdate(page);
    await expect(page.locator('#updateTitle')).toHaveText('Aggiornamento fallito', { timeout: 30_000 });
    await expect(page.locator('#updateMessage')).toContainText('probe450');
  });

  test('4 A dropped connection with no log entry: the installed version decides', async ({ page }) => {
    await login(page);
    await page.goto(`${BASE}/admin/updates`);
    await routeDownload(page);
    await routeInstall(page, 'reset');
    await routeStatus(page, [
      { success: true, version: '0.7.93', running: false, last: null },
      { success: true, version: TARGET, running: false, last: null },
    ]);
    await startUpdate(page);
    await expect(page.locator('#updateTitle')).toHaveText('Aggiornamento completato!', { timeout: 30_000 });
  });

  test('6 A failure before the install step, which logs no attempt, still shows its own error', async ({ page }) => {
    await login(page);
    await page.goto(`${BASE}/admin/updates`);
    await routeDownload(page);
    await routeInstall(page, '502');
    // The run's outcome file is the only trace: no new update_logs row.
    await routeStatus(page, [
      { success: true, version: '0.7.93', running: false, last: { id: 70, to_version: '0.7.93', status: 'completed', error: '' }, outcome: { at: 1000, attempt: 'a'.repeat(32), success: true, error: '', version: '0.7.93' } },
      attempt => ({ success: true, version: '0.7.93', running: false, last: { id: 70, to_version: '0.7.93', status: 'completed', error: '' }, outcome: { at: 2000, attempt, success: false, error: 'Spazio insufficiente probe450', version: '0.7.93' } }),
    ]);
    await startUpdate(page);
    await expect(page.locator('#updateTitle')).toHaveText('Aggiornamento fallito', { timeout: 30_000 });
    await expect(page.locator('#updateMessage')).toContainText('Spazio insufficiente probe450');
  });

  test('7 An attempt the server left half-way is reported as interrupted', async ({ page }) => {
    await login(page);
    await page.goto(`${BASE}/admin/updates`);
    await routeDownload(page);
    await routeInstall(page, '502');
    await routeStatus(page, [
      { success: true, version: '0.7.93', running: false, last: { id: 80, to_version: '0.7.93', status: 'completed', error: '' }, outcome: null },
      { success: true, version: '0.7.93', running: false, last: { id: 81, to_version: TARGET, status: 'started', error: '' }, outcome: null },
    ]);
    await startUpdate(page);
    await expect(page.locator('#updateTitle')).toHaveText('Aggiornamento fallito', { timeout: 30_000 });
    await expect(page.locator('#updateMessage')).toContainText('interrotto sul server');
  });

  test('5 An older completed update in the log is not taken for this one', async ({ page }) => {
    await login(page);
    await page.goto(`${BASE}/admin/updates`);
    await routeDownload(page);
    await routeInstall(page, '502');
    // Nothing new is ever logged and the version never moves.
    await routeStatus(page, [
      { success: true, version: '0.7.93', running: false, last: { id: 60, to_version: '0.7.93', status: 'completed', error: '' } },
    ]);
    await startUpdate(page);
    await expect(page.locator('#updateTitle')).toHaveText('Aggiornamento fallito', { timeout: 40_000 });
    await expect(page.locator('#updateMessage')).toContainText('non risulta completato');
  });

  test('8 Another administrator\'s outcome is not taken for this one, even if newer', async ({ page }) => {
    await login(page);
    await page.goto(`${BASE}/admin/updates`);
    await routeDownload(page);
    await routeInstall(page, '502');
    const other = 'b'.repeat(32);
    await routeStatus(page, [
      { success: true, version: '0.7.93', running: false, last: { id: 90, to_version: '0.7.93', status: 'completed', error: '' }, outcome: null },
      // A second run ended first, with an error that is not this page's, and
      // the lock is free: a timestamp check would take this outcome.
      { success: true, version: '0.7.93', running: false, last: { id: 90, to_version: '0.7.93', status: 'completed', error: '' }, outcome: { at: 3000, attempt: other, success: false, error: 'not mine probe450', version: '0.7.93' } },
      attempt => ({ success: true, version: '0.7.93', running: false, last: { id: 90, to_version: '0.7.93', status: 'completed', error: '' }, outcome: { at: 4000, attempt, success: true, error: '', version: '0.7.93' } }),
    ]);
    await startUpdate(page);
    await expect(page.locator('#updateTitle')).toHaveText('Aggiornamento completato!', { timeout: 30_000 });
    await expect(page.locator('#updateMessage')).not.toContainText('not mine');
    expect(sentAttempt, 'the install request carried an identifier').toMatch(/^[a-f0-9]{32}$/);
  });

  test('9 A status that cannot be read before the request does not hide this run\'s success', async ({ page }) => {
    await login(page);
    await page.goto(`${BASE}/admin/updates`);
    await routeDownload(page);
    await routeInstall(page, '502');
    // The version never reaches the target: only the outcome can tell.
    await routeStatus(page, [
      null,
      attempt => ({ success: true, version: '0.7.93', running: false, last: null, outcome: { at: 5000, attempt, success: true, error: '', version: '0.7.93' } }),
    ]);
    await startUpdate(page);
    await expect(page.locator('#updateTitle')).toHaveText('Aggiornamento completato!', { timeout: 30_000 });
  });
});
