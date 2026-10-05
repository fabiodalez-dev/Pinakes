// @ts-check
/**
 * The Danish union catalogue (DBC, danMARC2) as an import source, set up the
 * way a librarian does it: Plugins → Z39.50/SRU → "+ Add from preset" → DBC,
 * save, and find the server again after the page reloads, with the protocol
 * and the ISBN index that make DBC answer. The row is removed at the end, so
 * the configuration is left as it was found.
 *
 * Reading the records themselves is covered offline, on saved DBC responses,
 * by tests/sru-roles-danmarc2.unit.php.
 *
 * Run: /tmp/run-e2e.sh tests/danmarc2-dbc-preset.spec.js --config=tests/playwright.config.js --workers=1
 */

const { test, expect } = require('@playwright/test');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';

test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'danmarc2-dbc-preset requires E2E_ADMIN_EMAIL and E2E_ADMIN_PASS');

const DBC_URL = 'https://opensearch.addi.dk/b3.5_5.2/?agency=100200&profile=test';

async function loginAsAdmin(page) {
  await page.goto(`${BASE}/admin`);
  if (page.url().includes('/admin') && !page.url().match(/login|accedi|anmelden/)) return;
  for (const slug of ['login', 'accedi', 'anmelden']) {
    const resp = await page.goto(`${BASE}/${slug}`).catch(() => null);
    if (resp && resp.status() === 200 && (await page.locator('input[name="email"]').count()) > 0) break;
  }
  await page.fill('input[name="email"]', ADMIN_EMAIL);
  await page.fill('input[name="password"]', ADMIN_PASS);
  await Promise.all([
    page.waitForURL(/admin/, { timeout: 15000 }),
    page.locator('button[type="submit"]').click(),
  ]);
}

async function openZ39Modal(page) {
  await page.goto(`${BASE}/admin/plugins`);
  await page.waitForLoadState('networkidle');
  const configBtn = page.locator('button[onclick*="openZ39ServerModal"]').first();
  await expect(configBtn).toBeVisible({ timeout: 10000 });
  await configBtn.click();
  await expect(page.locator('#z39ServerModal')).toBeVisible({ timeout: 8000 });
}

/** Submit the modal and wait for the success dialog and the reload it triggers. */
async function saveModal(page) {
  const saved = page.waitForResponse((r) => r.request().method() === 'POST' && r.status() === 200, { timeout: 15000 });
  await page.locator('#z39ServerForm button[type="submit"]').click();
  await saved;
  await page.locator('.swal2-confirm').click({ timeout: 10000 });
  await page.waitForLoadState('networkidle');
}

const dbcRow = (page) => page.locator('.z39-server-row').filter({
  has: page.locator(`input[name="server_url[]"][value="${DBC_URL}"]`),
});

test.describe.serial('DBC danMARC2 preset', () => {
  /** @type {import('@playwright/test').Page} */
  let page;

  test.beforeAll(async ({ browser }) => {
    page = await (await browser.newContext()).newPage();
    await loginAsAdmin(page);
  });
  test.afterAll(async () => { await page?.context().close(); });

  test('1 The syntax list offers danMARC2 over SRU and over DBC OpenSearch', async () => {
    await openZ39Modal(page);
    await page.locator('#z39PresetServers').selectOption('dbc');
    const row = dbcRow(page);
    await expect(row).toHaveCount(1);
    const values = await row.locator('select[name="server_syntax[]"] option').evaluateAll((opts) => opts.map((o) => o.value));
    expect(values).toEqual(expect.arrayContaining(['danmarc2', 'dbc-opensearch']));
  });

  test('2 The DBC preset fills the OpenSearch protocol and the term.isbn index', async () => {
    const row = dbcRow(page);
    await expect(row.locator('select[name="server_syntax[]"]')).toHaveValue('dbc-opensearch');
    await expect(row.locator('input[name="server_isbn_index[]"]')).toHaveValue('term.isbn');
    await expect(row.locator('input[name="server_name[]"]')).toHaveValue(/DBC/);
  });

  test('3 Saved, the server is there after the page reloads, unchanged', async () => {
    await saveModal(page);
    await openZ39Modal(page);
    const row = dbcRow(page);
    await expect(row).toHaveCount(1);
    await expect(row.locator('select[name="server_syntax[]"]')).toHaveValue('dbc-opensearch');
    await expect(row.locator('input[name="server_isbn_index[]"]')).toHaveValue('term.isbn');
  });

  test('4 Removed and saved, it is gone: the configuration is as it was', async () => {
    await dbcRow(page).locator('button[onclick*="remove"]').click();
    await expect(dbcRow(page)).toHaveCount(0);
    await saveModal(page);
    await openZ39Modal(page);
    await expect(dbcRow(page)).toHaveCount(0);
  });
});
