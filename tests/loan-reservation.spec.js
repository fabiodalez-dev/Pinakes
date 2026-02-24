// @ts-check
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS  = process.env.E2E_ADMIN_PASS  || '';
const TEST_EMAIL  = process.env.E2E_TEST_EMAIL || 'testloan@example.com';
const TEST_PASS   = process.env.E2E_TEST_PASS  || 'Test1234!';

const DB_USER   = process.env.E2E_DB_USER   || '';
const DB_PASS   = process.env.E2E_DB_PASS   || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';
const DB_NAME   = process.env.E2E_DB_NAME   || '';

// Skip all tests when credentials are not configured
test.skip(
  !ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_PASS || !DB_NAME,
  'E2E credentials not configured (set E2E_ADMIN_EMAIL, E2E_ADMIN_PASS, E2E_DB_USER, E2E_DB_PASS, E2E_DB_NAME)',
);

/**
 * Execute a MySQL query and return trimmed output.
 * Uses execFileSync (no shell) — all arguments passed via env vars.
 */
function dbQuery(sql) {
  const args = ['-u', DB_USER, `-p${DB_PASS}`, DB_NAME, '-N', '-B', '-e', sql];
  if (DB_SOCKET) args.splice(3, 0, '-S', DB_SOCKET);
  return execFileSync('mysql', args, { encoding: 'utf-8', timeout: 10000 }).trim();
}

/** Return today's date as YYYY-MM-DD. */
function todayISO() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

/** Dismiss a visible SweetAlert popup (success/error toast). */
async function dismissSwal(page) {
  try {
    const popup = page.locator('.swal2-popup');
    if (await popup.isVisible({ timeout: 2000 }).catch(() => false)) {
      const btn = page.locator('.swal2-confirm');
      if (await btn.isVisible({ timeout: 1000 }).catch(() => false)) {
        await btn.click();
      }
      await page.waitForFunction(
        () => !document.querySelector('.swal2-popup'),
        { timeout: 5000 },
      ).catch(() => {});
    }
  } catch (_) { /* already closed */ }
}

/**
 * Open SweetAlert date-picker on #btn-request-loan, set start date, confirm.
 * Returns true on success (icon-success), false on error (icon-error).
 */
async function requestLoanViaSwal(page, dateISO) {
  await page.locator('#btn-request-loan').click();
  await page.waitForSelector('.swal2-popup', { timeout: 8000 });

  // Wait for Flatpickr to initialise
  await page.waitForFunction(
    () => {
      const el = document.querySelector('#swal-date-start');
      return el && /** @type {any} */ (el)._flatpickr;
    },
    { timeout: 8000 },
  );

  // Set date programmatically
  await page.evaluate((iso) => {
    /** @type {any} */ (document.querySelector('#swal-date-start'))._flatpickr.setDate(iso, true);
  }, dateISO);

  // Click confirm
  await page.locator('.swal2-confirm').click();

  // Wait for either success or error SweetAlert
  await page.waitForFunction(
    () => !!document.querySelector('.swal2-icon-success, .swal2-icon-error'),
    { timeout: 15000 },
  );

  const succeeded = await page.locator('.swal2-icon-success').isVisible().catch(() => false);
  await dismissSwal(page);
  return succeeded;
}

