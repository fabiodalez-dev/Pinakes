// @ts-check
/**
 * #450: a proxy in front of the site (here, a NAS's remote access) gave up on
 * the install request with a 502 after a minute, while PHP carried the update
 * to its end. The page reported an error for an update that had succeeded.
 *
 * The page now reads the outcome from /admin/updates/status when the install
 * request comes back as a gateway error or a dropped connection. It sends an
 * attempt identifier with the install request and only trusts what the server
 * filed under it (`attempt: {log, outcome}`). The proxy is simulated by
 * answering the browser's install request ourselves; the status replies play
 * the server finishing (or failing) behind it. No update runs.
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
 * @param {'502'|'502-empty-json'|'reset'} how
 */
async function routeInstall(page, how) {
  sentAttempt = '';
  await page.route('**/admin/updates/install-manual', route => {
    sentAttempt = new URLSearchParams(route.request().postData() || '').get('attempt') || '';
    if (how === 'reset') return route.abort('connectionreset');
    if (how === '502-empty-json') return route.fulfill({ status: 502, contentType: 'application/json', body: '' });
    return route.fulfill(PROXY_502);
  });
}

/**
 * A status reply, with what the server filed under the page's attempt.
 * @param {{version?: string, running?: boolean, last?: object|null, outcome?: object|null, mine?: object|null}} o
 */
