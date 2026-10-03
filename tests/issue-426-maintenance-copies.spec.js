// Issue #426: with its only copy under maintenance, a book published
// "Available Copies 0 / 0" — which reads as "this library does not have it".
// The whole chain is exercised as an operator and a reader see it: the copy
// state is changed through the real admin form (which is what recalculates
// libri.copie_totali), then the public page, the JSON availability endpoint
// and the request button are read back.
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || process.env.APP_URL || 'http://localhost:8081';
const marker = `Book426-${Date.now()}`;

function db(sql) {
  const args = ['-u', process.env.E2E_DB_USER, process.env.E2E_DB_NAME, '-N', '-B', '-e', sql];
  if (process.env.E2E_DB_SOCKET) args.unshift('-S', process.env.E2E_DB_SOCKET);
  return execFileSync('mysql', args, { encoding: 'utf8', env: { ...process.env, MYSQL_PWD: process.env.E2E_DB_PASS } }).trim();
}

async function login(page) {
  await page.goto(BASE + '/admin/dashboard');
  if (await page.locator('input[name=email]').isVisible()) {
    await page.locator('input[name=email]').fill(process.env.E2E_ADMIN_EMAIL);
    await page.locator('input[name=password]').fill(process.env.E2E_ADMIN_PASS);
    await page.locator('button[type=submit]').click();
    await page.waitForURL(u => !u.pathname.includes('accedi') && !u.pathname.includes('login'));
  }
}

let bookId; let copyId;

test.describe.serial('Issue 426 — a copy out of circulation is still owned', () => {
  test.beforeAll(() => {
    if (!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_DB_USER) throw new Error('Run with /tmp/run-e2e.sh');
    db(`INSERT INTO libri (titolo, copie_totali, copie_disponibili, stato) VALUES ('${marker}', 1, 1, 'disponibile')`);
    bookId = Number(db(`SELECT id FROM libri WHERE titolo='${marker}'`));
    db(`INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (${bookId}, '${marker}-C1', 'disponibile')`);
    copyId = Number(db(`SELECT id FROM copie WHERE libro_id=${bookId} ORDER BY id DESC LIMIT 1`));
  });

  test.afterAll(() => {
    try {
      db(`DELETE FROM copie WHERE libro_id=${bookId}`);
      db(`DELETE FROM libri WHERE id=${bookId}`);
    } catch (e) { console.error('Scoped cleanup failed:', e.message); }
  });

  test('maintenance keeps the copy in the published total and says why', async ({ page, browser }) => {
    await login(page);
    const anonymous = await browser.newContext();
    const reader = await anonymous.newPage();

    await reader.goto(BASE + `/libro/${bookId}`);
    await expect(reader.locator('.meta-value').filter({ hasText: '1 / 1' }).first()).toBeVisible();

    // The admin form is what recalculates the book: drive it, do not fake it.
    await page.goto(BASE + `/admin/books/${bookId}`);
    const csrf = await page.locator('[name=csrf_token]').first().inputValue();
    const applied = await page.request.post(BASE + `/admin/books/copies/${copyId}/update`, {
      form: { csrf_token: csrf, stato: 'manutenzione', note: 'issue 426' },
    });
    expect(applied.status(), 'copy state update').toBeLessThan(400);
    expect(db(`SELECT stato FROM copie WHERE id=${copyId}`)).toBe('manutenzione');
    // The column keeps its meaning: copies in circulation, which is now zero.
    expect(db(`SELECT copie_totali FROM libri WHERE id=${bookId}`)).toBe('0');

    await reader.goto(BASE + `/libro/${bookId}`);
    // What a reader must read: the library owns one copy, none available.
    await expect(reader.locator('.meta-value').filter({ hasText: '0 / 1' }).first()).toBeVisible();
    await expect(reader.locator('.meta-note').first()).toContainText('In manutenzione: 1');
    await expect(reader.locator('body')).not.toContainText('0 / 0');
    // The queue counts loanable copies and would refuse, so the button no
    // longer invites a request that cannot be honoured.
    const request = reader.locator('#btn-request-loan');
    await expect(request).toBeDisabled();
    await expect(request).toContainText('Momentaneamente non prenotabile');

    const api = await page.request.get(BASE + `/api/books/${bookId}/availability`);
    const payload = await api.json();
    expect(payload.copies_owned, 'the API publishes the owned total').toBe(1);
    expect(payload.copies_total, 'copies_total keeps meaning lending capacity').toBe(0);
    expect(payload.copies_out_of_circulation).toBe(1);

    // Back in circulation: everything returns to the plain counter.
    const back = await page.request.post(BASE + `/admin/books/copies/${copyId}/update`, {
      form: { csrf_token: csrf, stato: 'disponibile', note: '' },
    });
    expect(back.status()).toBeLessThan(400);
    await reader.goto(BASE + `/libro/${bookId}`);
    await expect(reader.locator('.meta-value').filter({ hasText: '1 / 1' }).first()).toBeVisible();
    await expect(reader.locator('.meta-note')).toHaveCount(0);
    await expect(reader.locator('#btn-request-loan')).toBeEnabled();
    await anonymous.close();
  });
});