// ────────────────────────────────────────────────────────────────────────
// Full loan/reservation lifecycle E2E (serial — session-dependent)
// ────────────────────────────────────────────────────────────────────────
test.describe.serial('Loan / Reservation Lifecycle', () => {

  /** @type {import('@playwright/test').BrowserContext} */
  let userCtx;
  /** @type {import('@playwright/test').Page} */
  let userPage;
  /** @type {import('@playwright/test').BrowserContext} */
  let adminCtx;
  /** @type {import('@playwright/test').Page} */
  let adminPage;

  let testUserId = 0;
  let testBookId = 0;
  let loanId     = 0;

  // ── Setup ──────────────────────────────────────────────────────────────
  test.beforeAll(async ({ browser }) => {
    // 1. Hash password via PHP
    const passLiteral = JSON.stringify(TEST_PASS);
    const hash = execFileSync('php', [
      '-r', `echo password_hash(${passLiteral}, PASSWORD_DEFAULT);`,
    ], { encoding: 'utf-8' }).trim();

    // 2. Clean stale test data
    dbQuery(`DELETE FROM prestiti WHERE utente_id IN (SELECT id FROM utenti WHERE email='${TEST_EMAIL}')`);
    dbQuery(`DELETE FROM prenotazioni WHERE utente_id IN (SELECT id FROM utenti WHERE email='${TEST_EMAIL}')`);
    dbQuery(`DELETE FROM utenti WHERE email='${TEST_EMAIL}'`);

    // 3. Create verified, active test user (codice_tessera is NOT NULL + UNIQUE)
    const tessera = 'TLOAN' + Date.now().toString().slice(-6);
    dbQuery(
      `INSERT INTO utenti (codice_tessera, nome, cognome, email, password, stato, email_verificata, tipo_utente, created_at)
       VALUES ('${tessera}', 'Test', 'Loan', '${TEST_EMAIL}', '${hash}', 'attivo', 1, 'standard', NOW())`,
    );
    testUserId = parseInt(dbQuery(`SELECT id FROM utenti WHERE email='${TEST_EMAIL}'`), 10);

    // 4. Find the test book
    testBookId = parseInt(
      dbQuery(`SELECT id FROM libri WHERE titolo LIKE '%Nome della Rosa%' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1`),
      10,
    );

    // 5. Neutralise any active loans from the smoke-test so our book starts clean
    dbQuery(`UPDATE prestiti SET stato='restituito', attivo=0, data_restituzione=CURDATE() WHERE libro_id=${testBookId} AND attivo=1`);
    dbQuery(`UPDATE copie SET stato='disponibile' WHERE libro_id=${testBookId} AND stato IN ('prestato','prenotato')`);

    // 6. Ensure at least 1 available copy
    const availCopies = parseInt(
      dbQuery(`SELECT COUNT(*) FROM copie WHERE libro_id=${testBookId} AND stato='disponibile'`) || '0', 10,
    );
    if (availCopies === 0) {
      dbQuery(
        `INSERT INTO copie (libro_id, stato, numero_inventario, created_at) VALUES (${testBookId}, 'disponibile', 'TEST-LOAN-001', NOW())`,
      );
    }

    // 7. Recalculate copie_disponibili
    dbQuery(
      `UPDATE libri SET copie_disponibili = (
         SELECT COUNT(*) FROM copie c
         LEFT JOIN prestiti p ON c.id = p.copia_id AND p.attivo = 1
           AND p.stato IN ('in_corso','in_ritardo','da_ritirare')
         WHERE c.libro_id = ${testBookId} AND c.stato = 'disponibile' AND p.id IS NULL
       ) WHERE id = ${testBookId}`,
    );

    // 8. Create browser contexts (one per user)
    userCtx  = await browser.newContext();
    userPage = await userCtx.newPage();
    adminCtx = await browser.newContext();
    adminPage = await adminCtx.newPage();
  });

  // ── Teardown ───────────────────────────────────────────────────────────
  test.afterAll(async () => {
    if (testUserId > 0) {
      dbQuery(`DELETE FROM prestiti WHERE utente_id=${testUserId}`);
      dbQuery(`DELETE FROM prenotazioni WHERE utente_id=${testUserId}`);
      dbQuery(`DELETE FROM utenti WHERE id=${testUserId}`);
    }
    // Restore copies
    if (testBookId > 0) {
      dbQuery(`UPDATE copie SET stato='disponibile' WHERE libro_id=${testBookId} AND stato IN ('prestato','prenotato')`);
      dbQuery(
        `UPDATE libri SET copie_disponibili = (
           SELECT COUNT(*) FROM copie WHERE libro_id=${testBookId} AND stato='disponibile'
         ) WHERE id=${testBookId}`,
      );
    }
    await userCtx?.close();
    await adminCtx?.close();
  });

  // ═══════════════════════════════════════════════════════════════════════
  // GROUP 1 — User Requests a Loan (copies available)
  // ═══════════════════════════════════════════════════════════════════════

  test('1.1: Login as test user', async () => {
    await userPage.goto(`${BASE}/accedi`);
    await userPage.fill('input[name="email"]', TEST_EMAIL);
    await userPage.fill('input[name="password"]', TEST_PASS);
    await userPage.locator('button[type="submit"]').click();
    await userPage.waitForURL(url => !url.toString().includes('/accedi'), { timeout: 15000 });
  });

  test('1.2: Request a loan from book detail page', async () => {
    await userPage.goto(`${BASE}/libro/${testBookId}`);
    await userPage.waitForLoadState('networkidle');

    // Button should say "Richiedi Prestito"
    const btn = userPage.locator('#btn-request-loan');
    await expect(btn).toBeVisible({ timeout: 5000 });
    const text = (await btn.textContent()) || '';
    expect(text).toMatch(/Richiedi Prestito/);

    // Open SweetAlert, set today, confirm
    const ok = await requestLoanViaSwal(userPage, todayISO());
    expect(ok).toBe(true);

    // DB verify
    const row = dbQuery(
      `SELECT stato, attivo FROM prestiti WHERE utente_id=${testUserId} AND libro_id=${testBookId} ORDER BY id DESC LIMIT 1`,
    );
    const [stato, attivo] = row.split('\t');
    expect(stato).toBe('pendente');
    expect(attivo).toBe('0');

    loanId = parseInt(
      dbQuery(`SELECT id FROM prestiti WHERE utente_id=${testUserId} AND libro_id=${testBookId} AND stato='pendente' ORDER BY id DESC LIMIT 1`),
      10,
    );
    expect(loanId).toBeGreaterThan(0);
  });

  test('1.3: Verify loan appears in user profile', async () => {
    await userPage.goto(`${BASE}/prenotazioni`);
    await userPage.waitForLoadState('networkidle');

    await expect(userPage.locator('text=Richieste in Sospeso')).toBeVisible({ timeout: 5000 });
    // Scope to the pending section's grid (the .items-grid immediately following the header)
    const pendingGrid = userPage.locator('.section-header:has-text("Richieste in Sospeso") + .items-grid');
    await expect(pendingGrid.locator('.item-card:has-text("Nome della Rosa")')).toBeVisible();
  });

  test('1.4: Duplicate loan prevention', async () => {
    await userPage.goto(`${BASE}/libro/${testBookId}`);
    await userPage.waitForLoadState('networkidle');

    const ok = await requestLoanViaSwal(userPage, todayISO());
    expect(ok).toBe(false); // should fail — duplicate

    // DB verify: still only 1 loan
    const count = parseInt(
      dbQuery(`SELECT COUNT(*) FROM prestiti WHERE utente_id=${testUserId} AND libro_id=${testBookId}`), 10,
    );
    expect(count).toBe(1);
  });

  // ═══════════════════════════════════════════════════════════════════════
  // GROUP 2 — Admin Approves the Loan
  // ═══════════════════════════════════════════════════════════════════════

  test('2.1: Login as admin', async () => {
    await adminPage.goto(`${BASE}/accedi`);
    await adminPage.fill('input[name="email"]', ADMIN_EMAIL);
    await adminPage.fill('input[name="password"]', ADMIN_PASS);
    await adminPage.locator('button[type="submit"]').click();
    await adminPage.waitForURL(url => !url.toString().includes('/accedi'), { timeout: 15000 });
  });

  test('2.2: Approve the loan', async () => {
    await adminPage.goto(`${BASE}/admin/loans/pending`);
    await adminPage.waitForLoadState('networkidle');

    const approveBtn = adminPage.locator(`.approve-btn[data-loan-id="${loanId}"]`);
    await expect(approveBtn).toBeVisible({ timeout: 5000 });

    await approveBtn.click();

    // Confirmation SweetAlert
    await adminPage.waitForSelector('.swal2-popup', { timeout: 5000 });
    await adminPage.locator('.swal2-confirm').click();

    // Wait for success (timer-based toast) or card removal
    await adminPage.waitForFunction(
      (id) => !!document.querySelector('.swal2-icon-success') || !document.querySelector(`[data-loan-id="${id}"]`),
      loanId,
      { timeout: 15000 },
    );
    await dismissSwal(adminPage);

    // DB verify: da_ritirare (today's loan), attivo=1
    const row = dbQuery(`SELECT stato, attivo FROM prestiti WHERE id=${loanId}`);
    const [stato, attivo] = row.split('\t');
    expect(stato).toBe('da_ritirare');
    expect(attivo).toBe('1');
  });

  test('2.3: Double-approval prevention', async () => {
    // Navigate to get fresh CSRF
    await adminPage.goto(`${BASE}/admin/loans/pending`);
    await adminPage.waitForLoadState('networkidle');
    const csrf = await adminPage.locator('meta[name="csrf-token"]').getAttribute('content');

    const result = await adminPage.evaluate(
      async ({ id, token }) => {
        const res = await fetch('/admin/loans/approve', {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-Token': token,
          },
          body: JSON.stringify({ loan_id: id }),
        });
        return { status: res.status, body: await res.json() };
      },
      { id: loanId, token: csrf },
    );

    expect(result.body.success).toBeFalsy();

    // DB verify: stato unchanged
    expect(dbQuery(`SELECT stato FROM prestiti WHERE id=${loanId}`)).toBe('da_ritirare');
  });

  // ═══════════════════════════════════════════════════════════════════════
  // GROUP 3 — Pickup Confirmation
  // ═══════════════════════════════════════════════════════════════════════

  test('3.1: Confirm pickup', async () => {
    await adminPage.goto(`${BASE}/admin/loans/pending`);
    await adminPage.waitForLoadState('networkidle');

    const pickupBtn = adminPage.locator(`.confirm-pickup-btn[data-loan-id="${loanId}"]`);
    await expect(pickupBtn).toBeVisible({ timeout: 5000 });

    await pickupBtn.click();
    await adminPage.waitForSelector('.swal2-popup', { timeout: 5000 });
    await adminPage.locator('.swal2-confirm').click();

    // Wait for success
    await adminPage.waitForFunction(
      () => !!document.querySelector('.swal2-icon-success') || !document.querySelector('.swal2-popup'),
      { timeout: 15000 },
    );
    await dismissSwal(adminPage);

    // DB verify: in_corso
    expect(dbQuery(`SELECT stato FROM prestiti WHERE id=${loanId}`)).toBe('in_corso');

    // DB verify: copy is 'prestato'
    const copyStat = dbQuery(`SELECT stato FROM copie WHERE id=(SELECT copia_id FROM prestiti WHERE id=${loanId})`);
    expect(copyStat).toBe('prestato');
  });

  test('3.2: User sees active loan', async () => {
    await userPage.goto(`${BASE}/prenotazioni`);
    await userPage.waitForLoadState('networkidle');

    await expect(userPage.locator('text=Prestiti attivi')).toBeVisible();
    const activeGrid = userPage.locator('.section-header:has-text("Prestiti attivi") + .items-grid');
    await expect(activeGrid.locator('.item-card:has-text("Nome della Rosa")')).toBeVisible();
  });

  // ═══════════════════════════════════════════════════════════════════════
  // GROUP 4 — Return the Book
  // ═══════════════════════════════════════════════════════════════════════

  test('4.1: Admin returns the loan', async () => {
    const copieBeforeReturn = parseInt(
      dbQuery(`SELECT copie_disponibili FROM libri WHERE id=${testBookId}`), 10,
    );

    await adminPage.goto(`${BASE}/admin/loans/pending`);
    await adminPage.waitForLoadState('networkidle');

    const returnBtn = adminPage.locator(`.return-btn[data-loan-id="${loanId}"]`);
    await expect(returnBtn).toBeVisible({ timeout: 5000 });

    await returnBtn.click();
    await adminPage.waitForSelector('.swal2-popup', { timeout: 5000 });
    await adminPage.locator('.swal2-confirm').click();

    // Return triggers page reload
    await adminPage.waitForLoadState('networkidle', { timeout: 15000 });
    // Extra wait for any lingering SweetAlert
    await dismissSwal(adminPage);

    // DB verify: restituito, attivo=0
    const row = dbQuery(`SELECT stato, attivo FROM prestiti WHERE id=${loanId}`);
    const [stato, attivo] = row.split('\t');
    expect(stato).toBe('restituito');
    expect(attivo).toBe('0');

    // DB verify: copy back to 'disponibile'
    const copyStat = dbQuery(`SELECT stato FROM copie WHERE id=(SELECT copia_id FROM prestiti WHERE id=${loanId})`);
    expect(copyStat).toBe('disponibile');

    // DB verify: copie_disponibili incremented
    const copieAfterReturn = parseInt(
      dbQuery(`SELECT copie_disponibili FROM libri WHERE id=${testBookId}`), 10,
    );
    expect(copieAfterReturn).toBeGreaterThan(copieBeforeReturn);
  });

  test('4.2: User sees loan in history', async () => {
    await userPage.goto(`${BASE}/prenotazioni`);
    await userPage.waitForLoadState('networkidle');

    await expect(userPage.locator('text=Prestiti passati')).toBeVisible();
    const historyGrid = userPage.locator('.section-header:has-text("Prestiti passati") + .items-grid');
    await expect(historyGrid.locator('.item-card:has-text("Nome della Rosa")').first()).toBeVisible({ timeout: 5000 });
  });

  // ═══════════════════════════════════════════════════════════════════════
  // GROUP 5 — Loan Rejection
  // ═══════════════════════════════════════════════════════════════════════

  test('5.1: Create another loan request', async () => {
    await userPage.goto(`${BASE}/libro/${testBookId}`);
    await userPage.waitForLoadState('networkidle');

    // Use tomorrow to avoid any same-day conflicts with the returned loan
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    const tomorrowISO = `${tomorrow.getFullYear()}-${String(tomorrow.getMonth() + 1).padStart(2, '0')}-${String(tomorrow.getDate()).padStart(2, '0')}`;

    const ok = await requestLoanViaSwal(userPage, tomorrowISO);
    expect(ok).toBe(true);

    // DB verify: new loan
    const newLoanId = parseInt(
      dbQuery(`SELECT id FROM prestiti WHERE utente_id=${testUserId} AND libro_id=${testBookId} AND stato='pendente' ORDER BY id DESC LIMIT 1`),
      10,
    );
    expect(newLoanId).toBeGreaterThan(loanId);
    loanId = newLoanId;
  });

  test('5.2: Admin rejects the loan', async () => {
    await adminPage.goto(`${BASE}/admin/loans/pending`);
    await adminPage.waitForLoadState('networkidle');

    const rejectBtn = adminPage.locator(`.reject-btn[data-loan-id="${loanId}"]`);
    await expect(rejectBtn).toBeVisible({ timeout: 5000 });

    await rejectBtn.click();
    await adminPage.waitForSelector('.swal2-popup', { timeout: 5000 });

    // Reject SweetAlert may have a textarea for reason
    const textarea = adminPage.locator('.swal2-textarea');
    if (await textarea.isVisible({ timeout: 1000 }).catch(() => false)) {
      await textarea.fill('Test rejection reason');
    }

    await adminPage.locator('.swal2-confirm').click();

    // Wait for success
    await adminPage.waitForFunction(
      () => !!document.querySelector('.swal2-icon-success') || !document.querySelector('.swal2-popup'),
      { timeout: 15000 },
    );
    await dismissSwal(adminPage);

    // DB verify: loan is DELETED (rejection deletes the row)
    const count = parseInt(
      dbQuery(`SELECT COUNT(*) FROM prestiti WHERE id=${loanId}`), 10,
    );
    expect(count).toBe(0);

    // DB verify: copie_disponibili unchanged (rejection doesn't affect copies)
    const disponibili = parseInt(
      dbQuery(`SELECT copie_disponibili FROM libri WHERE id=${testBookId}`), 10,
    );
    expect(disponibili).toBeGreaterThanOrEqual(1);
  });

  // ═══════════════════════════════════════════════════════════════════════
  // GROUP 6 — Reservation When No Copies Available
  // ═══════════════════════════════════════════════════════════════════════

  test('6.1: Button shows "Prenota" when copie_disponibili=0', async () => {
    // Make book appear as unavailable
    dbQuery(`UPDATE copie SET stato='prestato' WHERE libro_id=${testBookId}`);
    dbQuery(`UPDATE libri SET copie_disponibili=0 WHERE id=${testBookId}`);

    await userPage.goto(`${BASE}/libro/${testBookId}`);
    await userPage.waitForLoadState('networkidle');

    const btn = userPage.locator('#btn-request-loan');
    await expect(btn).toBeVisible({ timeout: 5000 });
    const text = (await btn.textContent()) || '';
    expect(text).toMatch(/Prenota/);
  });

  test('6.2: User makes reservation request (creates prestiti row)', async () => {
    // The unified flow creates a prestiti row via POST /api/libro/{id}/reservation.
    // Even though copie have stato='prestato', they are still "lendable" (not perso/danneggiato),
    // and with no overlapping prestiti rows, the availability API shows dates as free.
    const future = new Date();
    future.setDate(future.getDate() + 14);
    const futureISO = `${future.getFullYear()}-${String(future.getMonth() + 1).padStart(2, '0')}-${String(future.getDate()).padStart(2, '0')}`;

    const ok = await requestLoanViaSwal(userPage, futureISO);
    expect(ok).toBe(true);

    // DB verify: new prestiti row with stato=pendente
    const newId = parseInt(
      dbQuery(`SELECT id FROM prestiti WHERE utente_id=${testUserId} AND libro_id=${testBookId} AND stato='pendente' ORDER BY id DESC LIMIT 1`),
      10,
    );
    expect(newId).toBeGreaterThan(0);
    loanId = newId;
  });

  test('6.3: Duplicate reservation prevention', async () => {
    const future = new Date();
    future.setDate(future.getDate() + 14);
    const futureISO = `${future.getFullYear()}-${String(future.getMonth() + 1).padStart(2, '0')}-${String(future.getDate()).padStart(2, '0')}`;

    const ok = await requestLoanViaSwal(userPage, futureISO);
    expect(ok).toBe(false); // duplicate

    // DB verify: still only 1 pending
    const count = parseInt(
      dbQuery(`SELECT COUNT(*) FROM prestiti WHERE utente_id=${testUserId} AND libro_id=${testBookId} AND stato='pendente'`),
      10,
    );
    expect(count).toBe(1);
  });

  test('6.4: User cancels a reservation (prenotazioni table)', async () => {
    // Create a prenotazioni row directly to test the cancel flow
    const start = new Date(); start.setDate(start.getDate() + 30);
    const end   = new Date(); end.setDate(end.getDate() + 60);
    const startISO = `${start.getFullYear()}-${String(start.getMonth() + 1).padStart(2, '0')}-${String(start.getDate()).padStart(2, '0')}`;
    const endISO   = `${end.getFullYear()}-${String(end.getMonth() + 1).padStart(2, '0')}-${String(end.getDate()).padStart(2, '0')}`;

    dbQuery(
      `INSERT INTO prenotazioni (libro_id, utente_id, queue_position, stato, data_prenotazione, data_scadenza_prenotazione, data_inizio_richiesta, data_fine_richiesta, created_at)
       VALUES (${testBookId}, ${testUserId}, 1, 'attiva', '${startISO} 00:00:00', '${endISO} 23:59:59', '${startISO}', '${endISO}', NOW())`,
    );
    const reservationId = parseInt(
      dbQuery(`SELECT id FROM prenotazioni WHERE utente_id=${testUserId} AND libro_id=${testBookId} AND stato='attiva' ORDER BY id DESC LIMIT 1`),
      10,
    );
    expect(reservationId).toBeGreaterThan(0);

    // Navigate to user reservations
    await userPage.goto(`${BASE}/prenotazioni`);
    await userPage.waitForLoadState('networkidle');

    await expect(userPage.locator('text=Prenotazioni attive')).toBeVisible({ timeout: 5000 });

    // Handle native confirm() dialog
    userPage.once('dialog', (dialog) => dialog.accept());

    // Click the cancel button (form submit)
    const cancelBtn = userPage.locator('.btn-cancel').first();
    await expect(cancelBtn).toBeVisible({ timeout: 5000 });
    await cancelBtn.click();

    // Form submits → redirect
    await userPage.waitForURL(/prenotazioni/, { timeout: 15000 });

    // DB verify: reservation cancelled
    const newStato = dbQuery(`SELECT stato FROM prenotazioni WHERE id=${reservationId}`);
    expect(newStato).toBe('annullata');
  });

  test('6.5: Restore copies', async () => {
    // Clean up: delete pending loan from 6.2
    dbQuery(`DELETE FROM prestiti WHERE utente_id=${testUserId} AND stato='pendente'`);

    // Restore copy status
    dbQuery(`UPDATE copie SET stato='disponibile' WHERE libro_id=${testBookId}`);
    dbQuery(
      `UPDATE libri SET copie_disponibili = (
         SELECT COUNT(*) FROM copie WHERE libro_id=${testBookId} AND stato='disponibile'
       ) WHERE id=${testBookId}`,
    );

    const disponibili = parseInt(
      dbQuery(`SELECT copie_disponibili FROM libri WHERE id=${testBookId}`), 10,
    );
    expect(disponibili).toBeGreaterThanOrEqual(1);
  });

  // ═══════════════════════════════════════════════════════════════════════
  // GROUP 7 — Display Verification
  // ═══════════════════════════════════════════════════════════════════════

  test('7.1: Book detail shows correct availability count', async () => {
    const dbCount = parseInt(
      dbQuery(`SELECT copie_disponibili FROM libri WHERE id=${testBookId}`), 10,
    );

    await userPage.goto(`${BASE}/libro/${testBookId}`);
    await userPage.waitForLoadState('networkidle');

    // The button text should reflect availability
    const btn = userPage.locator('#btn-request-loan');
    await expect(btn).toBeVisible({ timeout: 5000 });
    const text = (await btn.textContent()) || '';

    if (dbCount > 0) {
      expect(text).toMatch(/Richiedi Prestito/);
    } else {
      expect(text).toMatch(/Prenota/);
    }
  });

  test('7.2: Admin pending loans page loads correctly', async () => {
    const jsErrors = [];
    adminPage.on('pageerror', (error) => jsErrors.push(error.message));

    await adminPage.goto(`${BASE}/admin/loans/pending`);
    await adminPage.waitForLoadState('networkidle');

    await expect(adminPage.locator('body')).not.toBeEmpty();

    const criticalErrors = jsErrors.filter(
      (e) => !e.includes('ResizeObserver') && !e.includes('Non-Error'),
    );
    expect(criticalErrors).toHaveLength(0);
  });

  test('7.3: User reservations page loads without errors', async () => {
    const jsErrors = [];
    userPage.on('pageerror', (error) => jsErrors.push(error.message));

    await userPage.goto(`${BASE}/prenotazioni`);
    await userPage.waitForLoadState('networkidle');

    await expect(userPage.locator('body')).not.toBeEmpty();

    // Page should have the expected section headers (translated Italian)
    await expect(userPage.getByRole('heading', { name: 'Prestiti attivi' })).toBeVisible();
    await expect(userPage.getByRole('heading', { name: 'Prenotazioni attive' })).toBeVisible();
    await expect(userPage.getByRole('heading', { name: 'Prestiti passati' })).toBeVisible();

    const criticalErrors = jsErrors.filter(
      (e) => !e.includes('ResizeObserver') && !e.includes('Non-Error'),
    );
    expect(criticalErrors).toHaveLength(0);
  });
});
