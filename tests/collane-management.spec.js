// @ts-check
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS  = process.env.E2E_ADMIN_PASS  || '';
const DB_USER   = process.env.E2E_DB_USER   || '';
const DB_PASS   = process.env.E2E_DB_PASS   || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';
const DB_NAME   = process.env.E2E_DB_NAME   || '';

test.skip(!ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_PASS || !DB_NAME, 'E2E credentials not configured');

function dbQuery(sql) {
  const args = ['-u', DB_USER, `-p${DB_PASS}`, DB_NAME, '-N', '-B', '-e', sql];
  if (DB_SOCKET) args.splice(3, 0, '-S', DB_SOCKET);
  return execFileSync('mysql', args, { encoding: 'utf-8', timeout: 10000 }).trim();
}

function dbExec(sql) {
  const args = ['-u', DB_USER, `-p${DB_PASS}`, DB_NAME, '-e', sql];
  if (DB_SOCKET) args.splice(3, 0, '-S', DB_SOCKET);
  execFileSync('mysql', args, { encoding: 'utf-8', timeout: 10000 });
}

async function loginAsAdmin(page) {
  await page.goto(`${BASE}/admin/dashboard`);
  const emailField = page.locator('input[name="email"]');
  if (await emailField.isVisible({ timeout: 3000 }).catch(() => false)) {
    await emailField.fill(ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await page.click('button[type="submit"]');
    await page.waitForURL(/admin/, { timeout: 15000 });
  }
}

test.describe.serial('Collane Management', () => {
  /** @type {import('@playwright/test').BrowserContext} */
  let context;
  /** @type {import('@playwright/test').Page} */
  let page;

  const TEST_COLLANA = 'E2E Test Series';
  const TEST_DESC = 'A test series for E2E testing';

  test.beforeAll(async ({ browser }) => {
    context = await browser.newContext();
    page = await context.newPage();
    await loginAsAdmin(page);
  });

  test.afterAll(async () => {
    // Cleanup
    try {
      dbExec(`DELETE FROM collane WHERE nome='${TEST_COLLANA}'`);
      dbExec(`UPDATE libri SET collana=NULL, numero_serie=NULL WHERE collana='${TEST_COLLANA}'`);
    } catch { /* ignore */ }
    await context?.close();
  });

  test('1. Collane page loads and shows series list', async () => {
    await page.goto(`${BASE}/admin/collane`);
    await page.waitForLoadState('networkidle');
    const html = await page.content();
    expect(html).toContain('Gestione Collane');
  });

  test('2. Create new collana via button', async () => {
    await page.goto(`${BASE}/admin/collane`);
    await page.waitForLoadState('networkidle');

    // Click "Nuova Collana" button
    await page.click('button:has-text("Nuova Collana")');

    // SweetAlert should appear
    const swalInput = page.locator('.swal2-input, #swal2-input');
    await swalInput.waitFor({ state: 'visible', timeout: 5000 });
    await swalInput.fill(TEST_COLLANA);

    // Confirm
    await page.click('.swal2-confirm');
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(1000);

    // May redirect to detail or reload list — either is OK

    // Verify in DB
    const exists = dbQuery(`SELECT COUNT(*) FROM collane WHERE nome='${TEST_COLLANA}'`);
    expect(parseInt(exists)).toBe(1);
  });

  test('3. Save collana description', async () => {
    await page.goto(`${BASE}/admin/collane/dettaglio?nome=${encodeURIComponent(TEST_COLLANA)}`);
    await page.waitForLoadState('networkidle');

    // Fill description textarea
    const textarea = page.locator('textarea[name="descrizione"]');
    await textarea.fill(TEST_DESC);

    // Click save
    await page.click('button:has-text("Salva descrizione")');
    await page.waitForLoadState('networkidle');

    // Verify in DB
    const desc = dbQuery(`SELECT descrizione FROM collane WHERE nome='${TEST_COLLANA}'`);
    expect(desc).toBe(TEST_DESC);
  });

  test('4. Collane detail page shows description', async () => {
    await page.goto(`${BASE}/admin/collane/dettaglio?nome=${encodeURIComponent(TEST_COLLANA)}`);
    await page.waitForLoadState('networkidle');

    const textarea = page.locator('textarea[name="descrizione"]');
    const value = await textarea.inputValue();
    expect(value).toBe(TEST_DESC);
  });

  test('5. Bulk assign collana via API', async () => {
    // Create a test book
    dbExec(`INSERT INTO libri (titolo, copie_totali, created_at, updated_at) VALUES ('E2E Collana Test Book', 1, NOW(), NOW())`);
    const bookId = dbQuery(`SELECT MAX(id) FROM libri WHERE titolo='E2E Collana Test Book' AND deleted_at IS NULL`);

    // Use the API directly (more reliable than UI checkbox interaction)
    const csrf = await page.evaluate(() => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '');
    const resp = await page.evaluate(async ({ base, bookId, collana, csrf }) => {
      const r = await fetch(base + '/admin/collane/bulk-assign', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify({ book_ids: [parseInt(bookId)], collana: collana })
      });
      return { status: r.status, body: await r.json() };
    }, { base: BASE, bookId, collana: TEST_COLLANA, csrf });

    expect(resp.body.success).toBe(true);

    // Verify in DB
    const collana = dbQuery(`SELECT collana FROM libri WHERE id=${bookId}`);
    expect(collana).toBe(TEST_COLLANA);

    // Cleanup
    dbExec(`DELETE FROM libri WHERE id=${bookId}`);
  });

  test('6. Delete collana removes from all books', async () => {
    // First assign the collana to a book
    dbExec(`INSERT INTO libri (titolo, collana, copie_totali, created_at, updated_at) VALUES ('E2E Delete Test', '${TEST_COLLANA}', 1, NOW(), NOW())`);

    await page.goto(`${BASE}/admin/collane/dettaglio?nome=${encodeURIComponent(TEST_COLLANA)}`);
    await page.waitForLoadState('networkidle');

    // Accept the confirm dialog
    page.on('dialog', dialog => dialog.accept());

    // Click delete button
    await page.click('button:has-text("Elimina collana")');
    await page.waitForLoadState('networkidle');

    // Should redirect to collane list
    expect(page.url()).toContain('/admin/collane');

    // Verify collana removed from books
    const count = dbQuery(`SELECT COUNT(*) FROM libri WHERE collana='${TEST_COLLANA}' AND deleted_at IS NULL`);
    expect(parseInt(count)).toBe(0);

    // Verify collane table cleaned
    const metaCount = dbQuery(`SELECT COUNT(*) FROM collane WHERE nome='${TEST_COLLANA}'`);
    expect(parseInt(metaCount)).toBe(0);

    // Cleanup
    dbExec(`DELETE FROM libri WHERE titolo='E2E Delete Test'`);
  });
});
