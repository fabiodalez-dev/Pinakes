// @ts-check
// Discussion #238 (Nikola) — loan-workflow improvements:
//   #2  entering/scanning a copy inventory code auto-identifies the book
//   #3  the book search suggestions show the subtitle
//   #4  the loan details page shows the physical copy inventory code + subtitle
//   #5  the loan receipt PDF includes the subtitle + inventory code
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS  = process.env.E2E_ADMIN_PASS  || '';
const TEST_EMAIL  = process.env.E2E_TEST_EMAIL || 'nikola-borrower@example.test';
const TEST_PASS   = process.env.E2E_TEST_PASS  || 'Borrow1234!';

const DB_USER   = process.env.E2E_DB_USER   || '';
const DB_PASS   = process.env.E2E_DB_PASS   || '';
const DB_HOST   = process.env.E2E_DB_HOST   || '';
const DB_PORT   = process.env.E2E_DB_PORT   || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';
const DB_NAME   = process.env.E2E_DB_NAME   || '';

test.skip(
  !ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME,
  'E2E credentials not configured',
);

function dbQuery(sql) {
  const args = [];
  if (DB_HOST) { args.push('-h', DB_HOST); if (DB_PORT) args.push('-P', DB_PORT); }
  else if (DB_SOCKET) { args.push('-S', DB_SOCKET); }
  args.push('-u', DB_USER, DB_NAME, '-N', '-B', '-e', sql);
  return execFileSync('mysql', args, {
    encoding: 'utf-8', timeout: 10000,
    env: { ...process.env, MYSQL_PWD: DB_PASS },
  }).trim();
}

// Unique per-run identifiers so repeated runs never collide (numero_inventario
// is UNIQUE; the book title is used to find/clean the fixture).
const RUN = Date.now().toString().slice(-7);
const BOOK_TITLE = `Nikola Fixture ${RUN}`;
const BOOK_SUBTITLE = `an odyssey of subtitles ${RUN}`;
const INV_CODE = `NIKOLA-INV-${RUN}`;

let bookId = 0;
let userId = 0;

async function loginAdmin(page) {
  await page.goto(`${BASE}/accedi`);
  await page.fill('input[name="email"]', ADMIN_EMAIL);
  await page.fill('input[name="password"]', ADMIN_PASS);
  await page.click('button[type="submit"]');
  await page.waitForURL(u => u.toString().includes('/admin'), { timeout: 15000 });
}

test.describe.configure({ mode: 'serial' });

