// @ts-check
/**
 * features.fines (Settings → Loans): fines and the patron ledger are opt-in.
 * Off, the Movimenti section of the reader record, the reader's own balance,
 * receipts and the fines report are gone; the ledger rows and the fine
 * amounts already saved are kept and show again with the module on.
 *
 * Run: /Users/fabio/Documents/GitHub/run-e2e-wt3.sh tests/feature-toggle-fines.spec.js --config=tests/playwright.config.js --workers=1
 */
const { test, expect } = require('@playwright/test');
const ft = require('./helpers/feature-toggles');

test.skip(!ft.configured, 'E2E credentials not configured');

const RUN = Date.now().toString(36);
const READER = `zz-ft-fines-${RUN}@test.local`;
const PASS = 'Test1234!';

test.describe.serial('Feature toggle: fines and patron ledger', () => {
  test.describe.configure({ timeout: 180000 });
  /** @type {import('@playwright/test').Page} */
  let page;
  let snapshot;
  let dailyFine = null;
  let readerId = 0;
  let movementId = 0;

  test.beforeAll(async ({ browser }) => {
    snapshot = ft.readFeatures(['fines']);
    const n = ft.dbQuery("SELECT COUNT(*) FROM system_settings WHERE category='loans' AND setting_key='daily_fine'");
    dailyFine = n === '1' ? ft.dbQuery("SELECT setting_value FROM system_settings WHERE category='loans' AND setting_key='daily_fine'") : null;
    ft.removeUsers('zz-ft-fines-%@test.local');
    readerId = ft.createUser(READER, PASS, 'standard');
    ft.dbQuery(`INSERT INTO movimenti_lettore (utente_id, tipo, importo, descrizione, created_at) VALUES (${readerId}, 'addebito_manuale', 3.00, 'ZZ FT fines', NOW())`);
    ft.dbQuery(`INSERT INTO movimenti_lettore (utente_id, tipo, importo, descrizione, metodo_pagamento, created_at) VALUES (${readerId}, 'pagamento', -1.00, 'ZZ FT paid', 'contanti', NOW())`);
    movementId = Number(ft.dbQuery(`SELECT MAX(id) FROM movimenti_lettore WHERE utente_id=${readerId}`));
    page = await browser.newPage();
    await ft.loginAdmin(page);
  });

  test.afterAll(async () => {
    await ft.restoreFeatures(snapshot);
    if (dailyFine === null) {
      ft.dbQuery("DELETE FROM system_settings WHERE category='loans' AND setting_key='daily_fine'");
    } else {
      ft.dbQuery(`UPDATE system_settings SET setting_value=${ft.sqlString(dailyFine)} WHERE category='loans' AND setting_key='daily_fine'`);
    }
    ft.dbQuery(`DELETE FROM movimenti_lettore WHERE utente_id=${readerId}`);
    ft.removeUsers('zz-ft-fines-%@test.local');
    await page?.close();
  });

  test('1. switched off: the reader record has no Movimenti section and receipts are refused', async () => {
    await ft.setModuleInUi(page, 'fines', false);
    await page.goto(`${ft.BASE}/admin/users/details/${readerId}`);
    await expect(page.locator('h1').first()).toBeVisible();
    await expect(page.locator('#movimenti')).toHaveCount(0);
    const res = await page.request.get(`${ft.BASE}/admin/users/${readerId}/ledger/receipt/${movementId}`, { maxRedirects: 0 });
    expect(res.status()).toBe(302);
    const csrf = await ft.csrfToken(page);
    const pay = await page.request.post(`${ft.BASE}/admin/users/${readerId}/ledger/payment`, { form: { csrf_token: csrf, importo: '1', metodo_pagamento: 'contanti', request_key: 'b'.repeat(32) }, maxRedirects: 0 });
    expect(pay.status()).toBe(302);
    expect(ft.dbQuery(`SELECT COUNT(*) FROM movimenti_lettore WHERE utente_id=${readerId}`)).toBe('2');
  });

  test('2. switched off: the statistics page has no fines report and the CSV is refused', async () => {
    await page.goto(`${ft.BASE}/admin/statistics`);
    await expect(page.locator('h1, h2').first()).toBeVisible();
    await expect(page.locator('#fines-report')).toHaveCount(0);
    await expect(page.locator('#stats-fines')).toHaveCount(0);
    const csv = await page.request.get(`${ft.BASE}/admin/statistics/fines.csv`, { maxRedirects: 0 });
    expect(csv.status()).toBe(302);
  });

  test('3. switched off: the reader does not see a balance on their loans page', async ({ browser }) => {
    const reader = await browser.newPage();
    try {
      await ft.loginAs(reader, READER, PASS);
      await reader.goto(`${ft.BASE}/prenotazioni`);
      await expect(reader.locator('body')).not.toContainText('Saldo e movimenti');
      await expect(reader.locator('[data-patron-balance]')).toHaveCount(0);
    } finally {
      await reader.close();
    }
  });

  test('4. switched off: the fine amounts stay editable and are saved with the tab', async () => {
    await page.goto(`${ft.BASE}/admin/settings?tab=loans`);
    await expect(page.locator('#daily_fine')).toBeEnabled();
    await page.fill('#daily_fine', '0,40');
    await page.locator('form[action$="/admin/settings/loans"] button[type="submit"]').click();
    await page.waitForURL(/tab=loans/);
    await expect(page.locator('#daily_fine')).toHaveValue(/0[.,]40/);
    await expect(page.locator('#feature_fines')).not.toBeChecked();
  });

  test('5. switched back on: Movimenti with the kept balance, receipt, report and the reader balance', async ({ browser }) => {
    await ft.setModuleInUi(page, 'fines', true);
    await page.goto(`${ft.BASE}/admin/users/details/${readerId}`);
    await expect(page.locator('#movimenti [data-ledger-balance]')).toHaveAttribute('data-ledger-balance', '200');
    const receipt = await page.request.get(`${ft.BASE}/admin/users/${readerId}/ledger/receipt/${movementId}`);
    expect(receipt.status()).toBe(200);
    await page.goto(`${ft.BASE}/admin/statistics`);
    await expect(page.locator('#fines-report')).toBeVisible();
    const reader = await browser.newPage();
    try {
      await ft.loginAs(reader, READER, PASS);
      await reader.goto(`${ft.BASE}/prenotazioni`);
      await expect(reader.locator('[data-patron-balance]')).toHaveAttribute('data-patron-balance', '200');
    } finally {
      await reader.close();
    }
  });
});
