// @ts-check
// Discussion #238 — loan-form UX features (comments #14 of 2026-08-04 and #15
// of 2026-08-11), shipped in v0.7.59. Behavioral browser coverage for:
//   #14.1a  "Me" button fills the logged-in operator as the borrower
//   #14.1b  a failed submit re-renders the form with the entered values retained
//   #14.2   "Salva e registra un'altra copia" (save_and_new): loan created, form
//           comes back with user + dates retained and the copy code cleared
//   #15.1a  the stale "Prestito creato con successo." alert (#loan_created_alert)
//           is removed on the first form edit (input/change on the form)
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS  = process.env.E2E_ADMIN_PASS  || '';
const TEST_EMAIL  = 'disc238-borrower@example.test';
const TEST_PASS   = 'Borrow1234!';

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

// Add N days to an ISO Y-m-d string without timezone drift.
function addDaysIso(iso, days) {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso);
  if (!m) throw new Error(`not an ISO date: ${iso}`);
  const d = new Date(Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3])));
  d.setUTCDate(d.getUTCDate() + days);
  return d.toISOString().slice(0, 10);
}

// Unique per-run identifiers so repeated runs never collide (numero_inventario
// is UNIQUE; titles are used to find/clean the fixtures).
const RUN = Date.now().toString().slice(-7);
const BOOK_A_TITLE = `Disc238 Fixture A ${RUN}`;
const BOOK_B_TITLE = `Disc238 Fixture B ${RUN}`;
const INV_A = `DISC238-A-${RUN}`;
const INV_B = `DISC238-B-${RUN}`;
const NOTE_TEXT = `disc238 retained note ${RUN}`;

let bookAId = 0;
let bookBId = 0;
let userId = 0;

async function loginAdmin(page) {
  await page.goto(`${BASE}/accedi`);
  await page.fill('input[name="email"]', ADMIN_EMAIL);
  await page.fill('input[name="password"]', ADMIN_PASS);
  await page.click('button[type="submit"]');
  await page.waitForURL(u => u.toString().includes('/admin'), { timeout: 15000 });
}

// Pick the fixture borrower via the user autocomplete.
async function selectBorrower(page) {
  await page.fill('#utente_search', TEST_EMAIL);
  const userSug = page.locator('#utente_suggest .suggestion-item').first();
  await expect(userSug).toBeVisible({ timeout: 8000 });
  await userSug.click();
  await expect(page.locator('#utente_id')).toHaveValue(String(userId));
}

// Let the copy-code resolver identify the book from an inventory code.
async function resolveBookByCopyCode(page, invCode, expectedBookId) {
  await page.fill('#copy_code', invCode);
  await expect.poll(
    async () => page.locator('#libro_id').inputValue(),
    { timeout: 8000 },
  ).toBe(String(expectedBookId));
}

// Set the due date through the flatpickr instance (the raw input is hidden by
// altInput, so page.fill() cannot reach it).
async function setDueDate(page, isoDate) {
  await expect.poll(
    async () => page.evaluate(() => {
      const el = document.getElementById('data_scadenza');
      // @ts-ignore
      return !!(el && el._flatpickr);
    }),
    { timeout: 8000 },
  ).toBe(true);
  await page.evaluate((d) => {
    // @ts-ignore
    document.getElementById('data_scadenza')._flatpickr.setDate(d, true, 'Y-m-d');
  }, isoDate);
  await expect(page.locator('#data_scadenza')).toHaveValue(isoDate);
}

test.describe.configure({ mode: 'serial' });