function status(o) {
  return (/** @type {string} */ attempt) => ({
    success: true,
    version: o.version || '0.7.93',
    running: !!o.running,
    last: o.last === undefined ? null : o.last,
    outcome: o.outcome === undefined ? null : o.outcome,
    attempt: o.mine ? { log: null, outcome: null, ...o.mine } : null,
    _for: attempt,
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
  await page.route(url => url.pathname.endsWith('/admin/updates/status'), route => {
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
    const res = await page.request.get(`${BASE}/admin/updates/status?attempt=${'c'.repeat(32)}`);
    expect(res.status()).toBe(200);
    expect(res.headers()['cache-control']).toContain('no-store');
    const data = await res.json();
    const installed = JSON.parse(fs.readFileSync(path.join(__dirname, '..', 'version.json'), 'utf8')).version;
    expect(data.success).toBe(true);
    expect(data.version).toBe(installed);
    expect(data.running).toBe(false);
    expect(data.attempt, 'an attempt the server never saw has nothing filed').toBeNull();
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
      status({ running: true, mine: { log: { id: 41, to_version: TARGET, status: 'started', error: '' } } }),
      status({ version: TARGET, mine: { log: { id: 41, to_version: TARGET, status: 'completed', error: '' } } }),
    ]);
    await startUpdate(page);
    await expect(page.locator('#updateMessage'), 'the page says it is waiting, not that it failed').toContainText('continua sul server', { timeout: 10_000 });
    await expect(page.locator('#updateTitle')).toHaveText('Aggiornamento completato!', { timeout: 30_000 });
    await expect(page.locator('[data-step="migrate"]')).not.toContainText('Errore');
    expect(sentAttempt, 'the install request carried an identifier').toMatch(/^[a-f0-9]{32}$/);
  });

  test('3 A 502 from the proxy, then the update fails on the server: the page shows its error', async ({ page }) => {
    await login(page);
    await page.goto(`${BASE}/admin/updates`);
    await routeDownload(page);
    await routeInstall(page, '502');
    await routeStatus(page, [
      status({ mine: { log: { id: 51, to_version: TARGET, status: 'failed', error: 'Errore nella copia del file: probe450' } } }),
    ]);
    await startUpdate(page);
    await expect(page.locator('#updateTitle')).toHaveText('Aggiornamento fallito', { timeout: 30_000 });
    await expect(page.locator('#updateMessage')).toContainText('probe450');
  });

  test('4 A dropped connection: the run\'s own outcome decides', async ({ page }) => {
    await login(page);
    await page.goto(`${BASE}/admin/updates`);
    await routeDownload(page);
    await routeInstall(page, 'reset');
    await routeStatus(page, [
      status({ running: true }),
      status({ version: TARGET, mine: { outcome: { success: true, error: '', version: TARGET } } }),
    ]);
    await startUpdate(page);
    await expect(page.locator('#updateTitle')).toHaveText('Aggiornamento completato!', { timeout: 30_000 });
  });

  test('6 A failure before the install step, which logs no attempt, still shows its own error', async ({ page }) => {
    await login(page);
    await page.goto(`${BASE}/admin/updates`);
    await routeDownload(page);
    await routeInstall(page, '502');
    // The run's outcome is the only trace: no update_logs row of its own.
    await routeStatus(page, [
      status({ mine: { log: null, outcome: { success: false, error: 'Spazio insufficiente probe450', version: '0.7.93' } } }),
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
      status({ mine: { log: { id: 81, to_version: TARGET, status: 'started', error: '' }, outcome: null } }),
    ]);
    await startUpdate(page);
    await expect(page.locator('#updateTitle')).toHaveText('Aggiornamento fallito', { timeout: 30_000 });
    await expect(page.locator('#updateMessage')).toContainText('interrotto sul server');
  });

  test('8 Another administrator\'s run, newer and finished, is not taken for this one', async ({ page }) => {
    await login(page);
    await page.goto(`${BASE}/admin/updates`);
    await routeDownload(page);
    await routeInstall(page, '502');
    const foreignLog = { id: 91, to_version: TARGET, status: 'completed', error: '' };
    const foreignOutcome = { at: 3000, attempt: 'b'.repeat(32), success: false, error: 'not mine probe450', version: '0.7.93' };
    await routeStatus(page, [
      // The other run ended first, with the lock free: its log row and its
      // outcome are the latest, and neither is filed under this attempt.
      status({ last: foreignLog, outcome: foreignOutcome }),
      status({ last: foreignLog, outcome: foreignOutcome, running: true }),
      status({ last: foreignLog, outcome: foreignOutcome, mine: { outcome: { success: false, error: 'mine probe450', version: '0.7.93' } } }),
    ]);
    await startUpdate(page);
    await expect(page.locator('#updateTitle')).toHaveText('Aggiornamento fallito', { timeout: 30_000 });
    await expect(page.locator('#updateMessage')).toContainText('mine probe450');
    await expect(page.locator('#updateMessage')).not.toContainText('not mine');
  });

  test('9 A status that cannot be read at first does not hide this run\'s success', async ({ page }) => {
    await login(page);
    await page.goto(`${BASE}/admin/updates`);
    await routeDownload(page);
    await routeInstall(page, '502');
    // The version never reaches the target: only the outcome can tell.
    await routeStatus(page, [
      null,
      status({ mine: { outcome: { success: true, error: '', version: '0.7.93' } } }),
    ]);
    await startUpdate(page);
    await expect(page.locator('#updateTitle')).toHaveText('Aggiornamento completato!', { timeout: 30_000 });
  });

  test('10 A gateway error with an empty JSON body is waited out, not reported', async ({ page }) => {
    await login(page);
    await page.goto(`${BASE}/admin/updates`);
    await routeDownload(page);
    await routeInstall(page, '502-empty-json');
    await routeStatus(page, [
      status({ version: TARGET, mine: { outcome: { success: true, error: '', version: TARGET } } }),
    ]);
    await startUpdate(page);
    await expect(page.locator('#updateTitle')).toHaveText('Aggiornamento completato!', { timeout: 30_000 });
  });

  test('5 Nothing filed under this attempt: the page says the outcome is to check, never a success', async ({ page }) => {
    await login(page);
    await page.goto(`${BASE}/admin/updates`);
    await routeDownload(page);
    await routeInstall(page, '502');
    // The installed version even reached the target, and the latest log row
    // completed: neither is this run's, so neither may pass for its success.
    await routeStatus(page, [
      status({ version: TARGET, last: { id: 60, to_version: TARGET, status: 'completed', error: '' } }),
    ]);
    await startUpdate(page);
    await expect(page.locator('#updateTitle')).toHaveText('Esito da verificare', { timeout: 40_000 });
    await expect(page.locator('#updateMessage')).toContainText(TARGET);
  });
});