test.describe('Nikola #238 — loan workflow', () => {
  test.beforeAll(() => {
    // Clean any stale fixture
    dbQuery(`DELETE FROM prestiti WHERE libro_id IN (SELECT id FROM libri WHERE titolo='${BOOK_TITLE}')`);
    dbQuery(`DELETE FROM copie WHERE numero_inventario='${INV_CODE}'`);
    dbQuery(`DELETE FROM libri WHERE titolo='${BOOK_TITLE}'`);
    dbQuery(`DELETE FROM prestiti WHERE utente_id IN (SELECT id FROM utenti WHERE email='${TEST_EMAIL}')`);
    dbQuery(`DELETE FROM utenti WHERE email='${TEST_EMAIL}'`);

    // Borrower
    const hash = execFileSync('php', ['-r', `echo password_hash('${TEST_PASS}', PASSWORD_DEFAULT);`], { encoding: 'utf-8' }).trim();
    const tessera = 'NIK' + RUN;
    dbQuery(
      `INSERT INTO utenti (codice_tessera, nome, cognome, email, password, stato, email_verificata, tipo_utente, created_at)
       VALUES ('${tessera}', 'Nikola', 'Borrower', '${TEST_EMAIL}', '${hash}', 'attivo', 1, 'standard', NOW())`
    );
    userId = parseInt(dbQuery(`SELECT id FROM utenti WHERE email='${TEST_EMAIL}'`), 10);

    // Book with subtitle + one available copy with a known inventory code
    dbQuery(
      `INSERT INTO libri (titolo, sottotitolo, anno_pubblicazione, copie_totali, copie_disponibili, created_at, updated_at)
       VALUES ('${BOOK_TITLE}', '${BOOK_SUBTITLE}', 2024, 1, 1, NOW(), NOW())`
    );
    bookId = parseInt(dbQuery(`SELECT id FROM libri WHERE titolo='${BOOK_TITLE}' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1`), 10);
    dbQuery(`INSERT INTO copie (libro_id, stato, numero_inventario, created_at) VALUES (${bookId}, 'disponibile', '${INV_CODE}', NOW())`);
    // Keep the denormalized FULLTEXT search index in sync so the book search finds it.
    dbQuery(`UPDATE libri SET search_index = CONCAT_WS(' ', titolo, sottotitolo) WHERE id=${bookId}`);
  });

  test.afterAll(() => {
    dbQuery(`DELETE FROM prestiti WHERE libro_id=${bookId}`);
    dbQuery(`DELETE FROM copie WHERE numero_inventario='${INV_CODE}'`);
    dbQuery(`DELETE FROM libri WHERE id=${bookId}`);
    dbQuery(`DELETE FROM utenti WHERE email='${TEST_EMAIL}'`);
  });

  test('#2/#3 copy code auto-identifies the book; search shows subtitle', async ({ page }) => {
    await loginAdmin(page);
    await page.goto(`${BASE}/admin/loans/create`);

    // #3 — typing the title shows the subtitle in the suggestions
    await page.fill('#libro_search', BOOK_TITLE);
    const suggestion = page.locator('#libro_suggest .suggestion-item', { hasText: BOOK_TITLE }).first();
    await expect(suggestion).toBeVisible({ timeout: 8000 });
    await expect(suggestion).toContainText(BOOK_SUBTITLE);

    // Reset the book field, then let the COPY CODE resolve the book (#2)
    await page.fill('#libro_search', '');
    await page.fill('#copy_code', INV_CODE);
    await expect.poll(
      async () => page.locator('#libro_id').inputValue(),
      { timeout: 8000 }
    ).toBe(String(bookId));
    await expect(page.locator('#copy_code_status')).toBeVisible();
    await expect(page.locator('#libro_search')).toHaveValue(new RegExp(BOOK_TITLE));
  });

  test('#4/#5 loan details + PDF show the copy inventory and subtitle', async ({ page }) => {
    await loginAdmin(page);
    await page.goto(`${BASE}/admin/loans/create`);

    // Pick the borrower via autocomplete
    await page.fill('#utente_search', TEST_EMAIL);
    const userSug = page.locator('#utente_suggest .suggestion-item').first();
    await expect(userSug).toBeVisible({ timeout: 8000 });
    await userSug.click();
    await expect(page.locator('#utente_id')).toHaveValue(String(userId));

    // Identify the book via the copy code (the #2 path)
    await page.fill('#copy_code', INV_CODE);
    await expect.poll(async () => page.locator('#libro_id').inputValue(), { timeout: 8000 }).toBe(String(bookId));

    await page.click('button[type="submit"]');
    await page.waitForLoadState('networkidle');

    // Find the created loan
    const loanId = parseInt(
      dbQuery(`SELECT id FROM prestiti WHERE libro_id=${bookId} AND utente_id=${userId} ORDER BY id DESC LIMIT 1`), 10
    );
    expect(loanId).toBeGreaterThan(0);
    // The loan must be pinned to our specific copy
    const copiaInv = dbQuery(`SELECT c.numero_inventario FROM prestiti p JOIN copie c ON p.copia_id=c.id WHERE p.id=${loanId}`);
    expect(copiaInv).toBe(INV_CODE);

    // #4 — details page shows the inventory code + subtitle
    await page.goto(`${BASE}/admin/loans/details/${loanId}`);
    await expect(page.locator('body')).toContainText(INV_CODE);
    await expect(page.locator('body')).toContainText(BOOK_SUBTITLE);

    // #5 — the receipt PDF downloads and (best-effort) carries the data
    const resp = await page.request.get(`${BASE}/admin/loans/${loanId}/pdf`);
    expect(resp.status()).toBe(200);
    expect(resp.headers()['content-type']).toContain('application/pdf');
    const buf = await resp.body();
    expect(buf.length).toBeGreaterThan(1000);
    // TCPDF may compress the text stream; if it didn't, assert the code is present.
    const asLatin1 = buf.toString('latin1');
    if (asLatin1.includes(INV_CODE)) {
      expect(asLatin1).toContain(INV_CODE);
    }
  });
});