test.describe('Discussion #238 — loan form UX', () => {
  test.beforeAll(() => {
    // Clean any stale fixture from previous runs.
    dbQuery(`DELETE FROM prestiti WHERE libro_id IN (SELECT id FROM libri WHERE titolo IN ('${BOOK_A_TITLE}','${BOOK_B_TITLE}'))`);
    dbQuery(`DELETE FROM prestiti WHERE utente_id IN (SELECT id FROM utenti WHERE email='${TEST_EMAIL}')`);
    dbQuery(`DELETE FROM copie WHERE numero_inventario IN ('${INV_A}','${INV_B}')`);
    dbQuery(`DELETE FROM libri WHERE titolo IN ('${BOOK_A_TITLE}','${BOOK_B_TITLE}')`);
    dbQuery(`DELETE FROM utenti WHERE email='${TEST_EMAIL}'`);

    // Borrower (codice_tessera is NOT NULL + UNIQUE).
    const hash = execFileSync('php', ['-r', `echo password_hash('${TEST_PASS}', PASSWORD_DEFAULT);`], { encoding: 'utf-8' }).trim();
    const tessera = 'D238' + RUN;
    dbQuery(
      `INSERT INTO utenti (codice_tessera, nome, cognome, email, password, stato, email_verificata, tipo_utente, created_at)
       VALUES ('${tessera}', 'Disc', 'Borrower', '${TEST_EMAIL}', '${hash}', 'attivo', 1, 'standard', NOW())`
    );
    userId = parseInt(dbQuery(`SELECT id FROM utenti WHERE email='${TEST_EMAIL}'`), 10);

    // Two books, one available copy each with a known inventory code (two books
    // because active loans are unique per user/book: feature #14.2 and #15.1a
    // each create a real loan for the same borrower).
    for (const [title, inv] of [[BOOK_A_TITLE, INV_A], [BOOK_B_TITLE, INV_B]]) {
      dbQuery(
        `INSERT INTO libri (titolo, anno_pubblicazione, copie_totali, copie_disponibili, created_at, updated_at)
         VALUES ('${title}', 2024, 1, 1, NOW(), NOW())`
      );
      const id = parseInt(dbQuery(`SELECT id FROM libri WHERE titolo='${title}' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1`), 10);
      dbQuery(`INSERT INTO copie (libro_id, stato, numero_inventario, created_at) VALUES (${id}, 'disponibile', '${inv}', NOW())`);
      dbQuery(`UPDATE libri SET search_index = titolo WHERE id=${id}`);
      if (title === BOOK_A_TITLE) bookAId = id; else bookBId = id;
    }
  });

  test.afterAll(() => {
    dbQuery(`DELETE FROM prestiti WHERE libro_id IN (${bookAId || 0}, ${bookBId || 0})`);
    dbQuery(`DELETE FROM copie WHERE numero_inventario IN ('${INV_A}','${INV_B}')`);
    dbQuery(`DELETE FROM libri WHERE id IN (${bookAId || 0}, ${bookBId || 0})`);
    dbQuery(`DELETE FROM utenti WHERE email='${TEST_EMAIL}'`);
  });

  test('#14.1a "Me" button fills the logged-in admin as the borrower', async ({ page }) => {
    // The admin's own utenti row is the oracle for what the button must fill.
    const adminRow = dbQuery(`SELECT id, nome, cognome FROM utenti WHERE email='${ADMIN_EMAIL}' LIMIT 1`).split('\t');
    const adminId = parseInt(adminRow[0], 10);
    const adminNome = (adminRow[1] || '').trim();
    expect(adminId).toBeGreaterThan(0);

    await loginAdmin(page);
    await page.goto(`${BASE}/admin/loans/create`);

    const meBtn = page.locator('#utente_me_btn');
    await expect(meBtn).toBeVisible();
    // The button carries the operator's id resolved server-side.
    await expect(meBtn).toHaveAttribute('data-me-id', String(adminId));

    // The borrower starts empty; clicking "Me" fills hidden id + visible name.
    await expect(page.locator('#utente_id')).toHaveValue('0');
    await meBtn.click();
    await expect(page.locator('#utente_id')).toHaveValue(String(adminId));

    const meName = (await meBtn.getAttribute('data-me-name')) || '';
    expect(meName.length).toBeGreaterThan(0);
    await expect(page.locator('#utente_search')).toHaveValue(meName);
    if (adminNome !== '' && adminNome !== 'NULL') {
      expect(meName).toContain(adminNome);
    }
  });

  test('#14.1b failed submit re-renders the form with entered values retained', async ({ page }) => {
    await loginAdmin(page);
    await page.goto(`${BASE}/admin/loans/create`);

    await selectBorrower(page);
    // Identify the book from its real copy code first…
    await resolveBookByCopyCode(page, INV_A, bookAId);
    // …then replace the copy code with one that cannot resolve. The resolver
    // leaves #libro_id untouched on failure, and store() rejects the submit
    // with error=copy_not_found (transaction rolled back, no loan created).
    const bogusCode = `DISC238-NOPE-${RUN}`;
    await page.fill('#copy_code', bogusCode);

    // A due date different from the configured default proves the re-render
    // truly retains the submitted value instead of re-applying the default.
    const startDate = await page.locator('#data_prestito').inputValue();
    expect(startDate).toMatch(/^\d{4}-\d{2}-\d{2}$/);
    const customDue = addDaysIso(startDate, 45);
    await setDueDate(page, customDue);
    await page.fill('#note', NOTE_TEXT);

    await page.click('form[action$="/admin/loans/create"] button[type="submit"]:not([name="save_and_new"])');
    await page.waitForURL(u => u.toString().includes('error=copy_not_found'), { timeout: 15000 });

    // The error banner is shown…
    await expect(page.locator('.bg-red-100')).toBeVisible();
    // …no loan was created…
    expect(dbQuery(`SELECT COUNT(*) FROM prestiti WHERE libro_id=${bookAId}`)).toBe('0');
    // …and every entered field survived the redirect (loan_form_old session).
    await expect(page.locator('#utente_id')).toHaveValue(String(userId));
    await expect(page.locator('#utente_search')).toHaveValue(/Disc/);
    await expect(page.locator('#libro_id')).toHaveValue(String(bookAId));
    await expect(page.locator('#libro_search')).toHaveValue(new RegExp(BOOK_A_TITLE));
    await expect(page.locator('#data_prestito')).toHaveValue(startDate);
    await expect(page.locator('#data_scadenza')).toHaveValue(customDue);
    await expect(page.locator('#note')).toHaveValue(NOTE_TEXT);
  });

  test('#14.2 save_and_new creates the loan, retains user + dates, clears the copy code', async ({ page }) => {
    await loginAdmin(page);
    await page.goto(`${BASE}/admin/loans/create`);

    await selectBorrower(page);
    await resolveBookByCopyCode(page, INV_A, bookAId);

    const startDate = await page.locator('#data_prestito').inputValue();
    const customDue = addDaysIso(startDate, 45);
    await setDueDate(page, customDue);
    await page.fill('#note', NOTE_TEXT);
    // Avoid the PDF auto-download iframe so the landing page stays plain.
    await page.uncheck('#scarica_pdf');

    await page.click('button[name="save_and_new"]');
    await page.waitForURL(u => u.toString().includes('/admin/loans/create') && u.toString().includes('created=1'), { timeout: 15000 });

    // The loan exists in the DB and is pinned to the scanned copy.
    const loanCount = dbQuery(
      `SELECT COUNT(*) FROM prestiti p JOIN copie c ON p.copia_id=c.id
       WHERE p.libro_id=${bookAId} AND p.utente_id=${userId} AND c.numero_inventario='${INV_A}'`
    );
    expect(loanCount).toBe('1');

    // Success alert visible on the re-rendered form.
    await expect(page.locator('#loan_created_alert')).toBeVisible();

    // Borrower, dates and note RETAINED — ready for the next copy…
    await expect(page.locator('#utente_id')).toHaveValue(String(userId));
    await expect(page.locator('#utente_search')).toHaveValue(/Disc/);
    await expect(page.locator('#data_prestito')).toHaveValue(startDate);
    await expect(page.locator('#data_scadenza')).toHaveValue(customDue);
    await expect(page.locator('#note')).toHaveValue(NOTE_TEXT);
    // …while book and copy code are CLEARED (active loans are unique per
    // user/book, so retaining the book would fail the duplicate guard).
    await expect(page.locator('#copy_code')).toHaveValue('');
    await expect(page.locator('#libro_id')).toHaveValue('0');
    await expect(page.locator('#libro_search')).toHaveValue('');
  });

  test('#15.1a stale success alert is removed on the first form edit', async ({ page }) => {
    await loginAdmin(page);
    await page.goto(`${BASE}/admin/loans/create`);

    // Reach the alert state through the real multi-copy flow (book B: the
    // borrower already holds book A from the previous test).
    await selectBorrower(page);
    await resolveBookByCopyCode(page, INV_B, bookBId);
    await page.uncheck('#scarica_pdf');
    await page.click('button[name="save_and_new"]');
    await page.waitForURL(u => u.toString().includes('created=1'), { timeout: 15000 });
    await expect(page.locator('#loan_created_alert')).toBeVisible();

    // The JS handler removes the alert on the first 'input'/'change' event on
    // the form (crea_prestito.php ~line 325) — typing in the copy-code field
    // dispatches 'input', exactly the scanner/manual-entry path.
    await page.fill('#copy_code', 'X');
    await expect(page.locator('#loan_created_alert')).toHaveCount(0);
    // The handler also strips the stale ?created/?pdf flags from the URL so a
    // refresh cannot resurrect the old notice.
    await expect.poll(() => page.url()).not.toContain('created=1');
  });
});
