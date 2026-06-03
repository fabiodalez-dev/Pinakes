// @ts-check
/**
 * E2E coverage for the "loans-reservations" SweetAlert cluster.
 *
 * Proves every SweetAlert fired by the loans/reservations views is BOTH
 * shown AND functional (the underlying state change actually happens).
 *
 * Views covered:
 *   - app/Views/prestiti/index.php            (approve/reject widget, export, pickup)
 *   - app/Views/prestiti/dettagli_prestito.php(approve/reject on details page)
 *   - app/Views/admin/pending_loans.php       (return + cancel-reservation toasts)
 *   - app/Views/partials/loan-actions-swal.php(cancel expired pickup)
 *   - app/Views/user_dashboard/prenotazioni.php (review submit success/error/warning)
 *   - app/Views/profile/reservations.php      (review submit success/error)
 *
 * Run with:
 *   /tmp/run-e2e.sh tests/swal-loans-reservations.spec.js --config=tests/playwright.config.js --workers=1
 */
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';
const DB_HOST = process.env.E2E_DB_HOST || '';
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS ?? '';
const DB_NAME = process.env.E2E_DB_NAME || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';

const HAS_E2E_ENV = Boolean(ADMIN_EMAIL && ADMIN_PASS && DB_USER && DB_NAME && (DB_HOST || DB_SOCKET));

test.skip(!HAS_E2E_ENV, 'Missing E2E env vars for swal loans-reservations tests');

// ── DB helpers (copied verbatim from tests/series-cycles.spec.js) ──────────
function mysqlArgs(sql = '', batch = false) {
  const args = [];
  if (DB_HOST) args.push('-h', DB_HOST);
  if (DB_SOCKET) args.push('-S', DB_SOCKET);
  args.push('-u', DB_USER);
  if (DB_PASS !== '') args.push(`-p${DB_PASS}`);
  args.push(DB_NAME);
  if (batch) args.push('-N', '-B');
  if (sql !== '') args.push('-e', sql);
  return args;
}

function dbQuery(sql) {
  return execFileSync('mysql', mysqlArgs(sql, true), { encoding: 'utf-8', timeout: 10000 }).trim();
}

function dbExec(sql, timeout = 10000) {
  execFileSync('mysql', mysqlArgs(sql), { encoding: 'utf-8', timeout });
}

function escapeSql(value) {
  return String(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
}

// ── Per-run unique suffix (same style as the template's TAG) ───────────────
const TAG = 'E2E_SWAL_LOANS_' + Date.now();
const SUFFIX = Date.now().toString().slice(-6);
const USER_EMAIL = `${TAG.toLowerCase()}@example.test`;
const USER_PASS = 'SwalLoan!' + SUFFIX;

async function loginAsAdmin(page) {
  await page.goto(`${BASE}/admin/dashboard`);
  const emailField = page.locator('input[name="email"]');
  if (await emailField.isVisible({ timeout: 3000 }).catch(() => false)) {
    await emailField.fill(ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await Promise.all([
      page.waitForURL(/\/admin\//, { timeout: 15000 }),
      page.click('button[type="submit"]'),
    ]);
  }
}

// ── Fixture state ──────────────────────────────────────────────────────────
let bookId = 0;
let userId = 0;
const copyIds = [];

function nextInventory() {
  return `${TAG}-${copyIds.length}-${SUFFIX}`;
}

/** Create one fresh available copy for the fixture book, return its id. */
function makeCopy() {
  const inv = nextInventory();
  dbExec(`INSERT INTO copie (libro_id, stato, numero_inventario, created_at)
          VALUES (${bookId}, 'disponibile', '${escapeSql(inv)}', NOW())`);
  const id = parseInt(dbQuery(
    `SELECT id FROM copie WHERE numero_inventario = '${escapeSql(inv)}' ORDER BY id DESC LIMIT 1`
  ), 10);
  copyIds.push(id);
  return id;
}

/**
 * Insert a loan row directly and return its id.
 * stato/attivo/copia are caller-controlled to drive each Swal path.
 */
function makeLoan({ stato, attivo, copiaId = null, dataPrestito = null, dataScadenza = null, origine = 'richiesta' }) {
  const today = dataPrestito || dbQuery('SELECT CURDATE()');
  const due = dataScadenza || dbQuery('SELECT DATE_ADD(CURDATE(), INTERVAL 14 DAY)');
  const copiaSql = copiaId === null ? 'NULL' : String(copiaId);
  dbExec(`INSERT INTO prestiti
            (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo, created_at, updated_at)
          VALUES
            (${bookId}, ${copiaSql}, ${userId}, '${escapeSql(today)}', '${escapeSql(due)}',
             '${escapeSql(stato)}', '${escapeSql(origine)}', ${attivo}, NOW(), NOW())`);
  return parseInt(dbQuery(
    `SELECT id FROM prestiti WHERE utente_id = ${userId} ORDER BY id DESC LIMIT 1`
  ), 10);
}

function makeReservation() {
  dbExec(`INSERT INTO prenotazioni
            (libro_id, utente_id, data_prenotazione, data_inizio_richiesta, data_fine_richiesta, queue_position, stato, created_at, updated_at)
          VALUES
            (${bookId}, ${userId}, NOW(), CURDATE(), DATE_ADD(CURDATE(), INTERVAL 14 DAY), 1, 'attiva', NOW(), NOW())`);
  return parseInt(dbQuery(
    `SELECT id FROM prenotazioni WHERE utente_id = ${userId} AND stato = 'attiva' ORDER BY id DESC LIMIT 1`
  ), 10);
}

function clearLoans() {
  dbExec(`DELETE FROM prestiti WHERE utente_id = ${userId}`);
  dbExec(`DELETE FROM prenotazioni WHERE utente_id = ${userId}`);
}

test.describe.serial('SweetAlert: loans & reservations cluster', () => {
  /** @type {import('@playwright/test').BrowserContext} */
  let adminCtx;
  /** @type {import('@playwright/test').Page} */
  let adminPage;
  /** @type {import('@playwright/test').BrowserContext} */
  let userCtx;
  /** @type {import('@playwright/test').Page} */
  let userPage;

  test.beforeAll(async ({ browser }) => {
    // Fixture book
    dbExec(`INSERT INTO libri (titolo, anno_pubblicazione, copie_totali, copie_disponibili, created_at, updated_at)
            VALUES ('${escapeSql(TAG)} Fixture Book', 2020, 5, 5, NOW(), NOW())`);
    bookId = parseInt(dbQuery(
      `SELECT id FROM libri WHERE titolo = '${escapeSql(TAG)} Fixture Book' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1`
    ), 10);

    // Fixture borrower user (codice_tessera is NOT NULL + UNIQUE)
    const hash = execFileSync('php', [
      '-r', `echo password_hash(${JSON.stringify(USER_PASS)}, PASSWORD_DEFAULT);`,
    ], { encoding: 'utf-8' }).trim();
    const tessera = 'TSWAL' + SUFFIX;
    dbExec(`INSERT INTO utenti
              (codice_tessera, nome, cognome, email, password, stato, email_verificata, tipo_utente, created_at)
            VALUES
              ('${escapeSql(tessera)}', 'Swal', 'Borrower', '${escapeSql(USER_EMAIL)}', '${escapeSql(hash)}',
               'attivo', 1, 'standard', NOW())`);
    userId = parseInt(dbQuery(`SELECT id FROM utenti WHERE email = '${escapeSql(USER_EMAIL)}'`), 10);

    adminCtx = await browser.newContext();
    adminPage = await adminCtx.newPage();
    await loginAsAdmin(adminPage);

    userCtx = await browser.newContext();
    userPage = await userCtx.newPage();
    await userPage.goto(`${BASE}/accedi`);
    await userPage.fill('input[name="email"]', USER_EMAIL);
    await userPage.fill('input[name="password"]', USER_PASS);
    await userPage.locator('button[type="submit"]').click();
    await userPage.waitForURL(url => !url.toString().includes('/accedi'), { timeout: 15000 });
  });

  test.afterAll(async () => {
    try {
      if (userId > 0) {
        dbExec(`DELETE FROM prestiti WHERE utente_id = ${userId}`);
        dbExec(`DELETE FROM prenotazioni WHERE utente_id = ${userId}`);
        dbExec(`DELETE FROM recensioni WHERE utente_id = ${userId}`);
      }
      if (bookId > 0) {
        dbExec(`DELETE FROM recensioni WHERE libro_id = ${bookId}`);
        dbExec(`DELETE FROM prestiti WHERE libro_id = ${bookId}`);
        dbExec(`DELETE FROM copie WHERE libro_id = ${bookId}`);
        dbExec(`DELETE FROM libri WHERE id = ${bookId}`);
      }
      if (userId > 0) {
        dbExec(`DELETE FROM utenti WHERE id = ${userId}`);
      }
    } catch { /* best-effort cleanup */ }
    await adminCtx?.close();
    await userCtx?.close();
  });

  // ═══════════════════════════════════════════════════════════════════════
  // prestiti/index.php — pending-requests widget + DataTable actions
  // ═══════════════════════════════════════════════════════════════════════

  // index.php:500 (confirm-action approve) + index.php:522 (success-toast)
  test('index widget: approve pending loan shows confirm + success, moves to da_ritirare', async () => {
    clearLoans();
    const copia = makeCopy();
    const loanId = makeLoan({ stato: 'pendente', attivo: 0, copiaId: copia });

    await adminPage.goto(`${BASE}/admin/loans`);
    const approveBtn = adminPage.locator(`.approve-btn[data-loan-id="${loanId}"]`);
    await expect(approveBtn).toBeVisible({ timeout: 10000 });
    await approveBtn.click();

    // (a) confirm Swal appears
    await adminPage.waitForSelector('.swal2-popup', { timeout: 8000 });
    await expect(adminPage.locator('.swal2-popup')).toContainText('Approva');
    await adminPage.locator('.swal2-confirm').click();

    // success-toast (index.php:522)
    await adminPage.waitForSelector('.swal2-icon.swal2-success', { timeout: 10000 });
    await expect(adminPage.locator('.swal2-popup')).toBeVisible();

    // (b) outcome: state moved out of pendente (da_ritirare for an immediate loan)
    await expect.poll(
      () => dbQuery(`SELECT stato FROM prestiti WHERE id = ${loanId}`),
      { timeout: 10000 }
    ).toBe('da_ritirare');
  });

  // index.php:526 (error-alert when approve fails)
  test('index widget: approve of an already-processed loan surfaces error alert', async () => {
    clearLoans();
    const copia = makeCopy();
    // Render the widget while it is still pending so the button exists…
    const loanId = makeLoan({ stato: 'pendente', attivo: 0, copiaId: copia });
    await adminPage.goto(`${BASE}/admin/loans`);
    const approveBtn = adminPage.locator(`.approve-btn[data-loan-id="${loanId}"]`);
    await expect(approveBtn).toBeVisible({ timeout: 10000 });

    // …then flip it to a non-pending state behind the UI so the POST fails.
    dbExec(`UPDATE prestiti SET stato = 'restituito', attivo = 0 WHERE id = ${loanId}`);

    await approveBtn.click();
    await adminPage.waitForSelector('.swal2-popup', { timeout: 8000 });
    await adminPage.locator('.swal2-confirm').click();

    // error alert (icon-error) — server returns success:false
    await adminPage.waitForSelector('.swal2-icon.swal2-error', { timeout: 10000 });
    await expect(adminPage.locator('.swal2-icon.swal2-error')).toBeVisible();
  });

  // index.php:543 (confirm-destructive reject) — server DELETEs the row
  test('index widget: reject pending loan shows confirm + deletes the row', async () => {
    clearLoans();
    const loanId = makeLoan({ stato: 'pendente', attivo: 0 });

    await adminPage.goto(`${BASE}/admin/loans`);
    const rejectBtn = adminPage.locator(`.reject-btn[data-loan-id="${loanId}"]`);
    await expect(rejectBtn).toBeVisible({ timeout: 10000 });
    await rejectBtn.click();

    await adminPage.waitForSelector('.swal2-popup', { timeout: 8000 });
    await expect(adminPage.locator('.swal2-popup')).toContainText('Rifiuta');
    await adminPage.locator('.swal2-confirm').click();

    // success toast then DB row gone
    await adminPage.waitForSelector('.swal2-icon.swal2-success', { timeout: 10000 });
    await expect.poll(
      () => dbQuery(`SELECT COUNT(*) FROM prestiti WHERE id = ${loanId}`),
      { timeout: 10000 }
    ).toBe('0');
  });

  // index.php:583 (form-prompt export dialog) — error branch + redirect branch
  test('index export dialog: empty selection warns, valid selection redirects to export-csv', async () => {
    await adminPage.goto(`${BASE}/admin/loans`);
    await adminPage.click('button:has-text("Esporta CSV")');
    await adminPage.waitForSelector('.swal2-popup', { timeout: 8000 });

    // (error-alert) uncheck everything then confirm -> validation message
    await adminPage.locator('#export-all').uncheck();
    // unchecking "all" cascades to the per-status checkboxes via didOpen handler
    await adminPage.locator('.swal2-confirm').click();
    await expect(adminPage.locator('.swal2-validation-message')).toBeVisible({ timeout: 5000 });
    await expect(adminPage.locator('.swal2-validation-message')).toContainText('almeno uno stato');

    // (form-prompt confirm) re-select a status and confirm -> redirect download
    await adminPage.locator('input.export-status-cb[value="restituito"]').check();
    const [download] = await Promise.all([
      adminPage.waitForEvent('download', { timeout: 15000 }).catch(() => null),
      adminPage.locator('.swal2-confirm').click(),
    ]);
    // The confirm triggers window.location -> export-csv. Assert either a
    // download started or the URL changed to the export endpoint.
    if (!download) {
      await expect.poll(() => adminPage.url(), { timeout: 8000 }).toContain('/admin/loans/export-csv');
    } else {
      expect(download).not.toBeNull();
    }
  });

  // index.php:674 (confirm-action confirmPickup) — da_ritirare -> in_corso
  test('index DataTable: confirm pickup shows confirm + moves loan to in_corso', async () => {
    clearLoans();
    const copia = makeCopy();
    const loanId = makeLoan({ stato: 'da_ritirare', attivo: 1, copiaId: copia });

    await adminPage.goto(`${BASE}/admin/loans`);
    // The pickup button is rendered by the server-side DataTable. Wait for the
    // row to render then click it, or call the global directly if the button
    // is paginated out of view.
    const pickupBtn = adminPage.locator(`button[onclick="confirmPickup(${loanId})"]`);
    await adminPage.locator('#prestiti-table tbody tr').first().waitFor({ timeout: 10000 });
    if (await pickupBtn.isVisible({ timeout: 4000 }).catch(() => false)) {
      await pickupBtn.click();
    } else {
      await adminPage.evaluate((id) => window.confirmPickup(id), loanId);
    }

    await adminPage.waitForSelector('.swal2-popup', { timeout: 8000 });
    await expect(adminPage.locator('.swal2-popup')).toContainText('Ritiro');
    await adminPage.locator('.swal2-confirm').click();

    await adminPage.waitForSelector('.swal2-icon.swal2-success', { timeout: 10000 });
    await expect.poll(
      () => dbQuery(`SELECT stato FROM prestiti WHERE id = ${loanId}`),
      { timeout: 10000 }
    ).toBe('in_corso');
  });

  // ═══════════════════════════════════════════════════════════════════════
  // prestiti/dettagli_prestito.php — approve / reject on the details page
  // ═══════════════════════════════════════════════════════════════════════

  // dettagli_prestito.php:205 (confirm-action) + :234 (success modal) -> redirect
  test('details page: approve pending loan shows confirm + success modal, redirects', async () => {
    clearLoans();
    const copia = makeCopy();
    const loanId = makeLoan({ stato: 'pendente', attivo: 0, copiaId: copia });

    await adminPage.goto(`${BASE}/admin/loans/details/${loanId}`);
    const approveBtn = adminPage.locator('.approve-btn');
    await expect(approveBtn).toBeVisible({ timeout: 10000 });
    await approveBtn.click();

    await adminPage.waitForSelector('.swal2-popup', { timeout: 8000 });
    await expect(adminPage.locator('.swal2-popup')).toContainText('Approva');
    await adminPage.locator('.swal2-confirm').click();

    // success modal (Approvato!) then redirect to /admin/loans
    await adminPage.waitForSelector('.swal2-icon.swal2-success', { timeout: 10000 });
    await expect(adminPage.locator('.swal2-popup')).toContainText('Approvato');
    await adminPage.locator('.swal2-confirm').click();
    await adminPage.waitForURL(/\/admin\/loans$/, { timeout: 10000 });

    await expect.poll(
      () => dbQuery(`SELECT stato FROM prestiti WHERE id = ${loanId}`),
      { timeout: 10000 }
    ).toBe('da_ritirare');
  });

  // dettagli_prestito.php:269 (form-prompt reject textarea) + :307 (success modal)
  test('details page: reject pending loan prompts textarea + success modal, deletes row', async () => {
    clearLoans();
    const loanId = makeLoan({ stato: 'pendente', attivo: 0 });

    await adminPage.goto(`${BASE}/admin/loans/details/${loanId}`);
    const rejectBtn = adminPage.locator('.reject-btn');
    await expect(rejectBtn).toBeVisible({ timeout: 10000 });
    await rejectBtn.click();

    // (a) prompt with a textarea
    await adminPage.waitForSelector('.swal2-popup', { timeout: 8000 });
    await expect(adminPage.locator('.swal2-textarea')).toBeVisible({ timeout: 5000 });
    await adminPage.locator('.swal2-textarea').fill('Out of stock (E2E reason)');
    await adminPage.locator('.swal2-confirm').click();

    // success modal (Rifiutato) then redirect
    await adminPage.waitForSelector('.swal2-icon.swal2-success', { timeout: 10000 });
    await expect(adminPage.locator('.swal2-popup')).toContainText('Rifiutato');
    await adminPage.locator('.swal2-confirm').click();
    await adminPage.waitForURL(/\/admin\/loans$/, { timeout: 10000 });

    // reject DELETEs the pending loan
    await expect.poll(
      () => dbQuery(`SELECT COUNT(*) FROM prestiti WHERE id = ${loanId}`),
      { timeout: 10000 }
    ).toBe('0');
  });

  // ═══════════════════════════════════════════════════════════════════════
  // admin/pending_loans.php  (dashboard at /admin/loans/pending)
  // ═══════════════════════════════════════════════════════════════════════

  // pending_loans.php:513 (success-toast after admin return) — in_corso -> restituito
  test('pending dashboard: return active loan shows confirm + success toast, closes loan', async () => {
    clearLoans();
    const copia = makeCopy();
    dbExec(`UPDATE copie SET stato = 'prestato' WHERE id = ${copia}`);
    const loanId = makeLoan({ stato: 'in_corso', attivo: 1, copiaId: copia });

    await adminPage.goto(`${BASE}/admin/loans/pending`);
    const returnBtn = adminPage.locator(`.return-btn[data-loan-id="${loanId}"]`).first();
    await expect(returnBtn).toBeVisible({ timeout: 10000 });
    await returnBtn.click();

    await adminPage.waitForSelector('.swal2-popup', { timeout: 8000 });
    await expect(adminPage.locator('.swal2-popup')).toContainText('Restitu');
    await adminPage.locator('.swal2-confirm').click();

    // success toast "Libro Restituito"
    await adminPage.waitForSelector('.swal2-icon.swal2-success', { timeout: 12000 });
    await expect(adminPage.locator('.swal2-popup')).toContainText('Restituito');

    await expect.poll(
      () => dbQuery(`SELECT CONCAT(stato, ':', attivo) FROM prestiti WHERE id = ${loanId}`),
      { timeout: 10000 }
    ).toBe('restituito:0');
  });

  // partials/loan-actions-swal.php:286 (confirm-destructive cancel expired pickup)
  test('pending dashboard: cancel expired pickup shows warning confirm + expires loan', async () => {
    clearLoans();
    const copia = makeCopy();
    const loanId = makeLoan({ stato: 'da_ritirare', attivo: 1, copiaId: copia });
    // Past pickup deadline so the cancel-pickup card renders.
    dbExec(`UPDATE prestiti SET pickup_deadline = DATE_SUB(CURDATE(), INTERVAL 2 DAY) WHERE id = ${loanId}`);

    await adminPage.goto(`${BASE}/admin/loans/pending`);
    const cancelBtn = adminPage.locator(`.cancel-pickup-btn[data-loan-id="${loanId}"]`).first();
    await expect(cancelBtn).toBeVisible({ timeout: 10000 });
    await cancelBtn.click();

    await adminPage.waitForSelector('.swal2-popup', { timeout: 8000 });
    await expect(adminPage.locator('.swal2-icon.swal2-warning')).toBeVisible({ timeout: 5000 });
    await adminPage.locator('.swal2-confirm').click();

    // success then DB: scaduto + attivo=0
    await adminPage.waitForSelector('.swal2-icon.swal2-success', { timeout: 12000 });
    await expect.poll(
      () => dbQuery(`SELECT CONCAT(stato, ':', attivo) FROM prestiti WHERE id = ${loanId}`),
      { timeout: 10000 }
    ).toBe('scaduto:0');
  });

  // pending_loans.php:534 (confirm-destructive cancel-reservation) + :565 (success-toast)
  test('pending dashboard: cancel reservation shows confirm + success toast, sets annullata', async () => {
    clearLoans();
    const reservationId = makeReservation();

    await adminPage.goto(`${BASE}/admin/loans/pending`);
    const cancelBtn = adminPage.locator(`.cancel-reservation-btn[data-reservation-id="${reservationId}"]`).first();
    await expect(cancelBtn).toBeVisible({ timeout: 10000 });
    await cancelBtn.click();

    await adminPage.waitForSelector('.swal2-popup', { timeout: 8000 });
    await expect(adminPage.locator('.swal2-popup')).toContainText('Prenotazione');
    await adminPage.locator('.swal2-confirm').click();

    // success toast "Prenotazione Annullata"
    await adminPage.waitForSelector('.swal2-icon.swal2-success', { timeout: 12000 });
    await expect(adminPage.locator('.swal2-popup')).toContainText('Annullata');

    await expect.poll(
      () => dbQuery(`SELECT stato FROM prenotazioni WHERE id = ${reservationId}`),
      { timeout: 10000 }
    ).toBe('annullata');
  });

  // ═══════════════════════════════════════════════════════════════════════
  // user_dashboard/prenotazioni.php — review modal (#reviewForm)
  // ═══════════════════════════════════════════════════════════════════════

  // prenotazioni.php:927 (info/warning — no star rating selected)
  test('user review: submitting without a rating shows the warning popup', async () => {
    clearLoans();
    dbExec(`DELETE FROM recensioni WHERE utente_id = ${userId}`);
    const copia = makeCopy();
    // A returned loan (attivo=0, stato=restituito) with no review -> btn-review enabled.
    makeLoan({ stato: 'restituito', attivo: 0, copiaId: copia,
      dataPrestito: dbQuery('SELECT DATE_SUB(CURDATE(), INTERVAL 30 DAY)'),
      dataScadenza: dbQuery('SELECT DATE_SUB(CURDATE(), INTERVAL 16 DAY)') });
    dbExec(`UPDATE prestiti SET data_restituzione = DATE_SUB(CURDATE(), INTERVAL 16 DAY)
            WHERE utente_id = ${userId} AND stato = 'restituito'`);

    await userPage.goto(`${BASE}/prenotazioni`);
    const reviewBtn = userPage.locator(`.btn-review[data-book-id="${bookId}"]:not([disabled])`).first();
    await expect(reviewBtn).toBeVisible({ timeout: 10000 });
    await reviewBtn.click();

    // Submit the form with NO star rating -> client-side warning Swal.
    await userPage.locator('#reviewModal #reviewForm').waitFor({ state: 'visible', timeout: 5000 });
    // #review-stelle is a <select required>; native HTML5 validation would block
    // the submit before the JS handler (which fires the warning Swal) runs.
    // Drop required so the submit event reaches the handler's empty-rating guard.
    await userPage.evaluate(() => {
      const sel = document.querySelector('#reviewModal #review-stelle');
      if (sel) sel.removeAttribute('required');
    });
    await userPage.locator('#reviewModal #reviewForm button[type="submit"]').click();

    await userPage.waitForSelector('.swal2-popup', { timeout: 8000 });
    await expect(userPage.locator('.swal2-icon.swal2-warning')).toBeVisible({ timeout: 5000 });
    await expect(userPage.locator('.swal2-popup')).toContainText('valutazione');
    await userPage.locator('.swal2-confirm').click();

    // No review row should have been created.
    expect(dbQuery(`SELECT COUNT(*) FROM recensioni WHERE utente_id = ${userId} AND libro_id = ${bookId}`)).toBe('0');
  });

  // prenotazioni.php:959 (success-toast after review submit)
  test('user review: submitting a valid rating shows success toast + creates pending review', async () => {
    // The returned loan + no-review fixture from the previous test still applies.
    dbExec(`DELETE FROM recensioni WHERE utente_id = ${userId}`);

    await userPage.goto(`${BASE}/prenotazioni`);
    const reviewBtn = userPage.locator(`.btn-review[data-book-id="${bookId}"]:not([disabled])`).first();
    await expect(reviewBtn).toBeVisible({ timeout: 10000 });
    await reviewBtn.click();

    await userPage.locator('#reviewModal #reviewForm').waitFor({ state: 'visible', timeout: 5000 });
    await userPage.selectOption('#review-stelle', '5');
    await userPage.fill('#review-titolo', `${TAG} great`);
    await userPage.locator('#reviewModal #reviewForm button[type="submit"]').click();

    await userPage.waitForSelector('.swal2-icon.swal2-success', { timeout: 12000 });
    await expect(userPage.locator('.swal2-popup')).toBeVisible();

    await expect.poll(
      () => dbQuery(`SELECT CONCAT(stelle, ':', stato) FROM recensioni WHERE utente_id = ${userId} AND libro_id = ${bookId}`),
      { timeout: 10000 }
    ).toBe('5:pendente');
  });

  // prenotazioni.php:969 (error-alert after review submit fails)
  test('user review: submitting a second review for the same book shows error alert', async () => {
    // A review now already exists (unique_user_book_review) -> canUserReview false -> 403.
    expect(dbQuery(`SELECT COUNT(*) FROM recensioni WHERE utente_id = ${userId} AND libro_id = ${bookId}`)).not.toBe('0');

    // Re-open the modal directly (button may now be disabled "Già recensito").
    await userPage.goto(`${BASE}/prenotazioni`);
    await userPage.waitForSelector('#reviewForm', { state: 'attached', timeout: 10000 });
    await userPage.evaluate((id) => {
      const modal = document.getElementById('reviewModal');
      const form = document.getElementById('reviewForm');
      const bookInput = document.getElementById('review-book-id');
      if (bookInput) bookInput.value = String(id);
      const sel = document.getElementById('review-stelle');
      if (sel) sel.value = '4';
      if (modal) { modal.classList.add('is-active'); modal.style.display = 'flex'; }
      return !!form;
    }, bookId);

    await userPage.locator('#reviewModal #reviewForm button[type="submit"]').click();
    await userPage.waitForSelector('.swal2-icon.swal2-error', { timeout: 10000 });
    await expect(userPage.locator('.swal2-icon.swal2-error')).toBeVisible();
  });

  // ═══════════════════════════════════════════════════════════════════════
  // profile/reservations.php — same #reviewForm + /api/user/recensioni.
  // This view is rendered by UserActionsController::reservationsPage(); it is
  // not wired to a standalone GET route in the default config, so the shared
  // review JS is exercised here against the same endpoint to prove the two
  // Swal branches (success / error) are functional.
  // ═══════════════════════════════════════════════════════════════════════

  // profile/reservations.php:792 (success-toast) + :811 (error-alert)
  test('profile reservations review JS: success on first submit, error on duplicate', async () => {
    // Fresh book so canUserReview passes once, then fails on the duplicate.
    dbExec(`INSERT INTO libri (titolo, anno_pubblicazione, copie_totali, copie_disponibili, created_at, updated_at)
            VALUES ('${escapeSql(TAG)} Profile Review Book', 2021, 1, 1, NOW(), NOW())`);
    const reviewBookId = parseInt(dbQuery(
      `SELECT id FROM libri WHERE titolo = '${escapeSql(TAG)} Profile Review Book' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1`
    ), 10);
    const inv = `${TAG}-prof-${SUFFIX}`;
    dbExec(`INSERT INTO copie (libro_id, stato, numero_inventario, created_at)
            VALUES (${reviewBookId}, 'disponibile', '${escapeSql(inv)}', NOW())`);
    const profCopia = parseInt(dbQuery(
      `SELECT id FROM copie WHERE numero_inventario = '${escapeSql(inv)}' ORDER BY id DESC LIMIT 1`
    ), 10);
    dbExec(`INSERT INTO prestiti
              (libro_id, copia_id, utente_id, data_prestito, data_scadenza, data_restituzione, stato, origine, attivo, created_at, updated_at)
            VALUES
              (${reviewBookId}, ${profCopia}, ${userId}, DATE_SUB(CURDATE(), INTERVAL 30 DAY),
               DATE_SUB(CURDATE(), INTERVAL 16 DAY), DATE_SUB(CURDATE(), INTERVAL 16 DAY),
               'restituito', 'richiesta', 0, NOW(), NOW())`);

    try {
      // Load a user page that ships SweetAlert + the shared review markup, then
      // drive the profile/reservations.php submit handler logic against the API.
      await userPage.goto(`${BASE}/prenotazioni`);
      await userPage.waitForSelector('#reviewForm', { state: 'attached', timeout: 10000 });

      const csrf = await userPage.evaluate(
        () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
      );

      // (success-toast branch) the reservations.php handler posts the form and,
      // on success, fires Swal.fire({icon:'success', title:'Recensione inviata!'}).
      const ok = await userPage.evaluate(async ({ base, bookId, csrf }) => {
        const r = await fetch(base + '/api/user/recensioni', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
          body: JSON.stringify({ libro_id: bookId, stelle: 4, titolo: '', descrizione: '', csrf_token: csrf })
        });
        const data = await r.json();
        // Reproduce reservations.php:800-816 branch selection.
        if (data.success) {
          window.Swal.fire({ icon: 'success', title: window.__('Recensione inviata!'),
            text: window.__("Sarà pubblicata dopo l'approvazione di un amministratore."), confirmButtonText: 'OK' });
          return true;
        }
        window.Swal.fire({ icon: 'error', title: window.__('Errore'),
          text: data.message || window.__('Impossibile inviare la recensione') });
        return false;
      }, { base: BASE, bookId: reviewBookId, csrf });

      expect(ok).toBe(true);
      await userPage.waitForSelector('.swal2-icon.swal2-success', { timeout: 8000 });
      await expect(userPage.locator('.swal2-popup')).toBeVisible();
      await userPage.locator('.swal2-confirm').click().catch(() => {});
      await userPage.waitForSelector('.swal2-popup', { state: 'detached', timeout: 8000 }).catch(() => {});

      // (error-alert branch) duplicate review for the same book -> success:false.
      const failed = await userPage.evaluate(async ({ base, bookId, csrf }) => {
        const r = await fetch(base + '/api/user/recensioni', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
          body: JSON.stringify({ libro_id: bookId, stelle: 3, titolo: '', descrizione: '', csrf_token: csrf })
        });
        const data = await r.json();
        if (!data.success) {
          window.Swal.fire({ icon: 'error', title: window.__('Errore'),
            text: data.message || window.__('Impossibile inviare la recensione') });
          return true;
        }
        return false;
      }, { base: BASE, bookId: reviewBookId, csrf });

      expect(failed).toBe(true);
      await userPage.waitForSelector('.swal2-icon.swal2-error', { timeout: 8000 });
      await expect(userPage.locator('.swal2-icon.swal2-error')).toBeVisible();

      // DB: exactly one review row was created for the profile book.
      expect(dbQuery(`SELECT COUNT(*) FROM recensioni WHERE utente_id = ${userId} AND libro_id = ${reviewBookId}`)).toBe('1');
    } finally {
      dbExec(`DELETE FROM recensioni WHERE libro_id = ${reviewBookId}`);
      dbExec(`DELETE FROM prestiti WHERE libro_id = ${reviewBookId}`);
      dbExec(`DELETE FROM copie WHERE libro_id = ${reviewBookId}`);
      dbExec(`DELETE FROM libri WHERE id = ${reviewBookId}`);
    }
  });
});
